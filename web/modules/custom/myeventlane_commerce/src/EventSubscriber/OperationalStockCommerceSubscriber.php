<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\EventSubscriber;

use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\Event\CartEmptyEvent;
use Drupal\commerce_cart\Event\CartEntityAddEvent;
use Drupal\commerce_cart\Event\CartEvents;
use Drupal\commerce_cart\Event\CartOrderItemRemoveEvent;
use Drupal\commerce_cart\Event\CartOrderItemUpdateEvent;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\myeventlane_commerce\Exception\OperationalStockUnavailableException;
use Drupal\myeventlane_commerce\Service\OperationalStockHoldManager;
use Drupal\myeventlane_commerce\Service\OperationalStockHoldStoreInterface;
use Drupal\myeventlane_commerce\Service\OperationalStockSaleManager;
use Drupal\myeventlane_commerce\Service\OperationalVariationStockResolver;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps add-on holds aligned with carts and serializes final stock checks.
 */
final class OperationalStockCommerceSubscriber implements EventSubscriberInterface {

  private const PLACEMENT_LOCK_ATTR = 'myeventlane_operational_stock.locks';

  /**
   * Locks retained when no HTTP request is available.
   *
   * @var array<int, string[]>
   */
  private array $cliPlacementLocks = [];

  public function __construct(
    private readonly OperationalStockHoldManager $holdManager,
    private readonly OperationalStockHoldStoreInterface $holdStore,
    private readonly CartManagerInterface $cartManager,
    private readonly RequestStack $requestStack,
    private readonly LoggerInterface $logger,
    private readonly OperationalStockSaleManager $saleManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      CartEvents::CART_ENTITY_ADD => ['onCartChanged', -30],
      CartEvents::CART_ORDER_ITEM_UPDATE => ['onCartItemUpdated', -30],
      CartEvents::CART_ORDER_ITEM_REMOVE => ['onCartItemRemoved', -30],
      CartEvents::CART_EMPTY => ['onCartEmpty', -30],
      OrderEvents::ORDER_PAID => ['onOrderPaid', 100],
      'commerce_order.place.pre_transition' => ['onOrderPlacePreTransition', 60],
      // Commerce Stock writes its sale transaction at priority -100.
      'commerce_order.place.post_transition' => ['onOrderPlacePostTransition', -200],
      'commerce_order.cancel.post_transition' => ['onOrderCancelPostTransition', -210],
      KernelEvents::TERMINATE => ['onKernelTerminate', -210],
    ];
  }

  /**
   * Refreshes holds after an operational variation is added.
   */
  public function onCartChanged(CartEntityAddEvent $event): void {
    $item = $event->getOrderItem();
    $variation = $item->getPurchasedEntity();
    if (!$this->isOperational($variation) || !$variation instanceof ProductVariationInterface) {
      return;
    }
    $cart = $event->getCart();
    $previousQuantity = $this->holdStore->getActiveQuantity((int) $cart->id(), (int) $variation->id());
    try {
      $this->refreshOrThrow($cart);
    }
    catch (UnprocessableEntityHttpException $e) {
      if ($previousQuantity > 0) {
        $item->setQuantity((string) $previousQuantity);
        $item->save();
        $this->holdManager->refresh($cart);
      }
      else {
        $this->cartManager->removeOrderItem($cart, $item);
      }
      throw $e;
    }
  }

  /**
   * Refreshes holds after an operational line quantity changes.
   */
  public function onCartItemUpdated(CartOrderItemUpdateEvent $event): void {
    if (!$this->isOperational($event->getOrderItem()->getPurchasedEntity())) {
      return;
    }
    try {
      $this->refreshOrThrow($event->getCart());
    }
    catch (UnprocessableEntityHttpException $e) {
      $event->getOrderItem()
        ->setQuantity($event->getOriginalOrderItem()->getQuantity())
        ->save();
      $this->holdManager->refresh($event->getCart());
      throw $e;
    }
  }

  /**
   * Refreshes remaining holds after an operational line is removed.
   */
  public function onCartItemRemoved(CartOrderItemRemoveEvent $event): void {
    if ($this->isOperational($event->getOrderItem()->getPurchasedEntity())) {
      $this->holdManager->refresh($event->getCart());
    }
  }

  /**
   * Releases operational holds when a cart is emptied.
   */
  public function onCartEmpty(CartEmptyEvent $event): void {
    $this->holdManager->release($event->getCart());
  }

  /**
   * Locks and revalidates operational stock immediately before placement.
   */
  public function onOrderPlacePreTransition(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if (!$order instanceof OrderInterface) {
      return;
    }
    if ($this->saleManager->isEnabled()) {
      if ($order->isPaid()) {
        $this->saleManager->commitPaid($order);
      }
      else {
        // Placed is not paid. Keep only a time-limited reservation.
        $this->holdManager->refresh($order);
      }
      return;
    }

    try {
      $locks = $this->holdManager->lockAndValidatePlacement($order);
    }
    catch (OperationalStockUnavailableException $e) {
      $this->logger->warning('Operational stock blocked placement for order @order: @message', [
        '@order' => (string) $order->id(),
        '@message' => $e->getMessage(),
      ]);
      throw new UnprocessableEntityHttpException($e->getMessage(), $e);
    }
    if ($locks === []) {
      return;
    }

    $request = $this->requestStack->getCurrentRequest();
    if ($request !== NULL) {
      $request->attributes->set(self::PLACEMENT_LOCK_ATTR, $locks);
      return;
    }
    $this->cliPlacementLocks[(int) $order->id()] = $locks;
  }

  /**
   * Releases holds and locks after Commerce Stock records the sale.
   */
  public function onOrderPlacePostTransition(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if (!$order instanceof OrderInterface) {
      return;
    }
    if ($this->saleManager->isEnabled()) {
      return;
    }
    // Release only after Commerce Stock has recorded its sale transaction.
    $this->holdManager->release($order);
    $this->releasePlacementLocks($order);
  }

  /**
   * Commits stock before any paid extras entitlement can be issued.
   */
  public function onOrderPaid(OrderEvent $event): void {
    $this->saleManager->commitPaid($event->getOrder());
  }

  /**
   * Releases stale holds and locks after order cancellation.
   */
  public function onOrderCancelPostTransition(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if ($order instanceof OrderInterface) {
      $this->holdManager->release($order);
      $this->releasePlacementLocks($order);
    }
  }

  /**
   * Releases placement locks if a later subscriber aborts the request.
   */
  public function onKernelTerminate(TerminateEvent $event): void {
    $locks = $event->getRequest()->attributes->get(self::PLACEMENT_LOCK_ATTR, []);
    if (is_array($locks)) {
      $this->holdManager->releaseLocks($locks);
    }
    $event->getRequest()->attributes->remove(self::PLACEMENT_LOCK_ATTR);
  }

  /**
   * Converts stock reservation failures into a customer-safe response.
   */
  private function refreshOrThrow(OrderInterface $cart): void {
    try {
      $this->holdManager->refresh($cart);
    }
    catch (OperationalStockUnavailableException $e) {
      throw new UnprocessableEntityHttpException($e->getMessage(), $e);
    }
  }

  /**
   * Checks whether a purchasable entity is a managed operational variation.
   */
  private function isOperational(mixed $purchasedEntity): bool {
    return $purchasedEntity instanceof ProductVariationInterface
      && in_array($purchasedEntity->bundle(), OperationalVariationStockResolver::OPERATIONAL_VARIATION_BUNDLES, TRUE);
  }

  /**
   * Releases HTTP or command-line placement locks for an order.
   */
  private function releasePlacementLocks(OrderInterface $order): void {
    $request = $this->requestStack->getCurrentRequest();
    if ($request !== NULL) {
      $locks = $request->attributes->get(self::PLACEMENT_LOCK_ATTR, []);
      if (is_array($locks)) {
        $this->holdManager->releaseLocks($locks);
      }
      $request->attributes->remove(self::PLACEMENT_LOCK_ATTR);
    }

    $orderId = (int) $order->id();
    if (isset($this->cliPlacementLocks[$orderId])) {
      $this->holdManager->releaseLocks($this->cliPlacementLocks[$orderId]);
      unset($this->cliPlacementLocks[$orderId]);
    }
  }

}
