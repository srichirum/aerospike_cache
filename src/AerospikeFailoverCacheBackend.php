<?php

namespace Drupal\aerospike_cache;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Cache backend decorator that fails over from Aerospike to a fallback backend.
 *
 * On the first cache operation each request, isAvailable() is called once on
 * the connection. If the ACM socket is reachable, all operations go to the
 * Aerospike backend. If not, all operations for that request route to the
 * fallback backend (typically the database) and a single warning is logged.
 *
 * Recovery is automatic: the probe runs fresh on every new request, so the site
 * switches back to Aerospike as soon as the ACM daemon becomes reachable again
 * without any deployment or cache flush.
 *
 * Writes always go to whichever backend is active, keeping the fallback warm
 * and ensuring cache-tag invalidations are not silently dropped.
 */
class AerospikeFailoverCacheBackend implements CacheBackendInterface {

  /**
   * Whether the failover warning has already been logged this request.
   *
   * @var bool
   */
  private bool $failoverWarned = FALSE;

  public function __construct(
    protected CacheBackendInterface $aerospike,
    protected CacheBackendInterface $fallback,
    protected AerospikeConnection $connection,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Returns the active backend for this request.
   */
  protected function backend(): CacheBackendInterface {
    if (!$this->connection->isAvailable()) {
      if (!$this->failoverWarned) {
        $this->failoverWarned = TRUE;
        // Channel resolved lazily to avoid circular dependency:
        // failover backend -> logger channel -> cache -> failover backend.
        $this->loggerFactory->get('aerospike_cache')->warning(
          'Aerospike unreachable — failing over to database cache.'
        );
      }
      return $this->fallback;
    }
    return $this->aerospike;
  }

  /**
   * {@inheritdoc}
   */
  public function get($cid, $allow_invalid = FALSE): object|false {
    return $this->backend()->get($cid, $allow_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE): array {
    return $this->backend()->getMultiple($cids, $allow_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function set($cid, $data, $expire = CacheBackendInterface::CACHE_PERMANENT, array $tags = []):void {
    $this->backend()->set($cid, $data, $expire, $tags);
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $items): void {
    $this->backend()->setMultiple($items);
  }

  /**
   * {@inheritdoc}
   */
  public function delete($cid): void {
    $this->backend()->delete($cid);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $cids): void {
    $this->backend()->deleteMultiple($cids);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll(): void {
    $this->backend()->deleteAll();
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate($cid): void {
    $this->backend()->invalidate($cid);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateMultiple(array $cids): void {
    $this->backend()->invalidateMultiple($cids);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateAll(): void {
    $this->backend()->invalidateAll();
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags): void {
    $this->backend()->invalidateTags($tags);
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection(): void {
    $this->backend()->garbageCollection();
  }

  /**
   * {@inheritdoc}
   */
  public function removeBin(): void {
    $this->backend()->removeBin();
  }

}
