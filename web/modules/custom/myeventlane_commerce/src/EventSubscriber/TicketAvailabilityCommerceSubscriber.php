<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\EventSubscriber;

use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_cart\Event\CartEntityAddEvent;
use Drupal\commerce_cart\Event\CartEvents;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_capacity\Exception\CapacityExceededException;
use Drupal\myeventlane_capacity\Service\CapacityOrderInspector;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\myeventlane_commerce\Service\TicketAvailabilityService;
use Drupal\node\NodeInterface;
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
    private readonly TicketAvailabilityService $ticketAvailability,
    private readonly CapacityOrderInspector $orderInspector,
    private readonly EntityTypeManagerInterface $entityTypeManager,
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
      'commerce_order.place.pre_transition' => ['onOrderPlacePreTransition', 0],
      'commerce_order.place.post_transition' => ['onOrderPlacePostTransition', 0],
    ];
  }

  /**
   * Releases placement lock after successful order transition.
   */
  public function onOrderPlacePostTransition(WorkflowTransitionEvent $event): void {
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

  public function onCartEntityAdd(CartEntityAddEvent $event): void {
    $order_item = $event->getOrderItem();
    $cart = $event->getCart();

    if (!$this->shouldValidateAsTicket($order_item)) {
      return;
    }

    $purchased = $order_item->get('purchased_entity')->entity;
    if (!$purchased instanceof ProductVariationInterface) {
      return;
    }

    $event_id = (int) $order_item->get('field_target_event')->target_id;
    if ($event_id < 1) {
      return;
    }

    $event_node = $this->entityTypeManager->getStorage('node')->load($event_id);
    if (!$event_node instanceof NodeInterface || $event_node->bundle() !== 'event') {
      return;
    }

    $product = $purchased->getProduct();
    if (!$product) {
      return;
    }

    $by_variation = $this->orderInspector->extractEventVariationQuantities($cart);
    $line_qty = $by_variation[$event_id][$purchased->id()] ?? 0;
    if ($line_qty <= 0) {
      return;
    }

    $event_totals = $this->orderInspector->extractEventQuantities($cart);
    $requested_total = $event_totals[$event_id] ?? 0;

    try {
      $this->ticketAvailability->assertPaidLineAndEventTotal(
        $event_node,
        $product,
        $purchased,
        $line_qty,
        $requested_total
      );
    }
    catch (CapacityExceededException $e) {
      $this->logger->warning(
        'Ticket availability blocked cart add: event @eid variation @vid — @msg',
        [
          '@eid' => (string) $event_id,
          '@vid' => (string) $purchased->id(),
          '@msg' => $e->getMessage(),
        ]
      );
      throw new UnprocessableEntityHttpException($e->getMessage(), $e);
    }
  }

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
