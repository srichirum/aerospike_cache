# Aerospike Cache

Provides [Aerospike](https://aerospike.com) as a high-performance cache backend
for Drupal. Supports cache tag invalidation, automatic database failover, and
connection pooling via the Aerospike Connection Manager (ACM).

## Requirements

- Drupal 11 or later
- PHP 8.3 or later
- [aerospike/php-client](https://github.com/aerospike/php-client) PHP extension
  (Rust-based, compiled from source)
- Aerospike Connection Manager (ACM) daemon running and accessible via Unix
  socket (bundled in the aerospike/php-client repository)

## Architecture

PHP communicates with the ACM daemon over a local gRPC Unix socket. The ACM
manages TCP connection pooling to the remote Aerospike cluster, keeping PHP
processes stateless.

```
Drupal → AerospikeCacheBackend → ACM (Unix socket) → Aerospike cluster
```

Each Drupal cache bin maps to an Aerospike set within the configured namespace.
Cache tag invalidation is handled by atomically incrementing per-tag counters
in a dedicated `cachetags` set. Checksums are computed as the sum of those
counters, matching the semantics of Drupal's database cache tag implementation.

## Installation

1. Build and install the `aerospike/php-client` PHP extension and enable it in
   `php.ini`:

   ```ini
   extension=libaerospike_php.so
   ```

2. Run and configure the ACM daemon so it can reach your Aerospike cluster.
   By default the ACM writes its socket to `/tmp/asld_grpc.sock`.

3. Enable the module:

   ```bash
   drush en aerospike_cache
   ```

4. Add the following to `settings.php`:

   ```php
   // ACM socket path and Aerospike namespace.
   $settings['aerospike_cache_socket']    = '/tmp/asld_grpc.sock';
   $settings['aerospike_cache_namespace'] = 'drupal';

   if (extension_loaded('aerospike_php')) {
     // Use aerospike_failover for automatic database fallback (recommended),
     // or aerospike for Aerospike-only with fail-soft error handling.
     $settings['cache']['default'] = 'cache.backend.aerospike_failover';

     // Optional: use APCu as a fast in-process tier for bins read on every
     // request. Requires the APCu PHP extension.
     $settings['cache']['bootstrap'] = 'cache.backend.chained_fast';
     $settings['cache']['config']    = 'cache.backend.chained_fast';
     $settings['cache']['discovery'] = 'cache.backend.chained_fast';
   }
   ```

## Cache backends

### `cache.backend.aerospike`

Direct Aerospike backend. All cache operations fail soft — reads return FALSE
on error (treated as a miss), writes are silently dropped. Use this if you
prefer to handle availability concerns at the infrastructure level.

### `cache.backend.aerospike_failover`

Aerospike primary with automatic database fallback. On each request the ACM
socket is probed once. If unreachable, all cache operations for that request
transparently route to the database backend. Recovery is automatic on the next
request. Recommended for production.

## Configuration reference

All settings are read from Drupal's `$settings` array. Environment variables
are used as fallbacks where noted.

| Setting | Default | Environment variable |
|---|---|---|
| `aerospike_cache_socket` | `/tmp/asld_grpc.sock` | `AEROSPIKE_SOCKET` |
| `aerospike_cache_namespace` | `drupal` | `AEROSPIKE_NAMESPACE` |
| `aerospike_cache_prefix` | `''` (none) | `AEROSPIKE_PREFIX` |
| `aerospike_cache_max_record_size` | `1000000` | — |
| `aerospike_cache_compress_threshold` | `4096` | — |
| `aerospike_cache_debug` | `FALSE` | — |

## Large items and record size limits

Aerospike caps each record at `max-record-size` (default **1 MiB**). Some Drupal
cache items — large plugin/discovery data, big render arrays — exceed this. The
module handles oversized items in three stages:

1. **Compression.** Any payload whose serialized size exceeds
   `aerospike_cache_compress_threshold` is gzip-compressed (and base64-encoded,
   since the client only accepts UTF-8 strings in a bin). Drupal cache data
   typically compresses 5–10×, so most large items fit comfortably.
2. **Splitting.** If an item is still over `aerospike_cache_max_record_size`
   after compression, it is split into chunk records (stored in the same set,
   so bulk clears still remove them) with a parent record referencing them.
   Reads reassemble the chunks; if a chunk has been evicted, the read is a
   clean cache miss. Items split into at most 32 chunks.
3. **Size guard.** Only an item that would need more than 32 chunks is skipped
   — not cached — with a single warning logged per request. The item is simply
   recomputed on the next request; the site never errors.

If your Aerospike cluster is configured with a larger `max-record-size` (up to
its 8 MiB ceiling), raise `aerospike_cache_max_record_size` to match so the
module makes full use of it:

```
# In the namespace stanza of aerospike.conf:
namespace drupal {
  ...
  max-record-size 8M
}
```

```php
// settings.php — must not exceed the cluster's configured max-record-size.
$settings['aerospike_cache_max_record_size'] = 8000000;
```

## Locks

The module provides an Aerospike-backed lock backend for Drupal's locking
subsystem (`\Drupal::lock()`). Acquisition uses Aerospike's atomic create-only
write, and every lock carries a TTL equal to its timeout, so a crashed process
cannot deadlock the system — the lock is evicted automatically.

It is not enabled by default. To route Drupal's lock service to Aerospike, add
to your site's `services.yml`:

```yaml
services:
  lock:
    class: Drupal\Core\Lock\LockBackendInterface
    factory: ['@aerospike_cache.lock.factory', 'get']
```

## Multi-site key prefixing

To let several Drupal sites share one Aerospike namespace without colliding,
give each site a distinct prefix. It is prepended to every key (cache and lock).

```php
$settings['aerospike_cache_prefix'] = 'siteA:';
```

## Status report (Aerospike Cache Admin)

Enable the `aerospike_cache_admin` submodule for a diagnostics page at
**Reports → Aerospike cache** (`/admin/reports/aerospike`). It shows:

- Connection health, socket, namespace, prefix, and extension status
- Configured record-size and compression limits
- A live write/read/delete round-trip latency probe
- Per-bin record counts

> Aerospike server-internal statistics (memory, evictions, hit ratio) are
> reached over the info protocol, which the PHP client does not expose through
> the ACM socket — so the report covers what is reachable from the application.

## Per-request diagnostics (hit/miss visibility)

To see, on a per-page basis, whether a response came out of Aerospike, enable
debug mode. There are two ways, and the first overrides the second:

1. **settings.php** — defining the flag hard-forces the state for the whole
   environment (useful to keep production off no matter what):

   ```php
   $settings['aerospike_cache_debug'] = TRUE;  // or FALSE to lock it off
   ```

2. **Admin toggle** — with the `aerospike_cache_admin` submodule, a checkbox at
   **Reports → Aerospike cache** turns diagnostics on or off. It is stored in
   State and takes effect on the next request with no deployment or cache
   rebuild. When settings.php defines the flag, this checkbox is shown disabled.

This is a development aid and a no-op when off, so it carries no measurable
overhead in production. When on, two things appear:

**Response headers** — added to every response (HTML and headless JSON:API
alike), visible in browser dev tools → Network:

```
X-Aerospike-Cache: HIT            # page-level result: HIT / MISS / BYPASS
X-Aerospike-Cache-Ops: get=1 hit=1 miss=0 set=0
X-Aerospike-Cache-Time: 1.96ms
```

The header is written by a stack middleware that wraps the page cache, so on an
anonymous page-cache HIT it reports the live result (one Aerospike read) rather
than a stale figure baked into the cached response.

**Toolbar tab** — with the `aerospike_cache_admin` submodule and the core
Toolbar module enabled, an "Aerospike" tab shows the same page-level result plus
a live op count and latency, and its tray breaks the activity down per bin. The
tab is placeholdered, so it stays accurate even when the rest of the page is
served from the dynamic page cache.

> The figures count Aerospike I/O only. The `bootstrap`, `config` and
> `discovery` bins use an APCu fast tier (ChainedFastBackend); once APCu is warm
> those reads never reach Aerospike and so are not counted — which is the
> intended behaviour, not a gap. `BYPASS` means Aerospike was unreachable and
> the request was served from the database fallback.

## Cache tag invalidation

Cache tag invalidation is handled entirely in Aerospike. Each tag has a counter
record in the `cachetags` set. Invalidating a tag increments its counter
atomically. On the next read, the stored checksum on the cache item no longer
matches the current counter sum, and the item is treated as invalid.

This module replaces Drupal's core `cache_tags.invalidator.checksum` service so
that both writes (invalidations) and reads (checksum lookups) use the same
Aerospike counters. Without this replacement, tag invalidations would write to
the database while the Aerospike backend reads its own counters, causing stale
items to persist indefinitely.

## Maintainers

- Sriharsha Chirumamilla
