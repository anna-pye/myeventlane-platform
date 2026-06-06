<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_commerce\Service\OrderItemClassifier;
use Drupal\myeventlane_commerce\Service\StripeConnectPaymentService;
use Drupal\myeventlane_commerce\Service\TicketBackedOrderItemClassifierInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\myeventlane_core\Service\StripeService;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\StripeConnectPaymentService
 *
 * @group myeventlane_commerce
 */
final class StripeConnectPaymentServiceTest extends UnitTestCase {

  /**
   * @covers ::calculateTicketRevenue
   */
  public function testCalculateTicketRevenueIncludesTicketBackedItemsOnly(): void {
    $ticket_item = $this->orderItem('default', '50.00');
    $merch_item = $this->orderItem('default', '20.00');
    $donation_item = $this->orderItem('checkout_donation', '10.00');
    $boost_item = $this->orderItem('boost', '5.00');

    $order = $this->createMock(OrderInterface::class);
    $order->method('getItems')->willReturn([$ticket_item, $merch_item, $donation_item, $boost_item]);

    $service = $this->service([$ticket_item]);
    $this->assertSame(5000, $service->calculateTicketRevenue($order));
  }

  /**
   * @covers ::calculateOrganiserDonationRevenue
   */
  public function testCalculateOrganiserDonationRevenueIncludesOrganiserDonationsOnly(): void {
    $checkout_donation = $this->orderItem('checkout_donation', '12.50');
    $rsvp_donation = $this->orderItem('rsvp_donation', '7.50');
    $platform_donation = $this->orderItem('platform_donation', '20.00');
    $ticket_item = $this->orderItem('default', '50.00');

    $order = $this->createMock(OrderInterface::class);
    $order->method('getItems')->willReturn([
      $checkout_donation,
      $rsvp_donation,
      $platform_donation,
      $ticket_item,
    ]);

    $service = $this->service([]);
    $this->assertSame(2000, $service->calculateOrganiserDonationRevenue($order));
  }

  /**
   * @covers ::calculateTicketRevenueCentsForEvent
   */
  public function testCalculateTicketRevenueCentsForEventScopesTicketBackedItems(): void {
    $ticket_item = $this->orderItem('default', '40.00', 42);
    $other_event_ticket = $this->orderItem('default', '30.00', 99);
    $addon_item = $this->orderItem('default', '15.00', 42);

    $order = $this->createMock(OrderInterface::class);
    $order->method('getItems')->willReturn([$ticket_item, $other_event_ticket, $addon_item]);

    $service = $this->service([$ticket_item]);
    $this->assertSame(4000, $service->calculateTicketRevenueCentsForEvent($order, 42));
  }

