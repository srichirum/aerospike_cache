<?php

namespace Drupal\aerospike_cache;

use Aerospike\BatchPolicy;
use Aerospike\BatchRead;
use Aerospike\BatchReadPolicy;
use Aerospike\BatchWrite;
use Aerospike\BatchWritePolicy;
use Aerospike\Bin;
use Aerospike\Operation;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;

/**
 * Aerospike-backed implementation of CacheTagsChecksumInterface.
 *
 * Each cache tag is stored as a record in the "cachetags" Aerospike set. The
 * "count" bin holds an integer counter that is atomically incremented each time
 * the tag is invalidated. The checksum for a set of tags is the sum of their
 * counters, matching the semantics of DatabaseCacheTagsChecksum — it is
 * order-independent and increases monotonically.
 *
 * Reads use a single batch operation regardless of how many tags are checked,
 * reducing round-trips to one socket hop per checksum lookup. Writes
 * (invalidations) are also batched.
 *
 * All Aerospike I/O fails soft: read failures return version 0 (treating items
 * as not-yet-invalidated rather than throwing), and write failures are logged
 * and swallowed. This service is instantiated during container compilation and
 * must never throw.
 */
class AerospikeCacheTagsChecksum implements CacheTagsChecksumInterface, CacheTagsInvalidatorInterface {

  protected const SET = 'cachetags';

  /**
   * Whether an I/O error has already been logged this request.
   *
   * @var bool
   */
  private bool $errorLogged = FALSE;

  /**
   * Constructs the checksum provider.
   *
   * @param \Drupal\aerospike_cache\AerospikeConnection $connection
   *   The Aerospike connection.
   * @param \Closure $loggerFactory
   *   A service closure returning the logger.factory. Injected lazily (not as
   *   a direct reference) to avoid a circular dependency when a logger in the
   *   consuming site depends on a cache backend:
   *   checksum -> logger.factory -> [site logger] -> cache.default -> checksum.
   */
  public function __construct(
    protected AerospikeConnection $connection,
    protected \Closure $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getCurrentChecksum(array $tags) {
    return (string) array_sum($this->getTagVersions($tags));
  }

  /**
   * {@inheritdoc}
   */
  public function isValid($checksum, array $tags) {
    return (string) $checksum === $this->getCurrentChecksum($tags);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags): void {
    if (empty($tags)) {
      return;
    }
    try {
      $client = $this->connection->getClient();
      $bwp    = new BatchWritePolicy();
      $ops    = [Operation::add(new Bin('count', 1))];
      $batch  = [];
      foreach (array_unique($tags) as $tag) {
        $batch[] = new BatchWrite($bwp, $this->connection->makeKey(self::SET, $tag), $ops);
      }
      $client->batch(new BatchPolicy(), $batch);
    }
    catch (\Throwable $e) {
      $this->logError('invalidateTags', $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function reset() {
    // No-op: counters live in Aerospike and must persist across requests.
  }

  /**
   * Returns the current invalidation counter for each tag.
   *
   * A single batch read fetches all counters in one socket hop. Results are
   * returned in input order; tags with no record yet default to 0.
   */
  protected function getTagVersions(array $tags): array {
    if (empty($tags)) {
      return [];
    }
    $versions = [];
    try {
      $client  = $this->connection->getClient();
      $brp     = new BatchReadPolicy();
      $ordered = array_values(array_unique($tags));
      $reads   = [];
      foreach ($ordered as $tag) {
        $reads[] = new BatchRead($brp, $this->connection->makeKey(self::SET, $tag), ['count']);
      }
      $results = $client->batch(new BatchPolicy(), $reads);
      foreach ($ordered as $i => $tag) {
        $rec            = $results[$i]->getRecord();
        $versions[$tag] = ($rec !== NULL && isset($rec->bins['count']))
          ? (int) $rec->bins['count']
          : 0;
      }
    }
    catch (\Throwable $e) {
      $this->logError('getTagVersions', $e);
      // Fail soft: treat unknown tags as never-invalidated.
      foreach ($tags as $tag) {
        $versions[$tag] = $versions[$tag] ?? 0;
      }
    }
    return $versions;
  }

  /**
   * Logs an Aerospike I/O failure once per request to avoid log flooding.
   */
  protected function logError(string $op, \Throwable $e): void {
    if (!$this->errorLogged) {
      $this->errorLogged = TRUE;
      // The factory is resolved here, at runtime, by invoking the service
      // closure — never at container compile time. This is what breaks the
      // circular dependency described in the constructor.
      ($this->loggerFactory)()->get('aerospike_cache')->error(
        'Aerospike cache tags @op failed: @msg',
        ['@op' => $op, '@msg' => $e->getMessage()],
      );
    }
  }

}
