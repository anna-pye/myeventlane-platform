<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Http\MelKernelAuthRouteSilencer;
use Drupal\myeventlane_core\Service\AnalyticsService;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records canonical event page views for vendor analytics.
 */
final class EventPageViewTracker {

  /**
   * Page cache header set by Drupal core on internal page cache hits.
   */
  private const PAGE_CACHE_HEADER = 'X-Drupal-Cache';

  public function __construct(
    private readonly AnalyticsService $analytics,
    private readonly PublicEventVisibility $publicVisibility,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Tracks a view during the kernel request phase (after routing).
   */
  public function trackFromKernelRequest(Request $request, ?Response $response = NULL): void {
    $this->trackIfViewableEventPage($request, $response);
  }

  /**
   * Tracks a view when internal page cache serves a stored response.
   */
  public function trackFromPageCacheHit(Request $request, Response $response): void {
    if ($response->headers->get(self::PAGE_CACHE_HEADER) !== 'HIT') {
      return;
    }

    $this->trackIfViewableEventPage($request, $response);
  }

  /**
   * Tracks a view when the canonical event page is actually delivered.
   */
  private function trackIfViewableEventPage(Request $request, ?Response $response = NULL): void {
    if (MelKernelAuthRouteSilencer::shouldBypassAuthAccountRoutes($request)) {
      return;
    }

    if ($response !== NULL) {
      if ($response->isRedirection() || $response->getStatusCode() >= 400) {
        return;
      }
    }

    $node = $this->resolveEventNode($request);
    if (!$node instanceof NodeInterface || !$node->isPublished()) {
      return;
    }

    if (!$this->publicVisibility->isDirectlyViewable($node, $this->currentUser, $request)) {
      return;
    }

    $this->analytics->track('node', (int) $node->id(), AnalyticsService::EVENT_EVENT_VIEW);
  }

  /**
   * Resolves the event node from routed request attributes only.
   *
   * Routing must have completed; never call the router here — that can
   * initialise router.request_context before the request stack is ready.
   */
  private function resolveEventNode(Request $request): ?NodeInterface {
    if ((string) $request->attributes->get('_route', '') !== 'entity.node.canonical') {
      return NULL;
    }

    $node = $request->attributes->get('node');
    if (!$node instanceof NodeInterface || $node->bundle() !== 'event') {
      return NULL;
    }

    return $node;
  }

}
