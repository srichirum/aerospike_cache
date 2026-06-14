<?php

namespace Drupal\Tests\aerospike_cache\Unit;

use Drupal\aerospike_cache\AerospikeCacheStats;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\aerospike_cache\AerospikeCacheStats
 * @group aerospike_cache
 */
class AerospikeCacheStatsTest extends UnitTestCase {

  /**
   * Builds a collector with the given settings array and State value.
   *
   * @param array $settings
   *   The settings.php array.
   * @param bool|null $stateValue
   *   The State value to return, or NULL to assert State is never read.
   *
   * @return \Drupal\aerospike_cache\AerospikeCacheStats
   *   The collector.
   */
  protected function collector(array $settings, ?bool $stateValue): AerospikeCacheStats {
    $state = $this->createMock(StateInterface::class);
    if ($stateValue === NULL) {
      $state->expects($this->never())->method('get');
    }
    else {
      $state->method('get')->with('aerospike_cache.debug', FALSE)->willReturn($stateValue);
    }
    return new AerospikeCacheStats(new Settings($settings), fn() => $state);
  }

  /**
   * A TRUE settings.php override forces diagnostics on, never reading State.
   *
   * @covers ::isEnabled
   */
  public function testSettingsForceOn(): void {
    $this->assertTrue($this->collector(['aerospike_cache_debug' => TRUE], NULL)->isEnabled());
  }

  /**
   * A FALSE settings.php override wins even when State is TRUE.
   *
   * @covers ::isEnabled
   */
  public function testSettingsForceOffOverridesState(): void {
    // State is TRUE but must never be consulted because settings.php wins.
    $this->assertFalse($this->collector(['aerospike_cache_debug' => FALSE], NULL)->isEnabled());
  }

  /**
   * With no settings.php override, the State toggle decides.
   *
   * @covers ::isEnabled
   */
  public function testStateUsedWhenSettingsAbsent(): void {
    $this->assertTrue($this->collector([], TRUE)->isEnabled());
    $this->assertFalse($this->collector([], FALSE)->isEnabled());
  }

  /**
   * The State gate is resolved once and memoised across calls.
   *
   * @covers ::isEnabled
   */
  public function testStateReadOnce(): void {
    $state = $this->createMock(StateInterface::class);
    $state->expects($this->once())->method('get')->willReturn(TRUE);
    $stats = new AerospikeCacheStats(new Settings([]), fn() => $state);
    $stats->isEnabled();
    $stats->isEnabled();
    $this->assertTrue($stats->isEnabled());
  }

  /**
   * When disabled, recording is a no-op and totals stay zero.
   *
   * @covers ::recordGet
   * @covers ::recordSet
   */
  public function testRecordingNoOpWhenDisabled(): void {
    $stats = $this->collector([], FALSE);
    $stats->recordGet('page', TRUE, 0.01);
    $stats->recordSet('page', 0.01);
    $this->assertSame(
      ['gets' => 0, 'hits' => 0, 'misses' => 0, 'sets' => 0, 'ms' => 0.0],
      $stats->getTotals(),
    );
    $this->assertNull($stats->getPageResult());
  }

  /**
   * When enabled, reads and writes accumulate and drive the page result.
   *
   * @covers ::recordGet
   * @covers ::recordGetMultiple
   * @covers ::recordSet
   * @covers ::getPageResult
   */
  public function testRecordingWhenEnabled(): void {
    $stats = $this->collector([], TRUE);
    $stats->recordGet('page', TRUE, 0.002);
    $stats->recordGetMultiple('render', 3, 1, 0.004);
    $stats->recordSet('render', 0.001);

    $totals = $stats->getTotals();
    $this->assertSame(5, $totals['gets']);
    $this->assertSame(4, $totals['hits']);
    $this->assertSame(1, $totals['misses']);
    $this->assertSame(1, $totals['sets']);
    // Page bin had a hit, so the page-level result is HIT.
    $this->assertSame('HIT', $stats->getPageResult());
  }

  /**
   * A failover marks the page result as BYPASS.
   *
   * @covers ::markBypass
   * @covers ::getPageResult
   */
  public function testBypassResult(): void {
    $stats = $this->collector([], TRUE);
    $stats->recordGet('page', FALSE, 0.001);
    $stats->markBypass();
    $this->assertTrue($stats->isBypassed());
    $this->assertSame('BYPASS', $stats->getPageResult());
  }

}
