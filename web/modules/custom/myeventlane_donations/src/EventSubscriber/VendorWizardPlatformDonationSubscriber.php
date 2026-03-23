<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_donations\Service\VendorEventMelSupportService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Marks event wizard platform donation checkout complete when order is paid.
 *
 * Reuses OrderEvents::ORDER_PAID; no custom Stripe handling.
 */
final class VendorWizardPlatformDonationSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly VendorEventMelSupportService $vendorEventMelSupportService,
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
    $order = $event->getOrder();
    if (!$order instanceof OrderInterface || $order->bundle() !== 'platform_donation') {
      return;
    }

    foreach ($order->getItems() as $item) {
      if ($item->bundle() !== 'platform_donation') {
        continue;
      }
      if (!$item->hasField('field_attendee_data') || $item->get('field_attendee_data')->isEmpty()) {
        continue;
      }
      $raw = (string) $item->get('field_attendee_data')->value;
      $decoded = json_decode($raw, TRUE);
      if (!is_array($decoded) || ($decoded['source'] ?? '') !== 'event_wizard') {
        continue;
      }
      $eventNid = 0;
      if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
        $eventNid = (int) $item->get('field_target_event')->target_id;
      }
      elseif (!empty($decoded['event_id'])) {
        $eventNid = (int) $decoded['event_id'];
      }
      if ($eventNid < 1) {
        continue;
      }
      $node = $this->entityTypeManager->getStorage('node')->load($eventNid);
      if ($node instanceof NodeInterface && $node->bundle() === 'event') {
        $this->vendorEventMelSupportService->markEventCheckoutCompleted($node);
      }
      return;
    }
  }

}
