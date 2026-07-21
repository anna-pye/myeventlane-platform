<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_refunds\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_refunds\Service\RefundAccessResolver;
use Drupal\myeventlane_refunds\Service\RefundOrderItemsInterface;
use Drupal\myeventlane_refunds\Service\StoreEventOwnershipInterface;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for refund financial ownership (parity AND store).
 *
 * @coversDefaultClass \Drupal\myeventlane_refunds\Service\RefundAccessResolver
 *
 * @group myeventlane_refunds
 */
final class RefundAccessResolverOwnershipTest extends TestCase {

  /**
   * Event author with parity, manage permission, and valid store/order is allowed.
   */
  public function testAuthorWithParityAndValidStoreAllowedToRefund(): void {
    $resolver = $this->resolver(
      hasParity: TRUE,
      storeOwnsEvent: TRUE,
      accountStore: $this->store(1),
      orderItemsForEvent: [$this->createMock(OrderItemInterface::class)],
      orderStoreOwnsEvent: TRUE,
    );
    $account = $this->account(10, ['manage_refunds' => TRUE]);
    $this->assertTrue($resolver->vendorCanRefundOrderForEvent($this->order('completed'), $this->event(), $account));
  }

  /**
   * Vendor entity owner with parity and valid store is allowed.
   */
  public function testVendorOwnerWithParityAndValidStoreAllowed(): void {
    $resolver = $this->resolver(
      hasParity: TRUE,
      storeOwnsEvent: TRUE,
      accountStore: $this->store(1),
      orderItemsForEvent: [$this->createMock(OrderItemInterface::class)],
      orderStoreOwnsEvent: TRUE,
    );
    $account = $this->account(20, ['manage_refunds' => TRUE]);
    $this->assertTrue($resolver->vendorCanManageEvent($this->event(), $account));
    $this->assertTrue($resolver->vendorCanRefundOrderForEvent($this->order('completed'), $this->event(), $account));
  }

  /**
   * Legitimate team member with parity and valid store is allowed.
   */
  public function testTeamMemberWithParityAndValidStoreAllowed(): void {
    $resolver = $this->resolver(
      hasParity: TRUE,
      storeOwnsEvent: TRUE,
      accountStore: $this->store(1),
      orderItemsForEvent: [$this->createMock(OrderItemInterface::class)],
      orderStoreOwnsEvent: TRUE,
    );
    $account = $this->account(30, ['manage_refunds' => TRUE]);
    $this->assertTrue($resolver->vendorCanRefundOrderForEvent($this->order('completed'), $this->event(), $account));
  }

  /**
   * Unrelated organiser is denied.
   */
  public function testUnrelatedOrganiserDenied(): void {
    $resolver = $this->resolver(
      hasParity: FALSE,
      storeOwnsEvent: TRUE,
      accountStore: $this->store(2),
      orderItemsForEvent: [$this->createMock(OrderItemInterface::class)],
      orderStoreOwnsEvent: TRUE,
    );
    $account = $this->account(40, ['manage_refunds' => TRUE]);
    $this->assertFalse($resolver->vendorCanManageEvent($this->event(), $account));
    $this->assertFalse($resolver->vendorCanRefundOrderForEvent($this->order('completed'), $this->event(), $account));
  }

  /**
   * Store owner without workspace parity is denied.
   */
  public function testStoreOwnerWithoutParityDenied(): void {
    $resolver = $this->resolver(
      hasParity: FALSE,
      storeOwnsEvent: TRUE,
      accountStore: $this->store(1),
      orderItemsForEvent: [$this->createMock(OrderItemInterface::class)],
      orderStoreOwnsEvent: TRUE,
    );
    $account = $this->account(50, ['manage_refunds' => TRUE]);
    $this->assertFalse($resolver->vendorCanManageEvent($this->event(), $account));
    $this->assertFalse($resolver->vendorCanRefundOrderForEvent($this->order('completed'), $this->event(), $account));
  }

