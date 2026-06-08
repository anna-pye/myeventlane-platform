<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\EventSubscriber;

use Drupal\myeventlane_event\Service\EventPageViewTracker;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records event page views on canonical event routes.
 *
 * Runs on CONTROLLER (after routing and param conversion) so the event node is
 * available on the request. REQUEST-phase listeners run before Drupal routing.
 */
final class EventViewTrackingSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly EventPageViewTracker $eventPageViewTracker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::CONTROLLER => ['onKernelController', 0],
    ];
  }

  /**
   * Tracks a view when the event full page controller is about to run.
   */
  public function onKernelController(ControllerEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $this->eventPageViewTracker->trackFromKernelRequest($event->getRequest());
  }

}
