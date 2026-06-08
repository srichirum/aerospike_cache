<?php

namespace Drupal\aerospike_cache;

use Aerospike\BatchDelete;
use Aerospike\BatchDeletePolicy;
use Aerospike\BatchPolicy;
use Aerospike\BatchRead;
use Aerospike\BatchReadPolicy;
use Aerospike\Bin;
use Aerospike\Expiration;
use Aerospike\InfoPolicy;
use Aerospike\ReadPolicy;
use Aerospike\WritePolicy;
use Drupal\Component\Assertion\Inspector;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Psr\Log\LoggerInterface;

/**
 * Aerospike cache backend implementing CacheBackendInterface.
 *
 * Each Drupal cache bin maps to an Aerospike set within the configured
 * namespace. Records store serialized data alongside metadata — expiry
 * timestamp, cache tags, and a checksum — so that tag-based invalidation works
 * correctly across requests and processes.
 *
 * All Aerospike I/O fails soft: read failures return FALSE (treated as a cache
 * miss), and write failures are logged and swallowed. This ensures an
 * unavailable Aerospike cluster degrades gracefully rather than taking the site
 * down. For production deployments that need transparent fallback to the
 * database, use AerospikeFailoverCacheBackend instead.
 *
 * Dependencies are constructor-injected so the backend is safe to instantiate
 * during container compilation, when the global \Drupal container is not yet
 * available.
 */
class AerospikeCacheBackend implements CacheBackendInterface {

  /**
   * Whether an I/O error has already been logged this request.
   *
   * @var bool
   */
  private bool $errorLogged = FALSE;

