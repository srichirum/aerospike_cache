<?php

namespace Drupal\aerospike_cache;

use Aerospike\Key;
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
   * Maximum number of chunks a single item may be split into.
   *
   * Caps the fan-out for pathologically large items; anything that would need
   * more chunks than this is skipped rather than stored.
   */
  protected const MAX_CHUNKS = 32;

  /**
   * Whether an I/O error has already been logged this request.
   *
   * @var bool
   */
  private bool $errorLogged = FALSE;

  /**
   * Whether an oversized-item skip has already been logged this request.
   *
   * @var bool
   */
  private bool $oversizedLogged = FALSE;

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
   * @param \Closure $loggerFactory
   *   A service closure returning the logger.factory. Injected lazily (not as
   *   a direct reference) so the logger graph is never traversed at container
   *   compile time — otherwise a site logger that depends on a cache backend
   *   would create a circular dependency.
   */
  public function __construct(
    protected string $bin,
    protected AerospikeConnection $connection,
    protected CacheTagsChecksumInterface $checksum,
    protected TimeInterface $time,
    protected \Closure $loggerFactory,
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

    $wp = $this->writePolicyFor($expire);

    // Encode the payload. Large items are compressed; the result is base64'd
    // because the Aerospike client only accepts UTF-8 strings in a bin, not
    // raw binary (gzcompress output). A 'zip' flag records the encoding so
    // prepareItem() can reverse it.
    $serialized = serialize($data);
    $compress = function_exists('gzcompress')
      && strlen($serialized) > $this->connection->getCompressThreshold();
    $payload = $compress ? base64_encode(gzcompress($serialized, 6)) : $serialized;

    // Metadata bins are common to single and multipart records.
    $meta = [
      new Bin('zip', $compress ? 1 : 0),
      new Bin('created', $this->time->getRequestTime()),
      new Bin('expire', $expire),
      new Bin('tags', implode(' ', $tags)),
      new Bin('checksum', $this->checksum->getCurrentChecksum($tags)),
      new Bin('valid', 1),
    ];

    $max = $this->connection->getMaxRecordSize();
    try {
      $client = $this->connection->getClient();

      if (strlen($payload) <= $max) {
        // Fits in one record. multipart = 0 marks it as a single item.
        $bins = array_merge([new Bin('data', $payload), new Bin('multipart', 0)], $meta);
        $client->put($wp, $this->connection->makeKey($this->bin, $cid), $bins);
        return;
      }

      // Too large for one record: split the payload into chunk records and
      // store a parent that references them. Bounded by MAX_CHUNKS so a
      // pathologically huge item is skipped rather than fanned out unboundedly.
      $chunks = str_split($payload, $max);
      if (count($chunks) > self::MAX_CHUNKS) {
        $this->logOversized($cid, strlen($payload));
        return;
      }

      // Write every chunk first. If any fails, abort without writing the
      // parent — a missing parent reads as a clean cache miss, whereas a
      // parent pointing at missing chunks would be unreconstructable.
      foreach ($chunks as $i => $chunk) {
        $client->put($wp, $this->chunkKey($cid, $i), [new Bin('data', $chunk)]);
      }

      // Parent holds metadata and the chunk count, but no data bin.
      $bins = array_merge([new Bin('multipart', count($chunks))], $meta);
      $client->put($wp, $this->connection->makeKey($this->bin, $cid), $bins);
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
      $client = $this->connection->getClient();
      $key = $this->connection->makeKey($this->bin, $cid);

      // Read the parent header first so multipart items also have their chunk
      // records removed — otherwise permanent (no-TTL) chunks would orphan.
      // Single items (the common case) carry multipart = 0 and skip this.
      $record = $client->get(new ReadPolicy(), $key);
      if ($record !== NULL && !empty($record->bins['multipart'])) {
        for ($i = 0; $i < (int) $record->bins['multipart']; $i++) {
          $client->delete(new WritePolicy(), $this->chunkKey($cid, $i));
        }
      }

      $client->delete(new WritePolicy(), $key);
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
    // A full scan is possible but deliberately avoided: truncate is a single
    // server-side command, whereas scanning to mark each record invalid would
    // read and rewrite every record. Stale-while-revalidate callers lose the
    // stale copy here; acceptable for the bins this backend serves.
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
    // Reassemble multipart items from their chunk records. A missing chunk
    // (e.g. evicted by TTL) makes the item unreconstructable — a clean miss.
    if (!empty($bins['multipart'])) {
      $raw = $this->readChunks($cid, (int) $bins['multipart']);
      if ($raw === NULL) {
        return FALSE;
      }
    }
    elseif (isset($bins['data'])) {
      $raw = $bins['data'];
    }
    else {
      return FALSE;
    }

    $item = new \stdClass();
    $item->cid = $cid;
    // Reverse the set() encoding: base64-decode and inflate when the 'zip'
    // flag is set. Uncompressed records (zip = 0/absent) are read as-is.
    if (!empty($bins['zip'])) {
      $raw = gzuncompress(base64_decode($raw));
    }
    // Cache data legitimately contains objects (render arrays, config, entity
    // values), so classes must be allowed — matching core's DatabaseBackend,
    // which unserialize()s cache data the same way. The data is written only by
    // Drupal itself into a namespace reachable solely via the in-pod ACM
    // socket; it is trusted to the same degree as the database cache.
    // allowed_classes is stated explicitly to document the decision.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $item->data = unserialize($raw, ['allowed_classes' => TRUE]);
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
      // Resolve the factory at runtime by invoking the service closure — never
      // at compile time. This is what breaks the circular dependency.
      ($this->loggerFactory)()->get('aerospike_cache')->error(
        'Aerospike cache @op failed on bin "@bin": @msg',
        ['@op' => $op, '@bin' => $this->bin, '@msg' => $e->getMessage()],
      );
    }
  }

  /**
   * Logs a skipped oversized item once per request to avoid log flooding.
   *
   * @param string $cid
   *   The cache ID that was skipped.
   * @param int $size
   *   The encoded payload size in bytes.
   */
  protected function logOversized(string $cid, int $size): void {
    if (!$this->oversizedLogged) {
      $this->oversizedLogged = TRUE;
      ($this->loggerFactory)()->get('aerospike_cache')->warning(
        'Aerospike cache skipped oversized item "@cid" on bin "@bin": @size bytes exceeds the @max byte limit. The item is not cached and will be recomputed.',
        [
          '@cid' => $cid,
          '@bin' => $this->bin,
          '@size' => $size,
          '@max' => $this->connection->getMaxRecordSize(),
        ],
      );
    }
  }

  /**
   * Builds a write policy carrying the correct expiration for an item.
   *
   * @param int $expire
   *   The cache expire timestamp, or Cache::PERMANENT.
   *
   * @return \Aerospike\WritePolicy
   *   The write policy.
   */
  protected function writePolicyFor(int $expire): WritePolicy {
    $wp = new WritePolicy();
    // NamespaceDefault() uses the namespace default-ttl (0 / never); Seconds(n)
    // expires n seconds from now.
    if ($expire !== Cache::PERMANENT) {
      $wp->expiration = Expiration::Seconds(max(1, $expire - $this->time->getRequestTime()));
    }
    else {
      $wp->expiration = Expiration::NamespaceDefault();
    }
    return $wp;
  }

  /**
   * Builds the Aerospike key for the Nth chunk of a multipart item.
   *
   * @param string $cid
   *   The parent cache ID.
   * @param int $index
   *   The zero-based chunk index.
   *
   * @return \Aerospike\Key
   *   The chunk key, in the parent's set so bulk truncation clears it too.
   */
  protected function chunkKey(string $cid, int $index): Key {
    return $this->connection->makeKey($this->bin, $cid . '::chunk::' . $index);
  }

  /**
   * Reads and concatenates the chunk records of a multipart item.
   *
   * @param string $cid
   *   The parent cache ID.
   * @param int $count
   *   The number of chunks to read.
   *
   * @return string|null
   *   The reassembled payload, or NULL if any chunk is missing.
   */
  protected function readChunks(string $cid, int $count): ?string {
    try {
      $brp = new BatchReadPolicy();
      $reads = [];
      for ($i = 0; $i < $count; $i++) {
        $reads[] = new BatchRead($brp, $this->chunkKey($cid, $i), ['data']);
      }
      $results = $this->connection->getClient()->batch(new BatchPolicy(), $reads);

      $payload = '';
      foreach ($results as $result) {
        $record = $result->getRecord();
        if ($record === NULL || !isset($record->bins['data'])) {
          // A chunk is gone; the item cannot be reconstructed.
          return NULL;
        }
        $payload .= $record->bins['data'];
      }
      return $payload;
    }
    catch (\Throwable $e) {
      $this->logError('readChunks', $e);
      return NULL;
    }
  }

}
