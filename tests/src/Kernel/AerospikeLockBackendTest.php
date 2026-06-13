<?php

namespace Drupal\Tests\aerospike_cache\Kernel;

use Drupal\aerospike_cache\Lock\AerospikeLockBackend;
use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel tests for AerospikeLockBackend.
 *
 * Requires a running ACM daemon and the aerospike_php extension; tests are
 * skipped automatically when Aerospike is unavailable.
 *
 * @group aerospike_cache
 * @group aerospike_cache_kernel
 * @coversDefaultClass \Drupal\aerospike_cache\Lock\AerospikeLockBackend
 */
class AerospikeLockBackendTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['aerospike_cache'];

  /**
   * First lock backend (acts as request "A").
   *
   * @var \Drupal\aerospike_cache\Lock\AerospikeLockBackend
   */
  private AerospikeLockBackend $a;

  /**
   * Second lock backend (acts as request "B"), distinct lock ID.
   *
   * @var \Drupal\aerospike_cache\Lock\AerospikeLockBackend
   */
  private AerospikeLockBackend $b;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if (!extension_loaded('aerospike_php')) {
      $this->markTestSkipped('aerospike_php extension not loaded.');
    }
    $connection = $this->container->get('aerospike_cache.connection');
    if (!$connection->isAvailable()) {
      $this->markTestSkipped('Aerospike ACM socket not reachable.');
    }
    $this->a = new AerospikeLockBackend($connection);
    $this->b = new AerospikeLockBackend($connection);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->a)) {
      $this->a->releaseAll();
    }
    if (isset($this->b)) {
      $this->b->releaseAll();
    }
    parent::tearDown();
  }

  /**
   * @covers ::acquire
   */
  public function testAcquireSucceedsForFreeLock(): void {
    $this->assertTrue($this->a->acquire('free', 30));
  }

  /**
   * @covers ::acquire
   */
  public function testSecondAcquireBlockedWhileHeld(): void {
    $this->assertTrue($this->a->acquire('held', 30));
    $this->assertFalse($this->b->acquire('held', 30));
  }

  /**
   * @covers ::lockMayBeAvailable
   */
  public function testLockMayBeAvailableReflectsState(): void {
    $this->assertTrue($this->b->lockMayBeAvailable('avail'));
    $this->a->acquire('avail', 30);
    $this->assertFalse($this->b->lockMayBeAvailable('avail'));
  }

  /**
   * @covers ::release
   */
  public function testReleaseFreesLockForOthers(): void {
    $this->a->acquire('rel', 30);
    $this->a->release('rel');
    $this->assertTrue($this->b->acquire('rel', 30));
  }

  /**
   * @covers ::release
   */
  public function testReleaseByNonOwnerDoesNotStealLock(): void {
    $this->a->acquire('owned', 30);
    // B never held it; releasing must not delete A's lock.
    $this->b->release('owned');
    $this->assertFalse($this->b->acquire('owned', 30));
  }

  /**
   * @covers ::acquire
   */
  public function testReentrantAcquireExtendsOwnLock(): void {
    $this->assertTrue($this->a->acquire('reentrant', 30));
    $this->assertTrue($this->a->acquire('reentrant', 30));
    // Still exclusively held against another request.
    $this->assertFalse($this->b->acquire('reentrant', 30));
  }

  /**
   * @covers ::releaseAll
   */
  public function testReleaseAllFreesEveryHeldLock(): void {
    $this->a->acquire('one', 30);
    $this->a->acquire('two', 30);
    $this->a->releaseAll();
    $this->assertTrue($this->b->acquire('one', 30));
    $this->assertTrue($this->b->acquire('two', 30));
  }

}
