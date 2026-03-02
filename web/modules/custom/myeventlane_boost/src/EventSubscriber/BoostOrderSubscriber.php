<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\EventSubscriber;

use Drupal\commerce_cart\Event\CartEntityAddEvent;
use Drupal\commerce_cart\Event\CartEvents;
use Drupal\commerce_cart\Event\OrderItemComparisonFieldsEvent;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\myeventlane_boost\Service\BoostEntitlementManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Event subscriber for Commerce order events related to boosts.
 */
final class BoostOrderSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a BoostOrderSubscriber.
   *
   * @param \Drupal\myeventlane_boost\Service\BoostEntitlementManager $entitlementManager
   *   The entitlement manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(
    private readonly BoostEntitlementManager $entitlementManager,
    private readonly LoggerInterface $logger,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Apply boost when order is paid.
      OrderEvents::ORDER_PAID => 'onOrderPaid',
      // Attach target event when item is added to cart.
      CartEvents::CART_ENTITY_ADD => 'onCartEntityAdd',
      // Ensure boost items with different target events aren't merged.
      CartEvents::ORDER_ITEM_COMPARISON_FIELDS => 'onComparisonFields',
    ];
  }

  /**
   * Handle ORDER_PAID event.
   *
   * @param \Drupal\commerce_order\Event\OrderEvent $event
   *   The order event.
   */
  public function onOrderPaid(OrderEvent $event): void {
    $order = $event->getOrder();

    $this->logger->notice('ORDER_PAID for order @id', ['@id' => $order->id()]);
    foreach ($order->getItems() as $item) {
      $this->entitlementManager->activateEntitlementFromOrderItem($order, $item);
    }
  }

  /**
   * Handle cart entity add event.
   *
   * Attaches the target event ID to boost order items when added to cart.
   *
   * @param \Drupal\commerce_cart\Event\CartEntityAddEvent $event
   *   The cart entity add event.
   */
  public function onCartEntityAdd(CartEntityAddEvent $event): void {
    $item = $event->getOrderItem();

    if (!$this->entitlementManager->isBoostOrderItem($item)) {
      return;
    }

    if (!$item->hasField('field_target_event')) {
      $this->logger->warning('Boost order item missing field_target_event at add-to-cart (bundle misconfigured?).');
      return;
    }

    // If target event not already set, try to get from query parameter.
    if (!$item->get('field_target_event')->target_id) {
      $request = $this->requestStack->getCurrentRequest();
      $nid = (int) ($request?->query->get('event') ?? 0);

      if ($nid > 0) {
        $item->set('field_target_event', ['target_id' => $nid]);
        $this->logger->info('Attached target event @nid to boost order item (cart add).', ['@nid' => $nid]);
      }
    }
  }

  /**
   * Handle order item comparison fields event.
   *
   * Ensures boost items for different events are not merged in cart.
   *
   * @param \Drupal\commerce_cart\Event\OrderItemComparisonFieldsEvent $event
   *   The comparison fields event.
   */
  public function onComparisonFields(OrderItemComparisonFieldsEvent $event): void {
    $item = $event->getOrderItem();

    if (!$this->entitlementManager->isBoostOrderItem($item)) {
      return;
    }

    $fields = $event->getComparisonFields();

    // Add field_target_event to comparison fields so items for different
    // events are not merged together.
    if ($item->hasField('field_target_event')) {
      $fields[] = 'field_target_event';
    }

    $event->setComparisonFields(array_unique($fields));
  }

}
