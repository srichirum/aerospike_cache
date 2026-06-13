<?php

namespace Drupal\aerospike_cache_admin\Controller;

use Aerospike\Bin;
use Aerospike\Key;
use Aerospike\PartitionFilter;
use Aerospike\ReadPolicy;
use Aerospike\ScanPolicy;
use Aerospike\WritePolicy;
use Drupal\aerospike_cache\AerospikeConnection;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the Aerospike cache status and diagnostics report.
 *
 * Aerospike server-internal statistics (memory, evictions, hit ratio) are
 * reached over the info protocol, which the PHP client does not expose through
 * the ACM socket. This report therefore covers what is reachable from the
 * application: connection health, the module's configuration, a live
 * round-trip latency probe, and per-bin record counts gathered by scanning.
 */
class AerospikeStatusController extends ControllerBase {

  /**
   * Cache bins (Aerospike sets) shown in the record-count overview.
   */
  protected const KNOWN_BINS = [
    'default',
    'render',
    'config',
    'bootstrap',
    'discovery',
    'data',
    'dynamic_page_cache',
    'entity',
    'menu',
    'page',
    'cachetags',
    'semaphore',
  ];

  /**
   * Upper bound on records counted per bin, to keep the page responsive.
   */
  protected const COUNT_CAP = 100000;

  /**
   * Constructs the controller.
   *
   * @param \Drupal\aerospike_cache\AerospikeConnection $connection
   *   The Aerospike connection.
   */
  public function __construct(protected AerospikeConnection $connection) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('aerospike_cache.connection'));
  }

  /**
   * Renders the report page.
   *
   * @return array
   *   A render array.
   */
  public function report(): array {
    $available = $this->connection->isAvailable();

    $build['connection'] = $this->section($this->t('Connection'), [
      [$this->t('ACM socket reachable'), $available ? $this->t('Yes') : $this->t('No — failing over to database')],
      [$this->t('Socket path'), $this->connection->getSocket()],
      [$this->t('Namespace'), $this->connection->getNamespace()],
      [$this->t('Key prefix'), $this->connection->getPrefix() ?: $this->t('(none)')],
      [$this->t('PHP extension'), extension_loaded('aerospike_php') ? $this->t('Loaded') : $this->t('Not loaded')],
    ]);

    $maxSize = number_format($this->connection->getMaxRecordSize());
    $threshold = number_format($this->connection->getCompressThreshold());
    $build['config'] = $this->section($this->t('Configuration'), [
      [$this->t('Max record size'), $this->t('@n bytes', ['@n' => $maxSize])],
      [$this->t('Compress threshold'), $this->t('@n bytes', ['@n' => $threshold])],
    ]);

    if (!$available) {
      $build['unavailable'] = [
        '#markup' => '<p>' . $this->t('Aerospike is unreachable; latency and record counts are unavailable.') . '</p>',
      ];
      return $build;
    }

    $build['latency'] = $this->section($this->t('Round-trip latency'), $this->probeLatency());
    $build['bins'] = $this->section($this->t('Records per bin'), $this->binCounts(), [
      $this->t('Bin'), $this->t('Records'),
    ]);

    return $build;
  }

  /**
   * Measures write/read/delete round-trip latency with a throwaway key.
   */
  protected function probeLatency(): array {
    $rows = [];
    try {
      $client = $this->connection->getClient();
      $key = new Key($this->connection->getNamespace(), 'aerospike_admin_probe', 'probe:' . uniqid());

      $t = microtime(TRUE);
      $client->put(new WritePolicy(), $key, [new Bin('v', 1)]);
      $rows[] = [$this->t('Write'), $this->ms($t)];

      $t = microtime(TRUE);
      $client->get(new ReadPolicy(), $key);
      $rows[] = [$this->t('Read'), $this->ms($t)];

      $t = microtime(TRUE);
      $client->delete(new WritePolicy(), $key);
      $rows[] = [$this->t('Delete'), $this->ms($t)];
    }
    catch (\Throwable $e) {
      $rows[] = [$this->t('Error'), $e->getMessage()];
    }
    return $rows;
  }

  /**
   * Counts records in each known bin by scanning (capped).
   */
  protected function binCounts(): array {
    $rows = [];
    $client = $this->connection->getClient();
    foreach (self::KNOWN_BINS as $bin) {
      try {
        $count = 0;
        $recordset = $client->scan(new ScanPolicy(), PartitionFilter::all(), $this->connection->getNamespace(), $bin, []);
        while ($recordset->getActive()) {
          $record = $recordset->next();
          if ($record === NULL || $record === FALSE) {
            break;
          }
          if (++$count >= self::COUNT_CAP) {
            break;
          }
        }
        $recordset->close();
        $label = $count >= self::COUNT_CAP ? $this->t('@n+', ['@n' => number_format(self::COUNT_CAP)]) : number_format($count);
        $rows[] = [$bin, $label];
      }
      catch (\Throwable $e) {
        $rows[] = [$bin, $this->t('—')];
      }
    }
    return $rows;
  }

  /**
   * Builds a labelled table section.
   */
  protected function section($title, array $rows, ?array $header = NULL): array {
    return [
      '#type' => 'table',
      '#caption' => $title,
      '#header' => $header ?: ['', ''],
      '#rows' => $rows,
      '#attributes' => ['class' => ['aerospike-cache-report']],
    ];
  }

  /**
   * Formats elapsed time since $start as a millisecond string.
   */
  protected function ms(float $start): string {
    return number_format((microtime(TRUE) - $start) * 1000, 2) . ' ms';
  }

}
