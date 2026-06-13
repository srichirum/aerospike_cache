<?php

namespace Drupal\aerospike_cache;

use Aerospike\Key;
use Aerospike\Client;
use Drupal\Core\Site\Settings;

/**
 * Manages the connection to the Aerospike Connection Manager (ACM).
 *
 * The aerospike/php-client Rust extension communicates with a local ACM daemon
 * via a gRPC Unix socket. The ACM handles TCP connection pooling to the remote
 * Aerospike cluster, keeping PHP processes stateless and reducing cluster load.
 *
 * Configuration is read from Drupal settings:
 * - aerospike_cache_socket: Path to the ACM Unix socket
 *   (default: /tmp/asld_grpc.sock, or env AEROSPIKE_SOCKET)
 * - aerospike_cache_namespace: Aerospike namespace for cache data
 *   (default: "drupal", or env AEROSPIKE_NAMESPACE)
 *
 * The client is opened lazily on first use. Call isAvailable() to probe the
 * connection without risking an exception in calling code.
 */
class AerospikeConnection {

  /**
   * The lazily-opened Aerospike client, or NULL before first use.
   */
  protected ?Client $client = NULL;

  /**
   * Cached result of the last availability probe. NULL means not yet probed.
   */
  protected ?bool $available = NULL;

  /**
   * The ACM Unix socket path.
   */
  protected string $socket;

  /**
   * The Aerospike namespace cache data is stored in.
   */
  protected string $namespace;

  /**
   * Maximum stored payload size in bytes before an item is skipped.
   */
  protected int $maxRecordSize;

  /**
   * Serialized-size threshold (bytes) above which payloads are compressed.
   */
  protected int $compressThreshold;

  /**
   * Optional prefix prepended to every key, for multi-site key isolation.
   */
  protected string $prefix;

  /**
   * Constructs an AerospikeConnection.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings.
   */
  public function __construct(Settings $settings) {
    $this->socket    = $settings->get('aerospike_cache_socket', getenv('AEROSPIKE_SOCKET') ?: '/tmp/asld_grpc.sock');
    $this->namespace = $settings->get('aerospike_cache_namespace', getenv('AEROSPIKE_NAMESPACE') ?: 'drupal');
    // Aerospike caps each record at max-record-size (default 1 MiB). Keep the
    // stored payload safely under that to leave room for the other bins and
    // record overhead. Tunable for clusters configured with a larger limit.
    $this->maxRecordSize     = (int) $settings->get('aerospike_cache_max_record_size', 1000000);
    $this->compressThreshold = (int) $settings->get('aerospike_cache_compress_threshold', 4096);
    // Optional key prefix. Lets several Drupal sites share one namespace
    // without colliding — each site sets a distinct prefix. Empty by default.
    $this->prefix = (string) $settings->get('aerospike_cache_prefix', getenv('AEROSPIKE_PREFIX') ?: '');
  }

  /**
   * Returns the Aerospike client, connecting lazily on first use.
   */
  public function getClient(): Client {
    if ($this->client === NULL) {
      $this->client = Client::connect($this->socket);
    }
    return $this->client;
  }

  /**
   * Returns TRUE if the ACM socket is reachable, FALSE otherwise.
   *
   * The result is cached on the instance so the probe runs at most once per
   * request per connection object, avoiding repeated timeout overhead after a
   * detected failure.
   */
  public function isAvailable(): bool {
    if ($this->available === NULL) {
      try {
        $this->getClient();
        $this->available = TRUE;
      }
      catch (\Throwable) {
        $this->available = FALSE;
      }
    }
    return $this->available;
  }

  /**
   * Returns the configured ACM socket path.
   */
  public function getSocket(): string {
    return $this->socket;
  }

  /**
   * Returns the configured Aerospike namespace.
   */
  public function getNamespace(): string {
    return $this->namespace;
  }

  /**
   * Returns the maximum stored payload size in bytes.
   */
  public function getMaxRecordSize(): int {
    return $this->maxRecordSize;
  }

  /**
   * Returns the serialized-size threshold above which payloads are compressed.
   */
  public function getCompressThreshold(): int {
    return $this->compressThreshold;
  }

  /**
   * Returns the configured key prefix (empty string if none).
   */
  public function getPrefix(): string {
    return $this->prefix;
  }

  /**
   * Builds an Aerospike key for the given set and primary key.
   *
   * The configured prefix (if any) is prepended to the primary key so that
   * sites sharing a namespace do not collide.
   */
  public function makeKey(string $set, string $pk): Key {
    return new Key($this->namespace, $set, $this->prefix . $pk);
  }

}
