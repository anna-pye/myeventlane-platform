<?php

declare(strict_types=1);

namespace Drupal\myeventlane_growth\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\myeventlane_growth\Service\GrowthTrackingService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Attributes Boost purchases to recent growth impressions.
 */
final class GrowthCommerceSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly GrowthTrackingService $tracking,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    if (!class_exists(OrderEvents::class)) {
      return [];
    }
    return [
      OrderEvents::ORDER_PAID => 'onOrderPaid',
    ];
  }

  public function onOrderPaid(OrderEvent $event): void {
    $order = $event->getOrder();
    if (!$order instanceof OrderInterface) {
      return;
    }
    $uid = (int) $order->getCustomerId();
    if ($uid <= 0) {
      return;
    }
    foreach ($order->getItems() as $item) {
      if ($item->bundle() !== 'boost') {
        continue;
      }
      $eid = 0;
      if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
        $eid = (int) $item->get('field_target_event')->target_id;
      }
      $this->tracking->recordConversionForPrefixes(
        $uid,
        $eid > 0 ? $eid : NULL,
        'boost_purchase',
        ['boost_'],
        ['order_id' => (int) $order->id()],
      );
    }
  }

}
