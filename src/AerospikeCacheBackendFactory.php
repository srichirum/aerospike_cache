<?php

namespace Drupal\aerospike_cache;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;

/**
 * Factory that creates an AerospikeCacheBackend instance per cache bin.
 *
 * Registered as the cache.backend.aerospike service. Each call to get() returns
 * a backend scoped to the given bin name, sharing the same connection,
 * checksum provider, and logger.
 */
class AerospikeCacheBackendFactory implements CacheFactoryInterface {

  public function __construct(
    protected AerospikeConnection $connection,
    protected CacheTagsChecksumInterface $checksum,
    protected TimeInterface $time,
    protected \Closure $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get($bin): AerospikeCacheBackend {
    return new AerospikeCacheBackend($bin, $this->connection, $this->checksum, $this->time, $this->loggerFactory);
  }

}
