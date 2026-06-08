<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\StackMiddleware;

use Drupal\myeventlane_event\Service\EventPageViewTracker;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Records event views when internal page cache serves a stored response.
 *
 * Dynamic page cache hits are handled by EventViewTrackingSubscriber after
 * routing; this middleware covers internal page cache hits that bypass the
 * kernel request subscribers entirely.
 */
final class EventViewTrackingMiddleware implements HttpKernelInterface {

  public function __construct(
    private readonly HttpKernelInterface $httpKernel,
    private readonly EventPageViewTracker $eventPageViewTracker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, $type = self::MAIN_REQUEST, $catch = TRUE): Response {
    $response = $this->httpKernel->handle($request, $type, $catch);

    if ($type === self::MAIN_REQUEST) {
      $this->eventPageViewTracker->trackFromPageCacheHit($request, $response);
    }

    return $response;
  }

}