  /**
   * @covers ::calculateVendorTransferAmount
   * @covers ::getConnectPaymentIntentParams
   */
  public function testGetConnectPaymentIntentParamsIncludesOrganiserDonationsInTransfer(): void {
    $ticket_item = $this->orderItem('default', '50.00');
    $donation_item = $this->orderItem('checkout_donation', '10.00');

    $store = $this->storeWithAccount('acct_ticket_vendor');
    $order = $this->createMock(OrderInterface::class);
    $order->method('getItems')->willReturn([$ticket_item, $donation_item]);
    $order->method('getStore')->willReturn($store);
    $order->method('id')->willReturn('101');

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['stripe_fee_percent', 3],
      ['stripe_fee_fixed_cents', 30],
    ]);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('myeventlane_core.settings')->willReturn($config);

    $service = $this->service([$ticket_item], config_factory: $config_factory);
    $params = $service->getConnectPaymentIntentParams($order);

    $this->assertSame(6000, $params['transfer_data']['amount']);
    $this->assertSame('acct_ticket_vendor', $params['transfer_data']['destination']);
    $this->assertSame(180, $params['application_fee_amount']);
  }

  /**
   * @covers ::getStripeAccountIdForOrder
   */
  public function testGetStripeAccountIdForOrganiserDonationOnlyOrderUsesEventChain(): void {
    $store = $this->storeWithAccount('acct_organiser_donation');
    $event = $this->eventWithVendorStore($store);
    $donation_item = $this->orderItem('rsvp_donation', '15.00', 42, $event);

    $entity_type_manager = $this->entityTypeManagerForEvent($event);
    $order = $this->createMock(OrderInterface::class);
    $order->method('getItems')->willReturn([$donation_item]);
    $order->method('getStore')->willReturn($this->storeWithAccount('acct_platform_default'));

    $service = $this->service([], $entity_type_manager);
    $this->assertSame('acct_organiser_donation', $service->getStripeAccountIdForOrder($order));
  }

  /**
   * @covers ::getStripeAccountIdForOrder
   * @covers ::getConnectPaymentIntentParams
   */
  public function testPlatformDonationOnlyOrderDoesNotUseConnect(): void {
    $platform_donation = $this->orderItem('platform_donation', '5.00');
    $order = $this->createMock(OrderInterface::class);
    $order->method('getItems')->willReturn([$platform_donation]);
    $order->method('getStore')->willReturn($this->storeWithAccount('acct_should_not_use'));

    $service = $this->service([]);
    $this->assertNull($service->getStripeAccountIdForOrder($order));
    $this->assertSame([], $service->getConnectPaymentIntentParams($order));
  }

  /**
   * @covers ::getConnectPaymentIntentParams
   */
  public function testGetConnectPaymentIntentParamsForDonationOnlyOrganiserOrder(): void {
    $store = $this->storeWithAccount('acct_rsvp_only');
    $event = $this->eventWithVendorStore($store);
    $donation_item = $this->orderItem('rsvp_donation', '15.00', 42, $event);

    $order = $this->createMock(OrderInterface::class);
    $order->method('getItems')->willReturn([$donation_item]);
    $order->method('getStore')->willReturn($this->storeWithAccount('acct_platform_default'));
    $order->method('id')->willReturn('202');

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['stripe_fee_percent', 3],
      ['stripe_fee_fixed_cents', 30],
    ]);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('myeventlane_core.settings')->willReturn($config);

    $service = $this->service([], $this->entityTypeManagerForEvent($event), $config_factory);
    $params = $service->getConnectPaymentIntentParams($order);

    $this->assertSame(1500, $params['transfer_data']['amount']);
    $this->assertSame('acct_rsvp_only', $params['transfer_data']['destination']);
    $this->assertSame(0, $params['application_fee_amount']);
  }

  /**
   * @covers ::calculateVendorTransferAmount
   */
  public function testCalculateVendorTransferAmountCombinesTicketAndOrganiserDonations(): void {
    $ticket_item = $this->orderItem('default', '40.00');
    $donation_item = $this->orderItem('checkout_donation', '5.00');
    $platform_donation = $this->orderItem('platform_donation', '3.00');

    $order = $this->createMock(OrderInterface::class);
    $order->method('getItems')->willReturn([$ticket_item, $donation_item, $platform_donation]);

    $service = $this->service([$ticket_item]);
    $this->assertSame(4500, $service->calculateVendorTransferAmount($order));
  }

  /**
   * @param list<\Drupal\commerce_order\Entity\OrderItemInterface> $ticket_backed_items
   */
  private function service(
    array $ticket_backed_items,
    ?EntityTypeManagerInterface $entity_type_manager = NULL,
    ?ConfigFactoryInterface $config_factory = NULL,
  ): StripeConnectPaymentService {
    $ticket_backed_ids = [];
    foreach ($ticket_backed_items as $item) {
      $ticket_backed_ids[spl_object_id($item)] = TRUE;
    }

    $classifier = $this->createMock(TicketBackedOrderItemClassifierInterface::class);
    $classifier->method('isTicketBackedOrderItem')->willReturnCallback(
      static fn (OrderItemInterface $item): bool => !empty($ticket_backed_ids[spl_object_id($item)]),
    );

    return new StripeConnectPaymentService(
      $entity_type_manager ?? $this->createMock(EntityTypeManagerInterface::class),
      new StripeService(
        $this->createMock(ConfigFactoryInterface::class),
        $this->createMock(EntityTypeManagerInterface::class),
        $this->createMock(LoggerChannelFactoryInterface::class),
      ),
      $config_factory ?? $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(LoggerInterface::class),
      new OrderItemClassifier(),
      $classifier,
    );
  }

  private function storeWithAccount(string $account_id): StoreInterface {
    $stripe_field = new StripeConnectTestFieldItemList(0, NULL, $account_id);
    $store = $this->createMock(StoreInterface::class);
    $store->method('hasField')->willReturnMap([
      ['field_stripe_account_id', TRUE],
    ]);
    $store->method('get')->willReturnMap([
      ['field_stripe_account_id', $stripe_field],
    ]);
    return $store;
  }

  private function eventWithVendorStore(StoreInterface $store): NodeInterface {
    $vendor_store_field = new StripeConnectTestFieldItemList(0, $store);
    $vendor = $this->createMock(ContentEntityInterface::class);
    $vendor->method('hasField')->willReturnMap([
      ['field_vendor_store', TRUE],
    ]);
    $vendor->method('get')->willReturnMap([
      ['field_vendor_store', $vendor_store_field],
    ]);

    $vendor_field = new StripeConnectTestFieldItemList(0, $vendor);
    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn('event');
    $event->method('hasField')->willReturnMap([
      ['field_event_vendor', TRUE],
    ]);
    $event->method('get')->willReturnMap([
      ['field_event_vendor', $vendor_field],
    ]);
    return $event;
  }

  private function entityTypeManagerForEvent(NodeInterface $event): EntityTypeManagerInterface {
    $node_storage = $this->createMock(EntityStorageInterface::class);
    $node_storage->method('load')->willReturn($event);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('hasDefinition')->with('myeventlane_vendor')->willReturn(FALSE);
    $entity_type_manager->method('getStorage')->willReturnMap([
      ['node', $node_storage],
    ]);
    return $entity_type_manager;
  }

  private function orderItem(
    string $bundle,
    string $amount,
    ?int $event_id = NULL,
    ?NodeInterface $event = NULL,
  ): OrderItemInterface {
    $item = $this->createMock(OrderItemInterface::class);
    $item->method('bundle')->willReturn($bundle);
    $item->method('getTotalPrice')->willReturn(new Price($amount, 'AUD'));

    if ($event_id !== NULL) {
      $target_event = new StripeConnectTestFieldItemList($event_id, $event);
      $item->method('hasField')->willReturnMap([
        ['field_target_event', TRUE],
      ]);
      $item->method('get')->willReturnMap([
        ['field_target_event', $target_event],
      ]);
    }
    else {
      $item->method('hasField')->willReturn(FALSE);
    }

    return $item;
  }

}

/**
 * Minimal field list stub for entity reference fields in unit tests.
 */
final class StripeConnectTestFieldItemList {

  public function __construct(
    public readonly int $target_id,
    public readonly ?object $entity = NULL,
    public readonly ?string $value = NULL,
  ) {}

  public function isEmpty(): bool {
    if ($this->value !== NULL) {
      return trim($this->value) === '';
    }
    return $this->target_id <= 0 && $this->entity === NULL;
  }

}