  /**
   * Workspace parity with a foreign store relationship is denied.
   */
  public function testParityWithForeignStoreDenied(): void {
    $resolver = $this->resolver(
      hasParity: TRUE,
      storeOwnsEvent: FALSE,
      accountStore: $this->store(99),
      orderItemsForEvent: [$this->createMock(OrderItemInterface::class)],
      orderStoreOwnsEvent: TRUE,
    );
    $account = $this->account(10, ['manage_refunds' => TRUE]);
    $this->assertFalse($resolver->vendorCanManageEvent($this->event(), $account));
    $this->assertFalse($resolver->vendorCanRefundOrderForEvent($this->order('completed'), $this->event(), $account));
  }

  /**
   * Parity without a resolvable store is denied.
   */
  public function testParityWithoutStoreDenied(): void {
    $resolver = $this->resolver(
      hasParity: TRUE,
      storeOwnsEvent: FALSE,
      accountStore: NULL,
      orderItemsForEvent: [$this->createMock(OrderItemInterface::class)],
      orderStoreOwnsEvent: TRUE,
    );
    $account = $this->account(10, ['manage_refunds' => TRUE]);
    $this->assertFalse($resolver->vendorCanManageEvent($this->event(), $account));
  }

  /**
   * Order with no items for the event is denied.
   */
  public function testOrderWithoutEventItemsDenied(): void {
    $resolver = $this->resolver(
      hasParity: TRUE,
      storeOwnsEvent: TRUE,
      accountStore: $this->store(1),
      orderItemsForEvent: [],
      orderStoreOwnsEvent: TRUE,
    );
    $account = $this->account(10, ['manage_refunds' => TRUE]);
    $this->assertFalse($resolver->vendorCanRefundOrderForEvent($this->order('completed'), $this->event(), $account));
  }

  /**
   * Order whose store does not own the event is denied.
   */
  public function testOrderForeignStoreDenied(): void {
    $accountStore = $this->store(1);
    $orderStore = $this->store(2);

    $storeOwnership = $this->createMock(StoreEventOwnershipInterface::class);
    $storeOwnership->method('getStoreForUser')->willReturn($accountStore);
    $storeOwnership->method('vendorOwnsEvent')->willReturnCallback(
      static function (StoreInterface $store) use ($accountStore): bool {
        return (int) $store->id() === (int) $accountStore->id();
      }
    );

    $inspector = $this->createMock(RefundOrderItemsInterface::class);
    $inspector->method('extractItemsForEvent')->willReturn([
      $this->createMock(OrderItemInterface::class),
    ]);

    $checker = $this->createMock(EventVendorAccessCheckerInterface::class);
    $checker->method('accountHasWorkspaceParityForEvent')->willReturn(TRUE);

    $resolver = new RefundAccessResolver($storeOwnership, $inspector, $checker);

    $state = new class() {

      /**
       * Returns the state ID.
       */
      public function getId(): string {
        return 'completed';
      }

    };
    $order = $this->createMock(OrderInterface::class);
    $order->method('getState')->willReturn($state);
    $order->method('getStore')->willReturn($orderStore);

    $account = $this->account(10, ['manage_refunds' => TRUE]);
    $this->assertFalse($resolver->vendorCanRefundOrderForEvent($order, $this->event(), $account));
  }

  /**
   * Missing order store fails closed.
   */
  public function testMissingOrderStoreDenied(): void {
    $storeOwnership = $this->createMock(StoreEventOwnershipInterface::class);
    $storeOwnership->method('getStoreForUser')->willReturn($this->store(1));
    $storeOwnership->method('vendorOwnsEvent')->willReturn(TRUE);

    $inspector = $this->createMock(RefundOrderItemsInterface::class);
    $inspector->method('extractItemsForEvent')->willReturn([
      $this->createMock(OrderItemInterface::class),
    ]);

    $checker = $this->createMock(EventVendorAccessCheckerInterface::class);
    $checker->method('accountHasWorkspaceParityForEvent')->willReturn(TRUE);

    $resolver = new RefundAccessResolver($storeOwnership, $inspector, $checker);
    $order = $this->order('completed', hasStore: FALSE);
    $account = $this->account(10, ['manage_refunds' => TRUE]);
    $this->assertFalse($resolver->vendorCanRefundOrderForEvent($order, $this->event(), $account));
  }

