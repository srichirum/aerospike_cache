<?php

namespace Drupal\aerospike_cache_admin\Toolbar;

use Drupal\aerospike_cache\AerospikeCacheStats;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Builds the live per-request Aerospike toolbar tab and tray.
 *
 * Rendered via #lazy_builder placeholders so the figures are recomputed on
 * every response — including dynamic-page-cache hits, where the rest of the
 * page is served from cache.
 */
class AerospikeToolbar implements TrustedCallbackInterface {

  use StringTranslationTrait;

  /**
   * Constructs the toolbar builder.
   *
   * @param \Drupal\aerospike_cache\AerospikeCacheStats $stats
   *   The request-scoped stats collector.
   */
  public function __construct(protected AerospikeCacheStats $stats) {}

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['renderTab', 'renderTray'];
  }

  /**
   * Renders the toolbar tab: the page-level result and a one-line summary.
   *
   * @return array
   *   A render array.
   */
  public function renderTab(): array {
    $totals = $this->stats->getTotals();
    $result = $this->stats->getPageResult();
    $detail = $this->t('@ops ops · @hit hit / @miss miss · @ms ms', [
      '@ops' => $totals['gets'],
      '@hit' => $totals['hits'],
      '@miss' => $totals['misses'],
      '@ms' => $totals['ms'],
    ]);
    return [
      '#type' => 'inline_template',
      '#template' => '<div class="aerospike-toolbar aerospike-toolbar--{{ state }}"><span class="aerospike-toolbar__result">⚡ {{ result }}</span><span class="aerospike-toolbar__detail">{{ detail }}</span></div>',
      '#context' => [
        'state' => strtolower($result ?? 'na'),
        'result' => $result ? 'Aerospike: ' . $result : $this->t('Aerospike: n/a'),
        'detail' => $detail,
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Renders the toolbar tray: the per-bin breakdown for this request.
   *
   * @return array
   *   A render array.
   */
  public function renderTray(): array {
    $rows = [];
    foreach ($this->stats->getBins() as $bin => $metrics) {
      $rows[] = [
        $bin,
        $metrics['hit'] ?? 0,
        $metrics['miss'] ?? 0,
        $metrics['set'] ?? 0,
      ];
    }
    $note = $this->stats->isBypassed()
      ? $this->t('Aerospike was unreachable — this request was served from the database fallback.')
      : $this->t('Counts reflect Aerospike I/O for the current request. APCu-tier hits (bootstrap/config/discovery) never reach Aerospike and are not counted.');
    return [
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Bin'), $this->t('Hit'), $this->t('Miss'), $this->t('Set')],
        '#rows' => $rows,
        '#empty' => $this->t('No Aerospike activity recorded for this request.'),
      ],
      'note' => [
        '#markup' => '<p class="aerospike-toolbar__note">' . $note . '</p>',
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

}
