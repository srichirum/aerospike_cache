<?php

namespace Drupal\Tests\aerospike_cache\Unit;

use Drupal\aerospike_cache\AerospikeConnection;
use Drupal\aerospike_cache\AerospikeFailoverCacheBackend;
use Drupal\Core\Cache\CacheBackendInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\aerospike_cache\AerospikeFailoverCacheBackend
 * @group aerospike_cache
 */
class AerospikeFailoverCacheBackendTest extends TestCase {

  /**
   * Mock primary (Aerospike) backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  private CacheBackendInterface $aerospike;

  /**
   * Mock fallback (database) backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  private CacheBackendInterface $fallback;

  /**
   * Mock Aerospike connection.
   *
   * @var \Drupal\aerospike_cache\AerospikeConnection|\PHPUnit\Framework\MockObject\MockObject
   */
  private AerospikeConnection $connection;

  /**
   * Mock logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  private LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->aerospike  = $this->createMock(CacheBackendInterface::class);
    $this->fallback   = $this->createMock(CacheBackendInterface::class);
    $this->connection = $this->createMock(AerospikeConnection::class);
    $this->logger     = $this->createMock(LoggerInterface::class);
  }

  /**
   * Returns a fresh failover backend wired with mock dependencies.
   */
  private function backend(): AerospikeFailoverCacheBackend {
    return new AerospikeFailoverCacheBackend(
      $this->aerospike,
      $this->fallback,
      $this->connection,
      $this->logger,
    );
  }

  /**
   * @covers ::get
   */
  public function testGetRoutesToAerospikeWhenAvailable(): void {
    $this->connection->method('isAvailable')->willReturn(TRUE);
    $item = (object) ['cid' => 'foo', 'data' => 'bar'];
    $this->aerospike->method('get')->with('foo', FALSE)->willReturn($item);
    $this->fallback->expects($this->never())->method('get');

    $this->assertSame($item, $this->backend()->get('foo'));
  }

  /**
   * @covers ::set
   */
  public function testSetRoutesToAerospikeWhenAvailable(): void {
    $this->connection->method('isAvailable')->willReturn(TRUE);
    $this->aerospike->expects($this->once())
      ->method('set')
      ->with('foo', 'bar', CacheBackendInterface::CACHE_PERMANENT, []);
    $this->fallback->expects($this->never())->method('set');

    $this->backend()->set('foo', 'bar');
  }

  /**
   * @covers ::delete
   */
  public function testDeleteRoutesToAerospikeWhenAvailable(): void {
    $this->connection->method('isAvailable')->willReturn(TRUE);
    $this->aerospike->expects($this->once())->method('delete')->with('foo');
    $this->fallback->expects($this->never())->method('delete');

    $this->backend()->delete('foo');
  }

  /**
   * @covers ::get
   */
  public function testGetRoutesToFallbackWhenUnavailable(): void {
    $this->connection->method('isAvailable')->willReturn(FALSE);
    $item = (object) ['cid' => 'foo', 'data' => 'fallback'];
    $this->fallback->method('get')->with('foo', FALSE)->willReturn($item);
    $this->aerospike->expects($this->never())->method('get');

    $this->assertSame($item, $this->backend()->get('foo'));
  }

  /**
   * @covers ::set
   */
  public function testSetRoutesToFallbackWhenUnavailable(): void {
    $this->connection->method('isAvailable')->willReturn(FALSE);
    $this->fallback->expects($this->once())->method('set');
    $this->aerospike->expects($this->never())->method('set');

    $this->backend()->set('foo', 'bar');
  }

  /**
   * @covers ::deleteAll
   */
  public function testDeleteAllRoutesToFallbackWhenUnavailable(): void {
    $this->connection->method('isAvailable')->willReturn(FALSE);
    $this->fallback->expects($this->once())->method('deleteAll');
    $this->aerospike->expects($this->never())->method('deleteAll');

    $this->backend()->deleteAll();
  }

  /**
   * @covers ::backend
   */
  public function testWarningLoggedExactlyOnceOnFailover(): void {
    $this->connection->method('isAvailable')->willReturn(FALSE);
    $this->fallback->method('get')->willReturn(FALSE);
    $this->logger->expects($this->once())->method('warning');

    $backend = $this->backend();
    $backend->get('a');
    $backend->get('b');
    $backend->set('c', 'v');
    $backend->deleteAll();
  }

  /**
   * @covers ::backend
   */
  public function testNoWarningWhenAerospikeIsAvailable(): void {
    $this->connection->method('isAvailable')->willReturn(TRUE);
    $this->aerospike->method('get')->willReturn(FALSE);
    $this->logger->expects($this->never())->method('warning');

    $backend = $this->backend();
    $backend->get('a');
    $backend->set('b', 'v');
  }

  /**
   * @covers ::getMultiple
   */
  public function testGetMultipleDelegates(): void {
    $this->connection->method('isAvailable')->willReturn(TRUE);
    $cids = ['a', 'b'];
    $this->aerospike->expects($this->once())
      ->method('getMultiple')
      ->with($this->identicalTo($cids), FALSE)
      ->willReturn([]);

    $this->backend()->getMultiple($cids);
  }

  /**
   * @covers ::setMultiple
   */
  public function testSetMultipleDelegates(): void {
    $this->connection->method('isAvailable')->willReturn(TRUE);
    $items = [['data' => 'x']];
    $this->aerospike->expects($this->once())
      ->method('setMultiple')
      ->with($items);

    $this->backend()->setMultiple($items);
  }

  /**
   * @covers ::deleteMultiple
   */
  public function testDeleteMultipleDelegates(): void {
    $this->connection->method('isAvailable')->willReturn(TRUE);
    $this->aerospike->expects($this->once())
      ->method('deleteMultiple')
      ->with(['a', 'b']);

    $this->backend()->deleteMultiple(['a', 'b']);
  }

  /**
   * @covers ::invalidate
   */
  public function testInvalidateDelegates(): void {
    $this->connection->method('isAvailable')->willReturn(TRUE);
    $this->aerospike->expects($this->once())->method('invalidate')->with('foo');

    $this->backend()->invalidate('foo');
  }

  /**
   * @covers ::invalidateMultiple
   */
  public function testInvalidateMultipleDelegates(): void {
    $this->connection->method('isAvailable')->willReturn(TRUE);
    $this->aerospike->expects($this->once())
      ->method('invalidateMultiple')
      ->with(['a', 'b']);

    $this->backend()->invalidateMultiple(['a', 'b']);
  }

  /**
   * @covers ::invalidateAll
   */
  public function testInvalidateAllDelegates(): void {
    $this->connection->method('isAvailable')->willReturn(TRUE);
    $this->aerospike->expects($this->once())->method('invalidateAll');

    $this->backend()->invalidateAll();
  }

  /**
   * @covers ::garbageCollection
   */
  public function testGarbageCollectionDelegates(): void {
    $this->connection->method('isAvailable')->willReturn(TRUE);
    $this->aerospike->expects($this->once())->method('garbageCollection');

    $this->backend()->garbageCollection();
  }

  /**
   * @covers ::removeBin
   */
  public function testRemoveBinDelegates(): void {
    $this->connection->method('isAvailable')->willReturn(TRUE);
    $this->aerospike->expects($this->once())->method('removeBin');

    $this->backend()->removeBin();
  }

}
