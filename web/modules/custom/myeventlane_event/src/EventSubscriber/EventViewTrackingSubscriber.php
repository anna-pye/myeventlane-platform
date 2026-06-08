<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\EventSubscriber;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Http\MelKernelAuthRouteSilencer;
use Drupal\myeventlane_core\Service\AnalyticsService;
use Drupal\myeventlane_event\Service\PublicEventVisibility;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records event page views on canonical event routes.
 *
 * Runs after the passcode gate subscriber so redirects are not counted.
 */
final class EventViewTrackingSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AnalyticsService $analytics,
    private readonly PublicEventVisibility $publicVisibility,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 20],
    ];
  }

  /**
   * Tracks a view when the event full page is actually served.
   */
  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || $event->hasResponse()) {
      return;
    }

    $request = $event->getRequest();
    if (MelKernelAuthRouteSilencer::shouldBypassAuthAccountRoutes($request)) {
      return;
    }

    if ((string) $request->attributes->get('_route', '') !== 'entity.node.canonical') {
      return;
    }

    $node = $request->attributes->get('node');
    if (!$node instanceof NodeInterface || $node->bundle() !== 'event' || !$node->isPublished()) {
      return;
    }

    if (!$this->publicVisibility->isDirectlyViewable($node, $this->currentUser, $request)) {
      return;
    }

    $this->analytics->track('node', (int) $node->id(), AnalyticsService::EVENT_EVENT_VIEW);
  }

}
