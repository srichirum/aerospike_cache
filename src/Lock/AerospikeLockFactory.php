<?php

namespace Drupal\aerospike_cache\Lock;

use Drupal\aerospike_cache\AerospikeConnection;

/**
 * Creates the Aerospike lock backend.
 *
 * Registered so that core's `lock` and `lock.persistent` services can be
 * pointed at Aerospike in services.yml. See the module README for the override.
 */
class AerospikeLockFactory {

  /**
   * Constructs an AerospikeLockFactory.
   *
   * @param \Drupal\aerospike_cache\AerospikeConnection $connection
   *   The Aerospike connection.
   */
  public function __construct(protected AerospikeConnection $connection) {}

  /**
   * Returns an Aerospike lock backend instance.
   *
   * @return \Drupal\aerospike_cache\Lock\AerospikeLockBackend
   *   The lock backend.
   */
  public function get(): AerospikeLockBackend {
    return new AerospikeLockBackend($this->connection);
  }

}
