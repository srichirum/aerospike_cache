<?php

namespace Drupal\Tests\aerospike_cache\Unit;

use Drupal\aerospike_cache\AerospikeCacheTagsChecksum;
use Drupal\aerospike_cache\AerospikeConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\aerospike_cache\AerospikeCacheTagsChecksum
 * @group aerospike_cache
 */
class AerospikeCacheTagsChecksumTest extends TestCase {

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
    $this->connection = $this->createMock(AerospikeConnection::class);
    $this->logger     = $this->createMock(LoggerInterface::class);
  }

  /**
   * Returns a fresh checksum instance for each test.
   */
  private function checksum(): AerospikeCacheTagsChecksum {
    return new AerospikeCacheTagsChecksum($this->connection, $this->logger);
  }

  /**
   * @covers ::getCurrentChecksum
   */
  public function testGetCurrentChecksumReturnsZeroForEmptyTags(): void {
    $this->assertSame('0', $this->checksum()->getCurrentChecksum([]));
  }

  /**
   * @covers ::getCurrentChecksum
   */
  public function testGetCurrentChecksumReturnsZeroOnConnectionFailure(): void {
    $this->connection->method('getClient')
      ->willThrowException(new \RuntimeException('ACM unreachable'));
    $this->logger->expects($this->once())->method('error');

    $result = $this->checksum()->getCurrentChecksum(['node:1', 'user:2']);
    $this->assertSame('0', $result);
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidReturnsTrueForEmptyTags(): void {
    $this->assertTrue($this->checksum()->isValid('0', []));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidReturnsFalseForMismatch(): void {
    $this->connection->method('getClient')
      ->willThrowException(new \RuntimeException('ACM unreachable'));

    // Stored checksum is non-zero but current (fail-soft) sum is 0.
    $this->assertFalse($this->checksum()->isValid('5', ['node:1']));
  }

  /**
   * @covers ::invalidateTags
   */
  public function testInvalidateTagsNoopsOnEmptyArray(): void {
    // getClient() must never be called for an empty tag list.
    $this->connection->expects($this->never())->method('getClient');
    $this->checksum()->invalidateTags([]);
  }

  /**
   * @covers ::invalidateTags
   */
  public function testInvalidateTagsSwallowsConnectionException(): void {
    $this->connection->method('getClient')
      ->willThrowException(new \RuntimeException('ACM unreachable'));
    $this->logger->expects($this->once())->method('error');

    // Must not throw.
    $this->checksum()->invalidateTags(['node:1']);
  }

  /**
   * @covers ::reset
   */
  public function testResetIsNoopAndDoesNotThrow(): void {
    $this->connection->expects($this->never())->method('getClient');
    $this->checksum()->reset();
    $this->assertTrue(TRUE);
  }

  /**
   * @covers ::logError
   */
  public function testErrorLoggedOncePerInstanceAcrossMultipleFailures(): void {
    $this->connection->method('getClient')
      ->willThrowException(new \RuntimeException('ACM unreachable'));

    // Only one log entry even though two operations fail.
    $this->logger->expects($this->once())->method('error');

    $checksum = $this->checksum();
    $checksum->getCurrentChecksum(['node:1']);
    $checksum->getCurrentChecksum(['node:2']);
  }

}
