<?php

namespace Drupal\Tests\aerospike_cache\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\aerospike_cache\AerospikeCacheBackend;
use Drupal\aerospike_cache\AerospikeConnection;
use Drupal\Core\Cache\Cache;
use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel tests for AerospikeCacheBackend.
 *
 * Requires a running ACM daemon at /tmp/asld_grpc.sock and the aerospike_php
 * extension. Tests are skipped automatically when Aerospike is unavailable.
 *
 * @group aerospike_cache
 * @group aerospike_cache_kernel
 */
class AerospikeCacheBackendTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['aerospike_cache'];

  /**
   * The cache backend under test.
   *
   * @var \Drupal\aerospike_cache\AerospikeCacheBackend
   */
  private AerospikeCacheBackend $backend;

  /**
   * The Aerospike connection service.
   *
   * @var \Drupal\aerospike_cache\AerospikeConnection
   */
  private AerospikeConnection $connection;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if (!extension_loaded('aerospike_php')) {
      $this->markTestSkipped('aerospike_php extension not loaded.');
    }

    $this->connection = $this->container->get('aerospike_cache.connection');

    if (!$this->connection->isAvailable()) {
      $this->markTestSkipped('Aerospike ACM socket not reachable.');
    }

    $checksum = $this->container->get('cache_tags.invalidator.checksum');
    $time     = $this->container->get('datetime.time');
    // The backend constructor takes a service closure returning logger.factory.
    $loggerClosure = fn() => $this->container->get('logger.factory');

    $this->backend = new AerospikeCacheBackend(
      'test_' . $this->randomMachineName(),
      $this->connection,
      $checksum,
      $time,
      $loggerClosure,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->backend->deleteAll();
    parent::tearDown();
  }

  /**
   * Tests that get() returns FALSE for a cache miss.
   */
  public function testGetReturnsFalseOnMiss(): void {
    $this->assertFalse($this->backend->get('nonexistent'));
  }

  /**
   * Tests that set() data is retrievable via get().
   */
  public function testSetAndGetRoundTrip(): void {
    $this->backend->set('foo', 'bar');
    $item = $this->backend->get('foo');

    $this->assertNotFalse($item);
    $this->assertSame('bar', $item->data);
    $this->assertSame('foo', $item->cid);
    $this->assertTrue($item->valid);
  }

  /**
   * Tests that complex nested data survives a set/get round trip.
   */
  public function testSetPreservesComplexData(): void {
    $data = ['nested' => ['key' => 'value'], 'number' => 42, 'flag' => TRUE];
    $this->backend->set('complex', $data);

    $item = $this->backend->get('complex');
    $this->assertSame($data, $item->data);
  }

  /**
   * Tests that a large item exceeding Aerospike's record limit is compressed.
   *
   * A ~2 MB serialized array would exceed the 1 MiB record limit if stored
   * raw, but compresses well below it and must round-trip intact.
   */
  public function testLargeItemIsCompressedAndRoundTrips(): void {
    $data = [];
    for ($i = 0; $i < 20000; $i++) {
      $data[] = ['id' => $i, 'label' => "Item $i", 'markup' => '<div class="card">x</div>'];
    }
    $this->assertGreaterThan(1048576, strlen(serialize($data)), 'Fixture must exceed 1 MiB raw.');

    $this->backend->set('large', $data);
    $item = $this->backend->get('large');

    $this->assertNotFalse($item, 'Large item should be cached after compression.');
    $this->assertSame($data, $item->data);
  }

  /**
   * Tests that an item too large even after compression is skipped cleanly.
   *
   * Random bytes do not compress, so a multi-megabyte payload stays over the
   * limit. set() must skip it silently (cache miss) rather than throwing.
   */
  public function testOversizedItemIsSkippedNotErrored(): void {
    $incompressible = base64_encode(random_bytes(3 * 1024 * 1024));

    // Must not throw.
    $this->backend->set('oversized', $incompressible);

    // The item is simply absent — a clean cache miss.
    $this->assertFalse($this->backend->get('oversized'));
  }

  /**
   * Tests that cache tags are stored and returned with the item.
   */
  public function testSetWithTags(): void {
    $this->backend->set('tagged', 'value', Cache::PERMANENT, ['node:1', 'user:5']);
    $item = $this->backend->get('tagged');

    $this->assertNotFalse($item);
    $this->assertContains('node:1', $item->tags);
    $this->assertContains('user:5', $item->tags);
  }

  /**
   * Tests that the expiry timestamp is stored and returned correctly.
   */
  public function testSetWithExpiry(): void {
    $expire = \Drupal::time()->getRequestTime() + 3600;
    $this->backend->set('expiring', 'value', $expire);

    $item = $this->backend->get('expiring');
    $this->assertNotFalse($item);
    $this->assertSame($expire, $item->expire);
  }

  /**
   * Tests that an expired item is treated as a cache miss.
   */
  public function testExpiredItemReturnsFalse(): void {
    $past = \Drupal::time()->getRequestTime() - 1;
    $this->backend->set('expired', 'value', $past);

    $this->assertFalse($this->backend->get('expired'));
  }

  /**
   * Tests that an expired item is returned when $allow_invalid is TRUE.
   */
  public function testExpiredItemReturnedWhenAllowInvalid(): void {
    $past = \Drupal::time()->getRequestTime() - 1;
    $this->backend->set('expired', 'value', $past);

    $item = $this->backend->get('expired', TRUE);
    $this->assertNotFalse($item);
    $this->assertFalse($item->valid);
  }

  /**
   * Tests that getMultiple() returns found items and removes their cids.
   */
  public function testGetMultipleReturnsFoundItems(): void {
    $this->backend->set('a', 'alpha');
    $this->backend->set('b', 'beta');

    $cids   = ['a', 'b', 'missing'];
    $result = $this->backend->getMultiple($cids);

    $this->assertArrayHasKey('a', $result);
    $this->assertArrayHasKey('b', $result);
    $this->assertArrayNotHasKey('missing', $result);
    // Found cids are removed from the input array.
    $this->assertSame(['missing'], array_values($cids));
  }

  /**
   * Tests that getMultiple() fails soft when the connection is broken.
   *
   * With the batch implementation a single failure aborts the whole call.
   * The cids array must remain unmodified so the caller can fall back.
   */
  public function testGetMultipleFailsSoftOnConnectionError(): void {
    $brokenConn = new AerospikeConnection(
      new Settings(['aerospike_cache_socket' => '/tmp/__no_socket__.sock'])
    );
    $backend = new AerospikeCacheBackend(
      'test_' . $this->randomMachineName(),
      $brokenConn,
      $this->container->get('cache_tags.invalidator.checksum'),
      $this->container->get('datetime.time'),
      fn() => $this->container->get('logger.factory'),
    );

    $cids   = ['a', 'b'];
    $result = $backend->getMultiple($cids);

    $this->assertSame([], $result);
    // Cids must be unchanged so callers can issue a fallback.
    $this->assertSame(['a', 'b'], $cids);
  }

  /**
   * Tests that deleteMultiple() fails soft when the connection is broken.
   */
  public function testDeleteMultipleFailsSoftOnConnectionError(): void {
    $brokenConn = new AerospikeConnection(
      new Settings(['aerospike_cache_socket' => '/tmp/__no_socket__.sock'])
    );
    $backend = new AerospikeCacheBackend(
      'test_' . $this->randomMachineName(),
      $brokenConn,
      $this->container->get('cache_tags.invalidator.checksum'),
      $this->container->get('datetime.time'),
      fn() => $this->container->get('logger.factory'),
    );

    // Must not throw even when Aerospike is unreachable.
    $backend->deleteMultiple(['a', 'b']);
    $this->assertTrue(TRUE);
  }

  /**
   * Tests that delete() removes a single item.
   */
  public function testDeleteRemovesItem(): void {
    $this->backend->set('del', 'value');
    $this->assertNotFalse($this->backend->get('del'));

    $this->backend->delete('del');
    $this->assertFalse($this->backend->get('del'));
  }

  /**
   * Tests that deleteMultiple() removes only the specified items.
   */
  public function testDeleteMultipleRemovesItems(): void {
    $this->backend->set('x', '1');
    $this->backend->set('y', '2');
    $this->backend->set('z', '3');

    $this->backend->deleteMultiple(['x', 'y']);

    $this->assertFalse($this->backend->get('x'));
    $this->assertFalse($this->backend->get('y'));
    $this->assertNotFalse($this->backend->get('z'));
  }

  /**
   * Tests that deleteAll() clears every item in the bin.
   */
  public function testDeleteAllClearsEntireBin(): void {
    $this->backend->set('a', '1');
    $this->backend->set('b', '2');
    // Ensure record LUT predates the truncate timestamp.
    usleep(5000);

    $this->backend->deleteAll();
    $this->waitUntilGone('a', 'b');

    $this->assertFalse($this->backend->get('a'));
    $this->assertFalse($this->backend->get('b'));
  }

  /**
   * Tests that invalidate() marks an item invalid without deleting it.
   */
  public function testInvalidateMarksItemInvalid(): void {
    $this->backend->set('inv', 'value');
    $this->backend->invalidate('inv');

    $this->assertFalse($this->backend->get('inv'));
    $item = $this->backend->get('inv', TRUE);
    $this->assertNotFalse($item);
    $this->assertFalse($item->valid);
  }

  /**
   * Tests that invalidateMultiple() only invalidates the specified items.
   */
  public function testInvalidateMultiple(): void {
    $this->backend->set('p', 'v1');
    $this->backend->set('q', 'v2');
    $this->backend->set('r', 'v3');

    $this->backend->invalidateMultiple(['p', 'q']);

    $this->assertFalse($this->backend->get('p'));
    $this->assertFalse($this->backend->get('q'));
    $this->assertNotFalse($this->backend->get('r'));
  }

  /**
   * Tests that invalidateAll() clears every item in the bin.
   */
  public function testInvalidateAllClearsBin(): void {
    $this->backend->set('a', '1');
    $this->backend->set('b', '2');
    // Ensure record LUT predates the truncate timestamp.
    usleep(5000);

    $this->backend->invalidateAll();
    $this->waitUntilGone('a', 'b');

    $this->assertFalse($this->backend->get('a'));
    $this->assertFalse($this->backend->get('b'));
  }

  /**
   * Tests that invalidating a tag causes the tagged item to become invalid.
   */
  public function testTagInvalidationMakesItemInvalid(): void {
    $this->backend->set('tagged', 'value', Cache::PERMANENT, ['node:42']);
    $this->assertNotFalse($this->backend->get('tagged'));

    Cache::invalidateTags(['node:42']);
    $this->assertFalse($this->backend->get('tagged'));
  }

  /**
   * Tests that invalidating one tag does not affect items with different tags.
   */
  public function testItemWithUnrelatedTagsRemainsValid(): void {
    $this->backend->set('a', 'v', Cache::PERMANENT, ['node:1']);
    $this->backend->set('b', 'v', Cache::PERMANENT, ['node:2']);

    Cache::invalidateTags(['node:1']);

    $this->assertFalse($this->backend->get('a'));
    $this->assertNotFalse($this->backend->get('b'));
  }

  /**
   * Tests that removeBin() clears all items.
   */
  public function testRemoveBinClearsAllItems(): void {
    $this->backend->set('a', '1');
    // Ensure record LUT predates the truncate timestamp.
    usleep(5000);

    $this->backend->removeBin();
    $this->waitUntilGone('a');

    $this->assertFalse($this->backend->get('a'));
  }

  /**
   * Polls until all given cids are gone or 5 seconds elapse.
   *
   * Aerospike's truncate() used by deleteAll() removes records whose last-
   * update-time precedes the truncation time. Different records in the same set
   * may be evicted at slightly different moments.
   */
  private function waitUntilGone(string ...$cids): void {
    $deadline = microtime(TRUE) + 5.0;
    while (microtime(TRUE) < $deadline) {
      $allGone = TRUE;
      foreach ($cids as $cid) {
        if ($this->backend->get($cid) !== FALSE) {
          $allGone = FALSE;
          break;
        }
      }
      if ($allGone) {
        return;
      }
      usleep(200000);
    }
  }

}
