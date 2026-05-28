<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\EventSubscriber;

use Drupal\myeventlane_event\Service\PublicEventVisibility;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds X-Robots-Tag noindex headers for non-public events.
 *
 * Applies to unlisted, private, and passcode event pages so they are not
 * indexed by search engines even when the page is accessible.
 */
final class EventVisibilityNoindexSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly PublicEventVisibility $publicVisibility,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => ['onKernelResponse', 0],
    ];
  }

  public function onKernelResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $route_name = (string) $request->attributes->get('_route', '');

    $applicable_routes = [
      'entity.node.canonical',
      'myeventlane_event.passcode_gate',
    ];

    if (!in_array($route_name, $applicable_routes, TRUE)) {
      return;
    }

    $node = $request->attributes->get('node');
    if (!$node instanceof NodeInterface || $node->bundle() !== 'event') {
      return;
    }

    if (!$this->publicVisibility->shouldNoindex($node)) {
      return;
    }

    $event->getResponse()->headers->set(
      'X-Robots-Tag',
      'noindex, nofollow, noarchive, nosnippet'
    );
  }

}
