<?php

namespace Drupal\aerospike_cache;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Replaces Drupal's cache tag checksum provider with the Aerospike one.
 *
 * Drupal's cache tag system uses a single service — cache_tags.invalidator
 * .checksum — as both the writer (incremented on invalidation) and the reader
 * (consulted by cache backends to detect stale items). If this service is not
 * replaced, tag invalidations write to the database while the Aerospike backend
 * reads its own counters, causing stale cache items to never be evicted.
 *
 * This provider replaces the service class in place so that core's existing
 * wiring (the cache_tags_invalidator tag and CacheTagsChecksumInterface alias)
 * continues pointing at the same service ID, now backed by Aerospike.
 *
 * Replacement only occurs when the aerospike_php extension is loaded and
 * Aerospike is configured as a cache backend, leaving non-Aerospike
 * environments unaffected.
 */
class AerospikeCacheServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    // Only take over when the Aerospike extension is actually present...
    if (!extension_loaded('aerospike_php')) {
      return;
    }

    // ...and only when Aerospike is actually serving as a cache backend.
    $cache = Settings::get('cache', []);
    $uses_aerospike = str_contains($cache['default'] ?? '', 'aerospike')
      || !empty(array_filter($cache['bins'] ?? [], fn($v) => str_contains($v, 'aerospike')));
    if (!$uses_aerospike) {
      return;
    }

    if ($container->hasDefinition('cache_tags.invalidator.checksum')) {
      $definition = $container->getDefinition('cache_tags.invalidator.checksum');
      $definition->setClass(AerospikeCacheTagsChecksum::class);
      $definition->setArguments([
        new Reference('aerospike_cache.connection'),
        // Lazy service closure, not a direct reference: keeps the logger graph
        // out of compile-time resolution so a site logger that depends on a
        // cache backend cannot form a circular dependency through the checksum.
        new ServiceClosureArgument(new Reference('logger.factory')),
      ]);
    }
  }

}
