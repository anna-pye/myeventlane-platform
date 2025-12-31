<?php

declare(strict_types=1);

namespace Drupal\myeventlane_capacity\EventSubscriber;

use Drupal\commerce_cart\Event\CartEntityAddEvent;
use Drupal\commerce_cart\Event\CartEvents;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_capacity\Exception\CapacityExceededException;
use Drupal\myeventlane_capacity\Service\CapacityOrderInspector;
use Drupal\myeventlane_capacity\Service\EventCapacityService;
use Drupal\node\NodeInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Enforces event capacity at Commerce lifecycle points.
 *
 * Prevents overselling by checking capacity:
 * - When items are added to cart
 * - Before order placement (final gate)
 */
final class CommerceCapacityEnforcementSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructs CommerceCapacityEnforcementSubscriber.
   *
   * @param \Drupal\myeventlane_capacity\Service\EventCapacityService $capacityService
   *   The capacity service.
   * @param \Drupal\myeventlane_capacity\Service\CapacityOrderInspector $orderInspector
   *   The order inspector service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    private readonly EventCapacityService $capacityService,
    private readonly CapacityOrderInspector $orderInspector,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    TranslationInterface $stringTranslation,
    private readonly LoggerInterface $logger,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Enforce capacity when items are added to cart.
      CartEvents::CART_ENTITY_ADD => 'onCartEntityAdd',
      // Final enforcement before order placement.
      'commerce_order.place.pre_transition' => 'onOrderPlacePreTransition',
    ];
  }

  /**
   * Handles cart entity add event.
   *
   * Enforces capacity when items are added to cart. If capacity would be
   * exceeded, prevents the add operation by throwing an exception.
   *
   * @param \Drupal\commerce_cart\Event\CartEntityAddEvent $event
   *   The cart entity add event.
   *
   * @throws \Drupal\myeventlane_capacity\Exception\CapacityExceededException
   *   If capacity would be exceeded.
   */
  public function onCartEntityAdd(CartEntityAddEvent $event): void {
    $order_item = $event->getOrderItem();
    $cart = $event->getCart();

    // Skip non-ticket items.
    if ($this->orderInspector->isNonTicketItem($order_item)) {
      return;
    }

    // Skip if item doesn't have field_target_event.
    if (!$order_item->hasField('field_target_event') || $order_item->get('field_target_event')->isEmpty()) {
      return;
    }

    $event_id = (int) $order_item->get('field_target_event')->target_id;
    if ($event_id <= 0) {
      return;
    }

    // Load the event.
    $event_node = $this->entityTypeManager->getStorage('node')->load($event_id);
    if (!$event_node instanceof NodeInterface || $event_node->bundle() !== 'event') {
      return;
    }

    // Calculate total requested quantity for this event in the cart.
    $event_quantities = $this->orderInspector->extractEventQuantities($cart);
    $requested_total = $event_quantities[$event_id] ?? 0;

    // If requested total is 0, allow (edge case).
    if ($requested_total <= 0) {
      return;
    }

    // Enforce capacity.
    try {
      $this->capacityService->assertCanBook($event_node, $requested_total);
    }
    catch (CapacityExceededException $e) {
      // Log the attempt.
      $this->logger->warning(
        'Capacity enforcement blocked cart add: event @event_id, requested @qty, message: @message',
        [
          '@event_id' => $event_id,
          '@qty' => $requested_total,
          '@message' => $e->getMessage(),
        ]
      );

      // Convert to user-friendly message and rethrow.
      $remaining = $this->capacityService->getRemaining($event_node);
      if ($remaining !== NULL) {
        $message = $this->t('Sorry, only @remaining ticket(s) remaining for this event. Please adjust your quantity.', [
          '@remaining' => $remaining,
        ]);
      }
      else {
        $message = $this->t('This event is sold out. Please try another event.');
      }

      throw new CapacityExceededException($message, 0, $e);
    }
  }

  /**
   * Handles order place pre-transition event.
   *
   * Final capacity enforcement gate before order is placed. This prevents
   * overselling even if cart validation was bypassed or capacity changed
   * between cart and checkout.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The workflow transition event.
   *
   * @throws \Drupal\myeventlane_capacity\Exception\CapacityExceededException
   *   If capacity would be exceeded.
   */
  public function onOrderPlacePreTransition(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if (!$order instanceof OrderInterface) {
      return;
    }

    // Extract event quantities from the order.
    $event_quantities = $this->orderInspector->extractEventQuantities($order);
    if (empty($event_quantities)) {
      // No ticket items, no capacity check needed.
      return;
    }

    // Load all events in bulk.
    $event_ids = array_keys($event_quantities);
    $event_storage = $this->entityTypeManager->getStorage('node');
    $events = $event_storage->loadMultiple($event_ids);

    // Enforce capacity for each event.
    foreach ($event_quantities as $event_id => $requested_total) {
      if ($requested_total <= 0) {
        continue;
      }

      $event_node = $events[$event_id] ?? NULL;
      if (!$event_node instanceof NodeInterface || $event_node->bundle() !== 'event') {
        continue;
      }

      // Check if event has capacity limits (NULL = unlimited).
      $capacity_total = $this->capacityService->getCapacityTotal($event_node);
      if ($capacity_total === NULL) {
        // Unlimited capacity, allow.
        continue;
      }

      // Enforce capacity.
      try {
        $this->capacityService->assertCanBook($event_node, $requested_total);
      }
      catch (CapacityExceededException $e) {
        // Log the blocked order.
        $this->logger->error(
          'Capacity enforcement blocked order placement: order @order_id, event @event_id, requested @qty, message: @message',
          [
            '@order_id' => $order->id(),
            '@event_id' => $event_id,
            '@qty' => $requested_total,
            '@message' => $e->getMessage(),
          ]
        );

        // Convert to user-friendly message.
        $remaining = $this->capacityService->getRemaining($event_node);
        if ($remaining !== NULL && $remaining > 0) {
          $message = $this->t('Sorry, only @remaining ticket(s) remaining for "@event". Please adjust your order.', [
            '@remaining' => $remaining,
            '@event' => $event_node->label(),
          ]);
        }
        else {
          $message = $this->t('Sorry, "@event" is sold out. Please try another event.', [
            '@event' => $event_node->label(),
          ]);
        }

        // Rethrow with user-friendly message.
        throw new CapacityExceededException($message, 0, $e);
      }
    }
  }


}