  /**
   * Admin bypass remains for manage and refund.
   */
  public function testAdminBypassRetained(): void {
    $resolver = $this->resolver(
      hasParity: FALSE,
      storeOwnsEvent: FALSE,
      accountStore: NULL,
      orderItemsForEvent: [$this->createMock(OrderItemInterface::class)],
      orderStoreOwnsEvent: TRUE,
    );
    $account = $this->account(1, ['administer commerce_order' => TRUE]);
    $this->assertTrue($resolver->vendorCanManageEvent($this->event(), $account));
    $this->assertTrue($resolver->vendorCanRefundOrderForEvent($this->order('completed'), $this->event(), $account));
  }

  /**
   * Manage permission alone without manage_refunds cannot refund.
   */
  public function testManageWithoutRefundPermissionDenied(): void {
    $resolver = $this->resolver(
      hasParity: TRUE,
      storeOwnsEvent: TRUE,
      accountStore: $this->store(1),
      orderItemsForEvent: [$this->createMock(OrderItemInterface::class)],
      orderStoreOwnsEvent: TRUE,
    );
    $account = $this->account(10, []);
    $this->assertTrue($resolver->vendorCanManageEvent($this->event(), $account));
    $this->assertFalse($resolver->vendorCanRefundOrderForEvent($this->order('completed'), $this->event(), $account));
  }

  /**
   * Builds a resolver with stubbed dependencies.
   *
   * @param bool $hasParity
   *   Whether workspace parity holds.
   * @param bool $storeOwnsEvent
   *   Whether the account store owns the event.
   * @param \Drupal\commerce_store\Entity\StoreInterface|null $accountStore
   *   Account-resolvable store, or NULL.
   * @param list<object> $orderItemsForEvent
   *   Items returned for the event.
   * @param bool $orderStoreOwnsEvent
   *   Whether non-account stores own the event.
   */
  private function resolver(
    bool $hasParity,
    bool $storeOwnsEvent,
    ?StoreInterface $accountStore,
    array $orderItemsForEvent,
    bool $orderStoreOwnsEvent,
  ): RefundAccessResolver {
    $storeOwnership = $this->createMock(StoreEventOwnershipInterface::class);
    $storeOwnership->method('getStoreForUser')->willReturn($accountStore);
    $storeOwnership->method('vendorOwnsEvent')->willReturnCallback(
      static function (StoreInterface $store, NodeInterface $event) use ($accountStore, $storeOwnsEvent, $orderStoreOwnsEvent): bool {
        if ($accountStore && (int) $store->id() === (int) $accountStore->id()) {
          return $storeOwnsEvent;
        }
        return $orderStoreOwnsEvent;
      }
    );

    $inspector = $this->createMock(RefundOrderItemsInterface::class);
    $inspector->method('extractItemsForEvent')->willReturn($orderItemsForEvent);

    $checker = $this->createMock(EventVendorAccessCheckerInterface::class);
    $checker->method('accountHasWorkspaceParityForEvent')->willReturn($hasParity);

    return new RefundAccessResolver($storeOwnership, $inspector, $checker);
  }

  /**
   * Builds a stub account.
   *
   * @param int $id
   *   Account UID.
   * @param array<string, bool> $permissions
   *   Permission map.
   */
  private function account(int $id, array $permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($id);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $perm): bool => !empty($permissions[$perm])
    );
    return $account;
  }

  /**
   * Builds a stub event node.
   */
  private function event(): NodeInterface {
    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn('event');
    $event->method('id')->willReturn(100);
    return $event;
  }

  /**
   * Builds a stub store.
   *
   * @param int $id
   *   Store ID.
   */
  private function store(int $id): StoreInterface {
    $store = $this->createMock(StoreInterface::class);
    $store->method('id')->willReturn($id);
    return $store;
  }

  /**
   * Builds a stub order.
   *
   * @param string $stateId
   *   Order workflow state ID.
   * @param bool $hasStore
   *   Whether the order has a store.
   */
  private function order(string $stateId, bool $hasStore = TRUE): OrderInterface {
    $state = new class($stateId) {

      /**
       * Constructs the state stub.
       */
      public function __construct(private readonly string $id) {}

      /**
       * Returns the state ID.
       */
      public function getId(): string {
        return $this->id;
      }

    };

    $order = $this->createMock(OrderInterface::class);
    $order->method('getState')->willReturn($state);
    if ($hasStore) {
      $order->method('getStore')->willReturn($this->store(1));
    }
    else {
      $order->method('getStore')->willReturn(NULL);
    }
    return $order;
  }

}
