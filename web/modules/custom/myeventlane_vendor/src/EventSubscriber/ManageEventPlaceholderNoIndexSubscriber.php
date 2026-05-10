<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds crawler directives for manage-event placeholder surfaces.
 *
 * Complements per-response meta tags from the placeholder controller so
 * intermediaries that only honour HTTP headers still suppress indexing.
 */
final class ManageEventPlaceholderNoIndexSubscriber implements EventSubscriberInterface {

  /**
   * Routes rendered by ManageEventPlaceholderController.
   *
   * @var list<string>
   */
  private const PLACEHOLDER_ROUTE_NAMES = [
    'myeventlane_vendor.manage_event.promote',
    'myeventlane_vendor.manage_event.payments',
    'myeventlane_vendor.manage_event.comms',
    'myeventlane_vendor.manage_event.advanced',
  ];

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => ['onResponse', -10],
    ];
  }

  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $route_name = $this->routeMatch->getRouteName();
    if ($route_name === NULL || !in_array($route_name, self::PLACEHOLDER_ROUTE_NAMES, TRUE)) {
      return;
    }
    $event->getResponse()->headers->set('X-Robots-Tag', 'noindex, nofollow', FALSE);
  }

}
