<?php

namespace Drupal\Tests\aerospike_cache\Kernel;

use Drupal\aerospike_cache\AerospikeCacheTagsChecksum;
use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel tests for AerospikeCacheTagsChecksum.
 *
 * Verifies checksum computation, tag invalidation via batch writes, and
 * checksum lookup via batch reads against a live Aerospike instance.
 *
 * @group aerospike_cache
 * @group aerospike_cache_kernel
 */
class AerospikeCacheTagsChecksumKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['aerospike_cache'];

  /**
   * The checksum provider under test.
   *
   * @var \Drupal\aerospike_cache\AerospikeCacheTagsChecksum
   */
  private AerospikeCacheTagsChecksum $checksum;

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

    // Instantiate directly so the test is unaffected by whether the service
    // provider has replaced cache_tags.invalidator.checksum in the container.
    $this->checksum = new AerospikeCacheTagsChecksum(
      $connection,
      $this->container->get('logger.factory'),
    );
  }

  /**
   * Tests that the checksum of an empty tag set is "0".
   */
  public function testChecksumForEmptyTagsIsZero(): void {
    $this->assertSame('0', $this->checksum->getCurrentChecksum([]));
  }

  /**
   * Tests that getCurrentChecksum() returns a numeric string.
   */
  public function testChecksumIsNumericString(): void {
    $result = $this->checksum->getCurrentChecksum(['node:1']);
    $this->assertIsString($result);
    $this->assertIsNumeric($result);
  }

  /**
   * Tests that the same tags produce the same checksum on repeated calls.
   */
  public function testChecksumIsReproducible(): void {
    $tags = ['node:1', 'user:2'];
    $a    = $this->checksum->getCurrentChecksum($tags);
    $b    = $this->checksum->getCurrentChecksum($tags);

    $this->assertSame($a, $b);
  }

  /**
   * Tests that the joint checksum equals the sum of individual checksums.
   */
  public function testChecksumIsSumOfIndividualTagVersions(): void {
    $tags     = ['node:100', 'node:101', 'node:102'];
    $combined = (int) $this->checksum->getCurrentChecksum($tags);
    $parts    = array_sum(array_map(
      fn($t) => (int) $this->checksum->getCurrentChecksum([$t]),
      $tags,
    ));

    $this->assertSame($parts, $combined);
  }

  /**
   * Tests that isValid() returns TRUE for the current checksum.
   */
  public function testIsValidReturnsTrueForCurrentChecksum(): void {
    $tags     = ['node:200'];
    $checksum = $this->checksum->getCurrentChecksum($tags);

    $this->assertTrue($this->checksum->isValid($checksum, $tags));
  }

  /**
   * Tests that isValid() returns FALSE after a tag is invalidated.
   */
  public function testIsValidReturnsFalseAfterInvalidation(): void {
    $tags     = ['node:' . $this->randomMachineName()];
    $checksum = $this->checksum->getCurrentChecksum($tags);

    $this->checksum->invalidateTags($tags);

    $this->assertFalse($this->checksum->isValid($checksum, $tags));
  }

  /**
   * Tests that invalidateTags() increments the tag counter.
   */
  public function testInvalidationIncrementsChecksum(): void {
    $tags   = ['node:' . $this->randomMachineName()];
    $before = (int) $this->checksum->getCurrentChecksum($tags);

    $this->checksum->invalidateTags($tags);
    $after = (int) $this->checksum->getCurrentChecksum($tags);

    $this->assertGreaterThan($before, $after);
  }

  /**
   * Tests that each invalidation increments the counter monotonically.
   */
  public function testEachInvalidationIncrementsChecksum(): void {
    $tags = ['node:' . $this->randomMachineName()];
    $v1   = (int) $this->checksum->getCurrentChecksum($tags);

    $this->checksum->invalidateTags($tags);
    $v2 = (int) $this->checksum->getCurrentChecksum($tags);

    $this->checksum->invalidateTags($tags);
    $v3 = (int) $this->checksum->getCurrentChecksum($tags);

    $this->assertGreaterThan($v1, $v2);
    $this->assertGreaterThan($v2, $v3);
  }

  /**
   * Tests that invalidating one tag does not affect unrelated tags.
   */
  public function testInvalidatingOneTagDoesNotAffectOthers(): void {
    $tagA   = 'node:' . $this->randomMachineName();
    $tagB   = 'node:' . $this->randomMachineName();
    $before = $this->checksum->getCurrentChecksum([$tagB]);

    $this->checksum->invalidateTags([$tagA]);
    $after = $this->checksum->getCurrentChecksum([$tagB]);

    $this->assertSame($before, $after);
  }

  /**
   * Tests batch invalidation and lookup across 8 tags in a single call each.
   */
  public function testBatchInvalidationAndLookup(): void {
    $tags   = array_map(
      fn($i) => 'test:' . $this->randomMachineName(),
      range(0, 7),
    );
    $before = $this->checksum->getCurrentChecksum($tags);

    $this->checksum->invalidateTags($tags);
    $after = $this->checksum->getCurrentChecksum($tags);

    $this->assertGreaterThan((int) $before, (int) $after);
  }

  /**
   * Tests that reset() does not alter Aerospike counters.
   */
  public function testResetDoesNotAlterCounters(): void {
    $tags = ['node:' . $this->randomMachineName()];
    $this->checksum->invalidateTags($tags);
    $before = $this->checksum->getCurrentChecksum($tags);

    $this->checksum->reset();
    $after = $this->checksum->getCurrentChecksum($tags);

    $this->assertSame($before, $after);
  }

}
