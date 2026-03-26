<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\EventSubscriber;

use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\myeventlane_notifications\Service\PlatformNotificationTriggerService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Commerce-driven notification triggers (queue-only fan-out).
 */
final class NotificationSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly PlatformNotificationTriggerService $triggerService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      OrderEvents::ORDER_PAID => ['onOrderPaid', 0],
    ];
  }

  public function onOrderPaid(OrderEvent $event): void {
    $this->triggerService->onOrderPaid($event->getOrder());
  }

}
