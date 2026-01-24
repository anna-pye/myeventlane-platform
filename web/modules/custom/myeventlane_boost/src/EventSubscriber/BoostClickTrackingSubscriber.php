<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\EventSubscriber;

use Drupal\myeventlane_boost\Service\BoostClickTracker;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event subscriber to track Boost clicks on event canonical pages.
 *
 * Intercepts requests to event pages with boost query parameters
 * and records clicks server-side.
 */
final class BoostClickTrackingSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a BoostClickTrackingSubscriber.
   *
   * @param \Drupal\myeventlane_boost\Service\BoostClickTracker $clickTracker
   *   The click tracker service.
   */
  public function __construct(
    private readonly BoostClickTracker $clickTracker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 100],
    ];
  }

  /**
   * Handles the request event.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event): void {
    // Skip for sub-requests.
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $routeName = $request->attributes->get('_route');

    // Only track on event canonical routes.
    if ($routeName !== 'entity.node.canonical') {
      return;
    }

    $node = $request->attributes->get('node');
    if (!$node instanceof NodeInterface || $node->bundle() !== 'event') {
      return;
    }

    // Only track for published events.
    if (!$node->isPublished()) {
      return;
    }

    // Check for boost query parameters.
    $boostOrderItemId = $request->query->get('boost');
    $placement = $request->query->get('placement');

    if (empty($boostOrderItemId) || empty($placement)) {
      return;
    }

    $boostOrderItemId = (int) $boostOrderItemId;
    $eventId = (int) $node->id();

    // Record the click.
    $this->clickTracker->recordClick($boostOrderItemId, $eventId, (string) $placement);
  }

}