  /**
   * Constructs an AerospikeCacheBackend.
   *
   * @param string $bin
   *   The cache bin name (maps to an Aerospike set).
   * @param \Drupal\aerospike_cache\AerospikeConnection $connection
   *   The Aerospike connection.
   * @param \Drupal\Core\Cache\CacheTagsChecksumInterface $checksum
   *   The cache tags checksum provider.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel for I/O failures.
   */
  public function __construct(
    protected string $bin,
    protected AerospikeConnection $connection,
    protected CacheTagsChecksumInterface $checksum,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get($cid, $allow_invalid = FALSE): object|false {
    try {
      $client = $this->connection->getClient();
      $record = $client->get(new ReadPolicy(), $this->connection->makeKey($this->bin, $cid));
    }
    catch (\Throwable $e) {
      $this->logError('get', $e);
      return FALSE;
    }

    if ($record === NULL) {
      return FALSE;
    }
    return $this->prepareItem($cid, $record->bins, $allow_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE): array {
    if (empty($cids)) {
      return [];
    }
    $results = [];
    try {
      $client  = $this->connection->getClient();
      $brp     = new BatchReadPolicy();
      $ordered = array_values($cids);
      $reads   = array_map(
        fn($cid) => new BatchRead($brp, $this->connection->makeKey($this->bin, $cid), []),
        $ordered,
      );
      $batch   = $client->batch(new BatchPolicy(), $reads);
      foreach ($ordered as $i => $cid) {
        $rec = $batch[$i]->getRecord();
        if ($rec === NULL || empty($rec->bins)) {
          continue;
        }
        $item = $this->prepareItem($cid, $rec->bins, $allow_invalid);
        if ($item !== FALSE) {
          $results[$cid] = $item;
        }
      }
    }
    catch (\Throwable $e) {
      $this->logError('getMultiple', $e);
    }
    $cids = array_values(array_diff($cids, array_keys($results)));
    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function set($cid, $data, $expire = Cache::PERMANENT, array $tags = []): void {
    assert(Inspector::assertAllStrings($tags), 'Cache tags must be strings.');
    $tags = array_unique($tags);
    sort($tags);

    $wp = new WritePolicy();

    // Aerospike expiration is an \Aerospike\Expiration value object.
    // NamespaceDefault() = use the namespace's default-ttl (0 / never for our
    // "drupal" namespace); Seconds(n) = expire n seconds from now.
    if ($expire !== Cache::PERMANENT) {
      $ttl = max(1, $expire - $this->time->getRequestTime());
      $wp->expiration = Expiration::Seconds($ttl);
    }
    else {
      $wp->expiration = Expiration::NamespaceDefault();
    }

    $bins = [
      new Bin('data', serialize($data)),
      new Bin('created', $this->time->getRequestTime()),
      new Bin('expire', $expire),
      new Bin('tags', implode(' ', $tags)),
      new Bin('checksum', $this->checksum->getCurrentChecksum($tags)),
      new Bin('valid', 1),
    ];

    try {
      $this->connection->getClient()->put($wp, $this->connection->makeKey($this->bin, $cid), $bins);
    }
    catch (\Throwable $e) {
      $this->logError('set', $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $items): void {
    foreach ($items as $cid => $item) {
      $this->set(
        $cid,
        $item['data'],
        $item['expire'] ?? Cache::PERMANENT,
        $item['tags'] ?? [],
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete($cid): void {
    try {
      $this->connection->getClient()->delete(new WritePolicy(), $this->connection->makeKey($this->bin, $cid));
    }
    catch (\Throwable $e) {
      $this->logError('delete', $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $cids): void {
    if (empty($cids)) {
      return;
    }
    try {
      $bdp   = new BatchDeletePolicy();
      $batch = array_map(
        fn($cid) => new BatchDelete($bdp, $this->connection->makeKey($this->bin, $cid)),
        array_unique($cids),
      );
      $this->connection->getClient()->batch(new BatchPolicy(), $batch);
    }
    catch (\Throwable $e) {
      $this->logError('deleteMultiple', $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll(): void {
    try {
      $this->connection->getClient()->truncate(
        new InfoPolicy(),
        $this->connection->getNamespace(),
        $this->bin,
      );
    }
    catch (\Throwable $e) {
      $this->logError('deleteAll', $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate($cid): void {
    $this->invalidateMultiple([$cid]);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateMultiple(array $cids): void {
    foreach ($cids as $cid) {
      $item = $this->get($cid, TRUE);
      if ($item === FALSE) {
        continue;
      }
      // Re-store the full item with an expire in the past so it is only
      // returned to callers that explicitly allow invalid items. Writing only
      // the 'valid' bin would orphan the other bins on a partial record.
      $this->set($cid, $item->data, $this->time->getRequestTime() - 1, $item->tags);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateAll(): void {
    // No partial-scan API is available, so truncate the bin. Callers relying on
    // stale-while-revalidate lose the stale copy here; acceptable for the bins
    // this backend serves, and avoids an expensive full scan.
    $this->deleteAll();
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags): void {
    // Cache-tag invalidation happens through the checksum provider
    // (AerospikeCacheTagsChecksum); stale items are detected on the next get()
    // via checksum mismatch. Nothing to do per-bin.
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection(): void {
    // Aerospike TTL eviction is handled server-side.
  }

  /**
   * {@inheritdoc}
   */
  public function removeBin(): void {
    $this->deleteAll();
  }

  /**
   * Builds a cache item object from a raw Aerospike record's bins.
   *
   * @param string $cid
   *   The cache ID.
   * @param array $bins
   *   The Aerospike record bins.
   * @param bool $allow_invalid
   *   Whether to return the item even if it is invalid.
   *
   * @return object|false
   *   The cache item, or FALSE if missing or invalid (and not allowed).
   */
  protected function prepareItem(string $cid, array $bins, bool $allow_invalid): object|false {
    if (!isset($bins['data'])) {
      return FALSE;
    }

    $item = new \stdClass();
    $item->cid = $cid;
    // Cache data legitimately contains objects (render arrays, config, entity
    // values), so classes must be allowed — matching core's DatabaseBackend,
    // which unserialize()s cache data the same way. The data is written only by
    // Drupal itself into a namespace reachable solely via the in-pod ACM
    // socket; it is trusted to the same degree as the database cache.
    // allowed_classes is stated explicitly to document the decision.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $item->data = unserialize($bins['data'], ['allowed_classes' => TRUE]);
    $item->created = (int) ($bins['created'] ?? 0);
    $item->expire = (int) ($bins['expire'] ?? Cache::PERMANENT);
    $item->tags = $bins['tags'] ? explode(' ', $bins['tags']) : [];
    $item->checksum = $bins['checksum'] ?? '';
    $item->valid = (bool) ($bins['valid'] ?? TRUE);

    // Expired by wall clock (matches core semantics). Aerospike's server-side
    // TTL also evicts the record shortly after; no explicit delete needed here.
    if ($item->expire !== Cache::PERMANENT && $item->expire < $this->time->getRequestTime()) {
      $item->valid = FALSE;
    }

    // Tag-based invalidation: stale if the stored checksum no longer matches.
    if (!$this->checksum->isValid($item->checksum, $item->tags)) {
      $item->valid = FALSE;
    }

    if (!$item->valid && !$allow_invalid) {
      return FALSE;
    }

    return $item;
  }

  /**
   * Logs an Aerospike I/O failure once per request to avoid log flooding.
   *
   * @param string $op
   *   The operation that failed.
   * @param \Throwable $e
   *   The thrown error.
   */
  protected function logError(string $op, \Throwable $e): void {
    if (!$this->errorLogged) {
      $this->errorLogged = TRUE;
      $this->logger->error('Aerospike cache @op failed on bin "@bin": @msg', [
        '@op' => $op,
        '@bin' => $this->bin,
        '@msg' => $e->getMessage(),
      ]);
    }
  }

}
