<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_commerce\Service\TicketAvailabilityService;
use Drupal\myeventlane_commerce\Service\TicketBackedOrderItemClassifier;
use Drupal\myeventlane_commerce\Service\TicketTierWaitlistService;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Marks tier waitlist offers accepted when matching orders complete.
 */
final class TicketWaitlistOrderSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TicketAvailabilityService $ticketAvailability,
    private readonly TicketTierWaitlistService $tierWaitlist,
    private readonly LoggerInterface $logger,
    private readonly TicketBackedOrderItemClassifier $ticketBackedOrderItemClassifier,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.place.post_transition' => ['onPlace', -80],
    ];
  }

  public function onPlace(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if (!$order instanceof OrderInterface) {
      return;
    }
    if ($order->getState()->getId() !== 'completed') {
      return;
    }

    $buyer = $order->getCustomerId() ? $this->entityTypeManager->getStorage('user')->load($order->getCustomerId()) : NULL;
    $orderMail = NULL;
    if ($order->hasField('mail') && !$order->get('mail')->isEmpty()) {
      $orderMail = (string) $order->get('mail')->value;
    }

    foreach ($order->getItems() as $item) {
      if ($this->ticketBackedOrderItemClassifier->isOperationalOrderItem($item)) {
        continue;
      }
      if (!$this->ticketBackedOrderItemClassifier->isTicketBackedOrderItem($item)) {
        continue;
      }
      if (!$item->hasField('field_target_event') || $item->get('field_target_event')->isEmpty()) {
        continue;
      }
      $purchased = $item->getPurchasedEntity();
      if (!$purchased instanceof ProductVariationInterface) {
        continue;
      }
      $eventId = (int) $item->get('field_target_event')->target_id;
      $eventNode = $this->entityTypeManager->getStorage('node')->load($eventId);
      if (!$eventNode instanceof \Drupal\node\NodeInterface || $eventNode->bundle() !== 'event') {
        continue;
      }
      $tier = $this->ticketAvailability->resolveTierForVariation($eventNode, $purchased);
      if (!$tier) {
        continue;
      }
      $qty = (int) $item->getQuantity();
      if ($qty < 1) {
        continue;
      }
      $this->tierWaitlist->reconcileCompletedPurchase(
        $eventId,
        (int) $tier->id(),
        $qty,
        $buyer instanceof \Drupal\user\UserInterface ? $buyer : NULL,
        $orderMail,
      );
    }
  }

}
