<?php

namespace Drupal\aerospike_cache;

use Drupal\Core\Site\Settings;

/**
 * Request-scoped collector of Aerospike cache activity.
 *
 * The cache backends report every read, write and failover into this service
 * during a request; the response subscriber and the admin toolbar then read it
 * back to show whether (and how fast) the response came out of Aerospike.
 *
 * Collection is gated and is a no-op when disabled, so there is no measurable
 * overhead in production. The gate is resolved from two sources:
 *
 * 1. If `$settings['aerospike_cache_debug']` is defined in settings.php it
 *    wins outright (TRUE or FALSE) — this lets an environment hard-force the
 *    state regardless of the UI, e.g. to keep production off.
 * 2. Otherwise the State value `aerospike_cache.debug` is used, which the admin
 *    screen toggles without a deployment.
 *
 * @code
 * $settings['aerospike_cache_debug'] = TRUE;
 * @endcode
 */
class AerospikeCacheStats {

  /**
   * Whether settings.php explicitly defines the debug flag (overriding State).
   */
  protected bool $settingsForced;

  /**
   * The settings.php value, when it defines the flag.
   */
  protected bool $settingsValue;

  /**
   * Memoised resolved gate for this request, or NULL until first resolved.
   */
  protected ?bool $enabled = NULL;

  /**
   * Per-bin counters, keyed by bin name then by metric (hit/miss/set).
   *
   * @var array<string, array<string, int>>
   */
  protected array $bins = [];

  /**
   * Total get() operations this request.
   */
  protected int $gets = 0;

  /**
   * Total reads that returned a valid item.
   */
  protected int $hits = 0;

  /**
   * Total reads that missed.
   */
  protected int $misses = 0;

  /**
   * Total write operations this request.
   */
  protected int $sets = 0;

  /**
   * Cumulative time spent in Aerospike I/O this request, in seconds.
   */
  protected float $time = 0.0;

  /**
   * Whether the failover backend routed to the database this request.
   */
  protected bool $bypassed = FALSE;

  /**
   * Constructs the collector.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings, read for the `aerospike_cache_debug` override.
   * @param \Closure $stateProvider
   *   A service closure returning the State service. Injected lazily and only
   *   invoked when settings.php does not define the flag, so resolving the gate
   *   never touches the database during early bootstrap in the common case.
   */
  public function __construct(Settings $settings, protected \Closure $stateProvider) {
    $all = $settings->getAll();
    $this->settingsForced = array_key_exists('aerospike_cache_debug', $all);
    $this->settingsValue = $this->settingsForced && (bool) $all['aerospike_cache_debug'];
  }

  /**
   * Whether diagnostics collection is active.
   *
   * Resolved once per request: settings.php wins if it defines the flag,
   * otherwise the State toggle is read and memoised.
   */
  public function isEnabled(): bool {
    if ($this->enabled === NULL) {
      $this->enabled = $this->settingsForced
        ? $this->settingsValue
        : (bool) ($this->stateProvider)()->get('aerospike_cache.debug', FALSE);
    }
    return $this->enabled;
  }

  /**
   * Records a single get() against a bin.
   *
   * @param string $bin
   *   The cache bin (Aerospike set).
   * @param bool $hit
   *   TRUE if a valid item was returned, FALSE on a miss.
   * @param float $seconds
   *   Elapsed I/O time.
   */
  public function recordGet(string $bin, bool $hit, float $seconds): void {
    if (!$this->isEnabled()) {
      return;
    }
    $this->gets++;
    $hit ? $this->hits++ : $this->misses++;
    $this->time += $seconds;
    $this->bins[$bin][$hit ? 'hit' : 'miss'] = ($this->bins[$bin][$hit ? 'hit' : 'miss'] ?? 0) + 1;
  }

  /**
   * Records a getMultiple() against a bin.
   *
   * @param string $bin
   *   The cache bin.
   * @param int $hits
   *   Number of cids that resolved to a valid item.
   * @param int $misses
   *   Number of cids that missed.
   * @param float $seconds
   *   Elapsed I/O time for the batch.
   */
  public function recordGetMultiple(string $bin, int $hits, int $misses, float $seconds): void {
    if (!$this->isEnabled()) {
      return;
    }
    $this->gets += $hits + $misses;
    $this->hits += $hits;
    $this->misses += $misses;
    $this->time += $seconds;
    if ($hits) {
      $this->bins[$bin]['hit'] = ($this->bins[$bin]['hit'] ?? 0) + $hits;
    }
    if ($misses) {
      $this->bins[$bin]['miss'] = ($this->bins[$bin]['miss'] ?? 0) + $misses;
    }
  }

  /**
   * Records a write against a bin.
   *
   * @param string $bin
   *   The cache bin.
   * @param float $seconds
   *   Elapsed I/O time.
   */
  public function recordSet(string $bin, float $seconds): void {
    if (!$this->isEnabled()) {
      return;
    }
    $this->sets++;
    $this->time += $seconds;
    $this->bins[$bin]['set'] = ($this->bins[$bin]['set'] ?? 0) + 1;
  }

  /**
   * Flags that the failover backend served from the database this request.
   */
  public function markBypass(): void {
    if ($this->isEnabled()) {
      $this->bypassed = TRUE;
    }
  }

  /**
   * Whether Aerospike was bypassed (failed over to database) this request.
   */
  public function isBypassed(): bool {
    return $this->bypassed;
  }

  /**
   * Returns request totals: gets, hits, misses, sets and time in ms.
   *
   * @return array{gets:int,hits:int,misses:int,sets:int,ms:float}
   *   The totals.
   */
  public function getTotals(): array {
    return [
      'gets' => $this->gets,
      'hits' => $this->hits,
      'misses' => $this->misses,
      'sets' => $this->sets,
      'ms' => round($this->time * 1000, 2),
    ];
  }

  /**
   * Returns the per-bin counters.
   *
   * @return array<string, array<string, int>>
   *   Counters keyed by bin name.
   */
  public function getBins(): array {
    return $this->bins;
  }

  /**
   * Derives the page-level result for the response's primary cache.
   *
   * Reports the outcome of the bin that serves the whole response — the
   * anonymous page cache, or the dynamic page cache for authenticated traffic.
   *
   * @return string|null
   *   'BYPASS', 'HIT', 'MISS', or NULL when neither page bin was touched.
   */
  public function getPageResult(): ?string {
    if ($this->bypassed) {
      return 'BYPASS';
    }
    foreach (['page', 'dynamic_page_cache'] as $bin) {
      if (isset($this->bins[$bin])) {
        return ($this->bins[$bin]['hit'] ?? 0) > 0 ? 'HIT' : 'MISS';
      }
    }
    return NULL;
  }

}
