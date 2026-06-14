<?php

namespace Drupal\aerospike_cache;

use Drupal\Core\Cache\CacheFactoryInterface;

/**
 * Factory that creates an AerospikeFailoverCacheBackend instance per cache bin.
 *
 * Registered as the cache.backend.aerospike_failover service. Each bin gets a
 * backend pairing an Aerospike primary with a configurable fallback (database
 * by default). Use this factory in settings.php for production deployments
 * where availability matters more than strict Aerospike-only behaviour.
 *
 * @see \Drupal\aerospike_cache\AerospikeFailoverCacheBackend
 */
class AerospikeFailoverCacheBackendFactory implements CacheFactoryInterface {

  public function __construct(
    protected CacheFactoryInterface $aerospikeFactory,
    protected CacheFactoryInterface $databaseFactory,
    protected AerospikeConnection $connection,
    protected \Closure $loggerFactory,
    protected ?AerospikeCacheStats $stats = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get($bin): AerospikeFailoverCacheBackend {
    return new AerospikeFailoverCacheBackend(
      $this->aerospikeFactory->get($bin),
      $this->databaseFactory->get($bin),
      $this->connection,
      $this->loggerFactory,
      $this->stats,
    );
  }

}
