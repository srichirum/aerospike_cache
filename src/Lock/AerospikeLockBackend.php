<?php

namespace Drupal\aerospike_cache\Lock;

use Aerospike\Bin;
use Aerospike\Expiration;
use Aerospike\ReadPolicy;
use Aerospike\RecordExistsAction;
use Aerospike\WritePolicy;
use Drupal\aerospike_cache\AerospikeConnection;
use Drupal\Core\Lock\LockBackendAbstract;

/**
 * Aerospike-backed lock backend.
 *
 * Locks are stored as records in the "semaphore" Aerospike set, keyed by lock
 * name. Acquisition relies on Aerospike's atomic create-only write
 * (RecordExistsAction::createOnly): the first writer wins, and concurrent
 * writers are rejected while the record exists. Each lock carries a TTL equal
 * to its timeout, so a process that dies without releasing its lock cannot
 * deadlock the system — Aerospike evicts the record automatically.
 *
 * The owning request is recorded in the "owner" bin (the per-request lock ID
 * from LockBackendAbstract) so release() only deletes locks this process holds,
 * never one another process acquired after ours expired.
 *
 * All Aerospike I/O fails soft: on error, acquire() reports failure rather than
 * throwing, matching the advisory nature of Drupal locks.
 */
class AerospikeLockBackend extends LockBackendAbstract {

  /**
   * The Aerospike set that stores lock records.
   */
  protected const SET = 'semaphore';

  /**
   * Constructs an AerospikeLockBackend.
   *
   * @param \Drupal\aerospike_cache\AerospikeConnection $connection
   *   The Aerospike connection.
   */
  public function __construct(protected AerospikeConnection $connection) {
    // Clean up any still-held locks at the end of the request. A shutdown
    // function is used rather than __destruct() to avoid garbage-collection
    // ordering problems, matching core's database lock backend.
    drupal_register_shutdown_function([$this, 'releaseAll']);
  }

  /**
   * {@inheritdoc}
   */
  public function acquire($name, $timeout = 30.0): bool {
    // Aerospike TTL is whole seconds with a minimum of one.
    $ttl = (int) max(ceil($timeout), 1);
    $lock_id = $this->getLockId();
    $key = $this->connection->makeKey(self::SET, $name);

    try {
      $client = $this->connection->getClient();

      // Already holding this lock locally: extend it, but only if the stored
      // record is still ours (it may have expired and been taken by another).
      if (isset($this->locks[$name])) {
        $record = $client->get(new ReadPolicy(), $key);
        if ($record !== NULL && ($record->bins['owner'] ?? NULL) === $lock_id) {
          $client->put($this->updatePolicy($ttl), $key, [new Bin('owner', $lock_id)]);
          return TRUE;
        }
        // The lock was lost; fall through and try to re-acquire it cleanly.
        unset($this->locks[$name]);
      }

      // Atomic create-only write: succeeds only if no record exists.
      $client->put($this->createOnlyPolicy($ttl), $key, [new Bin('owner', $lock_id)]);
      $this->locks[$name] = TRUE;
      return TRUE;
    }
    catch (\Throwable $e) {
      // create-only rejection (lock held) or any I/O error: not acquired.
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function lockMayBeAvailable($name): bool {
    $key = $this->connection->makeKey(self::SET, $name);
    try {
      // Expired locks are evicted server-side by their TTL, so a missing
      // record means the lock is free.
      return !$this->connection->getClient()->exists(new ReadPolicy(), $key);
    }
    catch (\Throwable $e) {
      // On error, assume unavailable so callers back off rather than busy-loop.
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function release($name): void {
    unset($this->locks[$name]);
    $key = $this->connection->makeKey(self::SET, $name);
    try {
      $client = $this->connection->getClient();
      // Only delete the record if we still own it, so we never release a lock
      // that expired and was re-acquired by another request.
      $record = $client->get(new ReadPolicy(), $key);
      if ($record !== NULL && ($record->bins['owner'] ?? NULL) === $this->getLockId()) {
        $client->delete(new WritePolicy(), $key);
      }
    }
    catch (\Throwable $e) {
      // Fail soft: the TTL guarantees the lock is eventually freed anyway.
    }
  }

  /**
   * {@inheritdoc}
   */
  public function releaseAll($lock_id = NULL): void {
    $owner = $lock_id ?: $this->getLockId();
    $names = array_keys($this->locks);
    $this->locks = [];

    try {
      $client = $this->connection->getClient();
      foreach ($names as $name) {
        $key = $this->connection->makeKey(self::SET, $name);
        $record = $client->get(new ReadPolicy(), $key);
        if ($record !== NULL && ($record->bins['owner'] ?? NULL) === $owner) {
          $client->delete(new WritePolicy(), $key);
        }
      }
    }
    catch (\Throwable $e) {
      // Fail soft: TTLs eventually free anything left behind.
    }
  }

  /**
   * Builds a create-only write policy with the given TTL.
   */
  protected function createOnlyPolicy(int $ttl): WritePolicy {
    $wp = new WritePolicy();
    $wp->setRecordExistsAction(RecordExistsAction::createOnly());
    $wp->setExpiration(Expiration::Seconds($ttl));
    return $wp;
  }

  /**
   * Builds an update write policy with the given TTL.
   */
  protected function updatePolicy(int $ttl): WritePolicy {
    $wp = new WritePolicy();
    $wp->setExpiration(Expiration::Seconds($ttl));
    return $wp;
  }

}
