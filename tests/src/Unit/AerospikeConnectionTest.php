<?php

namespace Drupal\Tests\aerospike_cache\Unit;

use Aerospike\Key;
use Drupal\aerospike_cache\AerospikeConnection;
use Drupal\Core\Site\Settings;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\aerospike_cache\AerospikeConnection
 * @group aerospike_cache
 */
class AerospikeConnectionTest extends TestCase {

  /**
   * @covers ::isAvailable
   */
  public function testIsAvailableReturnsFalseWhenSocketMissing(): void {
    $settings   = new Settings(['aerospike_cache_socket' => '/tmp/__no_such_socket__.sock']);
    $connection = new AerospikeConnection($settings);

    $this->assertFalse($connection->isAvailable());
  }

  /**
   * @covers ::isAvailable
   */
  public function testIsAvailableCachesResultOnInstance(): void {
    $settings   = new Settings(['aerospike_cache_socket' => '/tmp/__no_such_socket__.sock']);
    $connection = new AerospikeConnection($settings);

    $first  = $connection->isAvailable();
    $second = $connection->isAvailable();

    $this->assertSame($first, $second);
  }

  /**
   * @covers ::isAvailable
   */
  public function testTwoInstancesProbeIndependently(): void {
    $broken = new Settings(['aerospike_cache_socket' => '/tmp/__no_such_socket__.sock']);
    $conn   = new AerospikeConnection($broken);

    $this->assertFalse($conn->isAvailable());
  }

  /**
   * @covers ::getNamespace
   */
  public function testGetNamespaceReturnsConfiguredValue(): void {
    $settings   = new Settings(['aerospike_cache_namespace' => 'myapp']);
    $connection = new AerospikeConnection($settings);

    $this->assertSame('myapp', $connection->getNamespace());
  }

  /**
   * @covers ::getNamespace
   */
  public function testGetNamespaceDefaultsToDrupal(): void {
    putenv('AEROSPIKE_NAMESPACE');
    $settings   = new Settings([]);
    $connection = new AerospikeConnection($settings);

    $this->assertSame('drupal', $connection->getNamespace());
  }

  /**
   * @covers ::getSocket
   */
  public function testGetSocketReturnsConfiguredValue(): void {
    $settings   = new Settings(['aerospike_cache_socket' => '/var/run/acm.sock']);
    $connection = new AerospikeConnection($settings);

    $this->assertSame('/var/run/acm.sock', $connection->getSocket());
  }

  /**
   * @covers ::getSocket
   */
  public function testGetSocketDefaultsToTmpPath(): void {
    putenv('AEROSPIKE_SOCKET');
    $settings   = new Settings([]);
    $connection = new AerospikeConnection($settings);

    $this->assertSame('/tmp/asld_grpc.sock', $connection->getSocket());
  }

  /**
   * @covers ::makeKey
   */
  public function testMakeKeyReturnsKeyWithCorrectProperties(): void {
    $settings   = new Settings(['aerospike_cache_namespace' => 'testns']);
    $connection = new AerospikeConnection($settings);

    $key = $connection->makeKey('myset', 'mykey');

    $this->assertInstanceOf(Key::class, $key);
    $this->assertSame('testns', $key->namespace);
    $this->assertSame('myset', $key->setname);
    $this->assertSame('mykey', $key->value);
  }

}
