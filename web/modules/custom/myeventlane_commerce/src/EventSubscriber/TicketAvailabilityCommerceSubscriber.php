<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\EventSubscriber;

use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_cart\Event\CartEmptyEvent;
use Drupal\commerce_cart\Event\CartEntityAddEvent;
use Drupal\commerce_cart\Event\CartEvents;
use Drupal\commerce_cart\Event\CartOrderItemRemoveEvent;
use Drupal\commerce_cart\Event\CartOrderItemUpdateEvent;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_capacity\Exception\CapacityExceededException;
use Drupal\myeventlane_capacity\Service\CapacityOrderInspector;
use Drupal\myeventlane_commerce\Service\CartTicketHoldManager;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Tier rules, event capacity, and placement lock (acquire + release) in one module.
 */
final class TicketAvailabilityCommerceSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  private const PLACEMENT_LOCK_ATTR = 'myeventlane_capacity.placement_lock';

  private const LOCK_TIMEOUT = 30;

  public function __construct(
    private readonly CartTicketHoldManager $cartTicketHold,
    private readonly CapacityOrderInspector $orderInspector,
    private readonly LockBackendInterface $lock,
    private readonly RequestStack $requestStack,
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
      CartEvents::CART_ENTITY_ADD => ['onCartEntityAdd', -20],
      CartEvents::CART_ORDER_ITEM_UPDATE => ['onCartOrderItemUpdate', -20],
      CartEvents::CART_ORDER_ITEM_REMOVE => ['onCartOrderItemRemove', -20],
      CartEvents::CART_EMPTY => ['onCartEmpty', -20],
      'commerce_order.place.pre_transition' => ['onOrderPlacePreTransition', 0],
      'commerce_order.place.post_transition' => ['onOrderPlacePostTransition', 0],
    ];
  }

  /**
   * Releases placement lock after successful order transition.
   */
  public function onOrderPlacePostTransition(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if ($order instanceof OrderInterface) {
      $this->cartTicketHold->releaseItems($order, $order->getItems());
    }

    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return;
    }
    $lock_name = $request->attributes->get(self::PLACEMENT_LOCK_ATTR);
    if ($lock_name) {
      $this->lock->release($lock_name);
      $request->attributes->remove(self::PLACEMENT_LOCK_ATTR);
    }
  }

  /**
   * Starts or refreshes the reservation after a ticket is added.
   */
  public function onCartEntityAdd(CartEntityAddEvent $event): void {
    $order_item = $event->getOrderItem();
    $cart = $event->getCart();

    if (!$this->shouldValidateAsTicket($order_item)) {
      return;
    }

    $this->refreshCartOrThrow($cart, 'add');
  }

  /**
   * Refreshes the hold after a ticket quantity changes.
   */
  public function onCartOrderItemUpdate(CartOrderItemUpdateEvent $event): void {
    if (!$this->shouldValidateAsTicket($event->getOrderItem())) {
      return;
    }
    try {
      $this->refreshCartOrThrow($event->getCart(), 'update');
    }
    catch (UnprocessableEntityHttpException $e) {
      // Commerce saves the order item before dispatching this event. Restore
      // the last valid quantity so a rejected increase cannot persist.
      $event->getOrderItem()
        ->setQuantity($event->getOriginalOrderItem()->getQuantity())
        ->save();
      throw $e;
    }
  }

  /**
   * Shrinks or releases the event hold after a ticket line is removed.
   */
  public function onCartOrderItemRemove(CartOrderItemRemoveEvent $event): void {
    $removed = $event->getOrderItem();
    if (!$this->shouldValidateAsTicket($removed)) {
      return;
    }

    $cart = $event->getCart();
    $eventId = (int) $removed->get('field_target_event')->target_id;
    $remaining = $this->orderInspector->extractEventQuantities($cart);
    if (!isset($remaining[$eventId])) {
      $this->cartTicketHold->releaseEvent($cart, $eventId);
      return;
    }

    try {
      $this->cartTicketHold->refresh($cart);
    }
    catch (CapacityExceededException $e) {
      // A removal must remain possible. Release the stale hold and require an
      // explicit availability check before checkout instead of blocking it.
      $this->cartTicketHold->releaseEvent($cart, $eventId);
      $this->logger->warning(
        'Ticket hold expired while cart @cart was reduced for event @event: @message',
        [
          '@cart' => (string) $cart->id(),
          '@event' => (string) $eventId,
          '@message' => $e->getMessage(),
        ],
      );
    }
  }

  /**
   * Releases all holds when Commerce empties the cart.
   */
  public function onCartEmpty(CartEmptyEvent $event): void {
    $this->cartTicketHold->releaseItems($event->getCart(), $event->getOrderItems());
  }

  /**
   * Acquires the order placement lock before Commerce changes state.
   */
  public function onOrderPlacePreTransition(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if (!$order instanceof OrderInterface) {
      return;
    }

    $event_totals = $this->orderInspector->extractEventQuantities($order);
    if (empty($event_totals)) {
      return;
    }

    $lock_name = 'myeventlane_checkout:place_order:' . $order->id();
    if (!$this->lock->acquire($lock_name, self::LOCK_TIMEOUT)) {
      $this->logger->warning(
        'Order placement lock not acquired for order @order_id (possible duplicate submit)',
        ['@order_id' => $order->id()]
      );
      throw new \RuntimeException(
        (string) $this->t('Your order is already being processed. Please wait a moment before trying again.')
      );
    }

    $request = $this->requestStack->getCurrentRequest();
    if ($request) {
      $request->attributes->set(self::PLACEMENT_LOCK_ATTR, $lock_name);
    }
    // Per-event capacity validation runs in TicketCapacityOrderSubscriber (priority 50).
  }

  /**
   * Whether ticket availability rules apply to this cart line.
   */
  private function shouldValidateAsTicket(OrderItemInterface $order_item): bool {
    if ($this->orderInspector->isNonTicketItem($order_item)) {
      return FALSE;
    }
    if (!$order_item->hasField('field_target_event') || $order_item->get('field_target_event')->isEmpty()) {
      return FALSE;
    }
    $purchased = $order_item->get('purchased_entity')->entity;
    if (!$purchased instanceof PurchasableEntityInterface) {
      return FALSE;
    }
    return !$this->isOperationalAddonVariation($purchased);
  }

  /**
   * Refreshes all cart ticket holds and presents a safe Commerce exception.
   */
  private function refreshCartOrThrow(OrderInterface $cart, string $operation): void {
    try {
      $this->cartTicketHold->refresh($cart);
    }
    catch (CapacityExceededException $e) {
      $this->logger->warning(
        'Ticket availability blocked cart @operation for cart @cart: @message',
        [
          '@operation' => $operation,
          '@cart' => (string) $cart->id(),
          '@message' => $e->getMessage(),
        ],
      );
      throw new UnprocessableEntityHttpException($e->getMessage(), $e);
    }
  }

  /**
   * Operational add-ons are event-scoped but not ticket matrix variations.
   */
  private function isOperationalAddonVariation(PurchasableEntityInterface $entity): bool {
    if (!$entity instanceof ProductVariationInterface) {
      return FALSE;
    }
    $product = $entity->getProduct();
    if ($product === NULL) {
      return FALSE;
    }
    return OperationalMerchandiseManager::isOperationalProductBundle($product->bundle());
  }

}
