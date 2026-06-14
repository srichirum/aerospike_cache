<?php

namespace Drupal\aerospike_cache\StackMiddleware;

use Drupal\aerospike_cache\AerospikeCacheStats;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Adds X-Aerospike-Cache diagnostic headers to responses.
 *
 * Implemented as a stack middleware that wraps the page cache (priority above
 * http_middleware.page_cache) rather than a kernel response subscriber. This
 * matters: a subscriber runs inside the kernel, so on an anonymous page-cache
 * HIT it never executes, and any headers it sets on a MISS get stored in the
 * cached response and replayed — showing stale, misleading figures.
 *
 * Running outside the page cache, this middleware executes on every request,
 * including HITs. Because the page cache reads its bin through the instrumented
 * Aerospike backend, the request-scoped collector has already recorded that
 * lookup by the time control returns here — so even a HIT reports the correct
 * page result and op count, written fresh and never cached.
 *
 * Active only when the `aerospike_cache_debug` setting is on; otherwise it adds
 * no headers and the wrapped kernel is returned untouched.
 */
class AerospikeCacheStatsMiddleware implements HttpKernelInterface {

  /**
   * Constructs the middleware.
   *
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $httpKernel
   *   The wrapped HTTP kernel (the rest of the middleware stack).
   * @param \Drupal\aerospike_cache\AerospikeCacheStats $stats
   *   The request-scoped stats collector.
   */
  public function __construct(
    protected HttpKernelInterface $httpKernel,
    protected AerospikeCacheStats $stats,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, $type = self::MAIN_REQUEST, $catch = TRUE): Response {
    $response = $this->httpKernel->handle($request, $type, $catch);

    if ($this->stats->isEnabled() && $type === self::MAIN_REQUEST) {
      $totals = $this->stats->getTotals();
      $result = $this->stats->getPageResult();
      if ($result !== NULL) {
        $response->headers->set('X-Aerospike-Cache', $result);
      }
      $response->headers->set('X-Aerospike-Cache-Ops', sprintf(
        'get=%d hit=%d miss=%d set=%d',
        $totals['gets'], $totals['hits'], $totals['misses'], $totals['sets'],
      ));
      $response->headers->set('X-Aerospike-Cache-Time', $totals['ms'] . 'ms');
    }

    return $response;
  }

}
