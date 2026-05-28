<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_store\Entity\Store;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\myeventlane_tickets\Kernel\Traits\RegistersTicketBackedClassifierStubTrait;
use Drupal\myeventlane_messaging\EventSubscriber\OrderPaidConfirmationPdfRecoverySubscriber;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\OperationalIntegrityInspector;
use Drupal\myeventlane_tickets\Ticket\TicketIssuer;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\OperationalIntegrityInspector
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class OperationalIntegrityInspectorTest extends KernelTestBase {

  use RegistersTicketBackedClassifierStubTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'link',
    'path',
    'path_alias',
    'views',
    'address',
    'node',
    'options',
    'datetime',
    'datetime_range',
    'commerce',
    'commerce_number_pattern',
    'commerce_price',
    'commerce_order',
    'commerce_product',
    'commerce_store',
    'entity_reference_revisions',
    'paragraphs',
    'profile',
    'state_machine',
    'mel_ticket',
    'myeventlane_vendor',
    'myeventlane_tickets',
    'myeventlane_wallet',
  ];

  private Node $event;

  private User $customer;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);

    foreach (array_keys($container->getDefinitions()) as $service_id) {
      if (str_starts_with($service_id, 'myeventlane_vendor.') && $service_id !== 'myeventlane_vendor.event_access_checker') {
        $container->removeDefinition($service_id);
      }
    }

    $container->register('myeventlane_vendor.event_access_checker', EventVendorAccessChecker::class);
    $container->register('myeventlane_onboarding.manager', \stdClass::class);
    $container->register('myeventlane_core.vendor_follow', \stdClass::class);
    $container->register('myeventlane_event_studio.save', \stdClass::class);
    $container->register('myeventlane_legal.gatekeeper', \stdClass::class);
    $container->register('myeventlane_core.domain_detector', \stdClass::class);
    $container->register('myeventlane_analytics.order_item_classifier', \stdClass::class);
    $container->register('myeventlane_core.entity_id_normalizer', \stdClass::class);
    $container->register('myeventlane_boost.manager', \stdClass::class);
    $this->registerTicketBackedClassifierStub($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('commerce_currency');
    $this->installEntitySchema('profile');
    $this->installEntitySchema('commerce_store');
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('myeventlane_ticket');
    $this->installConfig(['commerce_store', 'commerce_product', 'commerce_order']);

    NodeType::create([
      'type' => 'event',
      'name' => 'Event',
    ])->save();
    $this->createEventFields();
    $this->ensureProductEventField();

    if (!OrderItemType::load('default')) {
      OrderItemType::create([
        'id' => 'default',
        'label' => 'Default',
        'purchasableEntityType' => 'commerce_product_variation',
        'orderType' => 'default',
      ])->save();
    }
    if (!OrderType::load('default')) {
      OrderType::create([
        'id' => 'default',
        'label' => 'Default',
        'workflow' => 'order_default',
      ])->save();
    }
    if (!Currency::load('AUD')) {
      Currency::create([
        'currencyCode' => 'AUD',
        'name' => 'Australian Dollar',
        'numericCode' => '036',
        'symbol' => '$',
        'fractionDigits' => 2,
      ])->save();
    }

    $store_type_storage = $this->container->get('entity_type.manager')->getStorage('commerce_store_type');
    if (!$store_type_storage->load('online')) {
      $store_type_storage->create(['id' => 'online', 'label' => 'Online'])->save();
    }
    $store = Store::create([
      'type' => 'online',
      'name' => 'Inspector Store',
      'mail' => 'store@example.test',
      'default_currency' => 'AUD',
      'status' => 1,
    ]);
    $store->save();

    $this->customer = User::create([
      'name' => 'inspector_customer',
      'mail' => 'inspector@example.test',
      'status' => 1,
    ]);
    $this->customer->save();

    $this->event = Node::create([
      'type' => 'event',
      'title' => 'Inspector Event',
      'uid' => $this->customer->id(),
      'status' => 1,
      'field_event_start' => '2026-08-01T10:00:00',
      'field_event_end' => '2026-08-01T12:00:00',
      'field_venue_name' => 'Hall A',
      'field_event_vendor' => $this->customer->id(),
    ]);
    $this->event->save();

    $product = Product::create([
      'type' => 'default',
      'title' => 'Inspector Product',
      'stores' => [$store],
      'field_event' => $this->event->id(),
    ]);
    $product->save();

    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => 'INSPECTOR-SKU',
      'title' => 'GA',
      'product_id' => $product->id(),
      'price' => new Price('25.00', 'AUD'),
      'status' => 1,
    ]);
    $variation->save();

    $order = Order::create([
      'type' => 'default',
      'store_id' => $store->id(),
      'state' => 'completed',
      'uid' => $this->customer->id(),
      'mail' => 'inspector@example.test',
      'placed' => time(),
    ]);
    $order->save();

    $item = OrderItem::create([
      'type' => 'default',
      'purchased_entity' => $variation,
      'quantity' => 2,
      'unit_price' => new Price('25.00', 'AUD'),
      'order_id' => $order->id(),
    ]);
    $item->save();
    $order->addItem($item);
    $order->save();
  }

  /**
   * @covers ::inspectOrder
   */
  public function testCanonicalIssuanceValid(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $diag = $this->inspector()->inspectOrder($order);
    $this->assertSame('valid', $diag['issuance']['quantity_alignment_status']);
    $this->assertSame(2, $diag['issuance']['expected_quantity']);
    $this->assertSame(2, $diag['issuance']['issued_ticket_count']);
    $this->assertArrayHasKey('fulfillment_operational_signals', $diag);
    $this->assertArrayHasKey('by_ticket_id', $diag['fulfillment_operational_signals']);
  }

  /**
   * @covers ::inspectOrder
   */
  public function testDuplicateIssuancePreventionObservable(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $this->issuer()->issueForOrder($order);
    $diag = $this->inspector()->inspectOrder($order);
    $this->assertTrue($diag['issuance']['idempotent_replay_would_skip']);
    $this->assertSame(2, $diag['issuance']['issued_ticket_count']);
  }

  /**
   * @covers ::inspectOrder
   */
  public function testOrphanTicketDetection(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $tickets = $this->loadTicketsForOrder((int) $order->id());
    $this->assertNotEmpty($tickets);
    $tickets[0]->set('order_item_id', NULL);
    $tickets[0]->save();
    $diag = $this->inspector()->inspectOrder($order);
    $this->assertGreaterThanOrEqual(1, $diag['issuance']['orphan_ticket_count']);
  }

  /**
   * @covers ::inspectOrder
   */
  public function testOrphanEligibleOrderItemDetection(): void {
    $order = $this->loadOrder();
    $diag = $this->inspector()->inspectOrder($order);
    $this->assertSame(2, $diag['issuance']['expected_quantity']);
    $this->assertSame(0, $diag['issuance']['issued_ticket_count']);
    $this->assertGreaterThanOrEqual(1, $diag['issuance']['orphan_eligible_order_item_count']);
  }

  /**
   * @covers ::inspectOrder
   */
  public function testCanonicalArtifactDetection(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    foreach ($this->loadTicketsForOrder((int) $order->id()) as $ticket) {
      $ticket->set('holder_name', 'Pat');
      $ticket->set('holder_email', 'pat@example.test');
      $ticket->save();
    }
    $diag = $this->inspector()->inspectOrder($order);
    $this->assertSame('valid', $diag['artifacts']['canonical_pdf_readiness']);
    $this->assertSame('valid', $diag['artifacts']['attachment_continuity']);
    $this->assertSame('valid', $diag['artifacts']['qr_payload_operational']);
    $this->assertArrayHasKey('entitlement_capability_policy', $diag['artifacts']);
    $this->assertArrayHasKey('venue_operation_policy', $diag['artifacts']);
    $this->assertArrayHasKey('timed_entry_policy', $diag['artifacts']);
    $this->assertArrayHasKey('session_entitlement_policy', $diag['artifacts']);
    $this->assertArrayHasKey('zone_access_topology', $diag['artifacts']);
    $this->assertArrayHasKey('operational_identity', $diag['artifacts']);
    $this->assertArrayHasKey('operational_continuity', $diag['artifacts']);
    $this->assertArrayHasKey('occupancy_policy', $diag['artifacts']);
    foreach ($this->loadTicketsForOrder((int) $order->id()) as $ticket) {
      $id = (string) $ticket->id();
      $this->assertArrayHasKey($id, $diag['artifacts']['timed_entry_policy']);
      $this->assertArrayHasKey($id, $diag['artifacts']['session_entitlement_policy']);
      $this->assertArrayHasKey($id, $diag['artifacts']['zone_access_topology']);
      $this->assertArrayHasKey($id, $diag['artifacts']['operational_identity']);
      $this->assertArrayHasKey($id, $diag['artifacts']['operational_continuity']);
      $this->assertArrayHasKey($id, $diag['artifacts']['occupancy_policy']);
      $oc = $diag['artifacts']['operational_continuity'][$id];
      $this->assertArrayHasKey('continuity_summary', $oc);
      $this->assertArrayHasKey('deterministic_continuity_descriptor', $oc);
      $op_occ = $diag['artifacts']['occupancy_policy'][$id];
      $this->assertArrayHasKey('occupancy_summary', $op_occ);
      $this->assertArrayHasKey('deterministic_occupancy_descriptor', $op_occ);
    }
    $this->assertSame('admit', $diag['artifacts']['entitlement_capability_policy']['ticket']['scanner_mode']);
    $this->assertSame('admission', $diag['artifacts']['venue_operation_policy']['ticket']['gate_semantics']['gate_family']);
  }

  /**
   * @covers ::inspectOrder
   */
  public function testWalletContinuityDetection(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $diag = $this->inspector()->inspectOrder($order);
    $this->assertSame('valid', $diag['artifacts']['wallet_route_scaffold']);
    $this->assertSame('canonical', $diag['compatibility']['wallet_resolution_surface']);
  }

  /**
   * @covers ::inspectOrder
   */
  public function testLegacyCompatibilitySurface(): void {
    $order = $this->loadOrder();
    $diag = $this->inspector()->inspectOrder($order);
    $this->assertSame('legacy', $diag['compatibility']['order_item_pdf_surface']);
    $this->assertSame('legacy', $diag['compatibility']['wallet_resolution_surface']);
  }

  /**
   * @covers ::inspectOrder
   */
  public function testMixedCompatibilityDetection(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $tickets = $this->loadTicketsForOrder((int) $order->id());
    $tickets[0]->set('holder_name', 'A');
    $tickets[0]->set('holder_email', 'a@example.test');
    $tickets[0]->save();
    $diag = $this->inspector()->inspectOrder($order);
    $this->assertSame('mixed', $diag['artifacts']['canonical_pdf_readiness']);
    $this->assertSame('mixed', $diag['artifacts']['attachment_continuity']);
    $this->assertContains($diag['compatibility']['ticket_pdf_operational_path'], ['canonical', 'mixed']);
  }

  /**
   * @covers ::inspectOrder
   */
  public function testGuestContinuityValid(): void {
    $order = $this->loadOrder();
    $order->setCustomerId(0);
    $order->save();
    $this->issuer()->issueForOrder($order);
    $diag = $this->inspector()->inspectOrder($order);
    $this->assertTrue($diag['guest_continuity']['guest_access_compatible']);
    $this->assertTrue($diag['guest_continuity']['entitlement_ownership_valid']);
    $this->assertSame('valid', $diag['guest_continuity']['continuity_status']);
  }

  /**
   * @covers ::inspectOrder
   */
  public function testGuestContinuityInvalid(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $tickets = $this->loadTicketsForOrder((int) $order->id());
    $tickets[0]->set('purchaser_uid', 0);
    $tickets[0]->save();
    $diag = $this->inspector()->inspectOrder($order);
    $this->assertFalse($diag['guest_continuity']['entitlement_ownership_valid']);
    $this->assertSame('invalid', $diag['guest_continuity']['continuity_status']);
  }

  /**
   * @covers ::inspectOrder
   */
  public function testRecoveryVisibility(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $key = OrderPaidConfirmationPdfRecoverySubscriber::recoveryStateKey((int) $order->id());
    $this->container->get('state')->set($key, time());
    $diag = $this->inspector()->inspectOrder($order);
    $this->assertSame('recovered', $diag['recovery']['completion_state']);
    $this->assertTrue($diag['recovery']['recovery_completed_recorded']);
    $this->assertFalse($diag['recovery']['recovery_mismatch']);
  }

  /**
   * @covers ::inspectOrder
   */
  public function testRecoveryMismatchWithStubSink(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $tickets = $this->loadTicketsForOrder((int) $order->id());
    foreach ($tickets as $ticket) {
      $ticket->set('created', 5000000);
      $ticket->save();
    }
    $sent = new \stdClass();
    $sent->sent = 1000;
    $stub = new class([$sent]) {
      /**
       * @param object[] $rows
       */
      public function __construct(private readonly array $rows) {}
      /**
       * @return object[]
       */
      public function findSentOrderConfirmationsForOrder(int $orderId, string $recipient): array {
        return $this->rows;
      }
    };
    $inspector = $this->buildInspectorWithMessageSink($stub);
    $diag = $inspector->inspectOrder($order);
    $this->assertTrue($diag['recovery']['recovery_mismatch']);
    $this->assertSame('pending', $diag['recovery']['recovery_requirement_signal']);
  }

  private function issuer(): TicketIssuer {
    return $this->container->get('myeventlane_tickets.ticket_issuer');
  }

  private function inspector(): OperationalIntegrityInspector {
    return $this->container->get('myeventlane_tickets.operational_integrity_inspector');
  }

  private function buildInspectorWithMessageSink(object $sink): OperationalIntegrityInspector {
    return new OperationalIntegrityInspector(
      $this->container->get('entity_type.manager'),
      $this->container->get('myeventlane_tickets.ticket_issuer'),
      $this->container->get('myeventlane_tickets.universal_ticket_view_model_builder'),
      $this->container->get('myeventlane_tickets.ticket_pdf_generator'),
      $this->container->get('myeventlane_tickets.ticket_qr_payload'),
      $this->container->get('mel_ticket_capability.manager'),
      $this->container->get('myeventlane_tickets.entitlement_capability_registry'),
      $this->container->get('myeventlane_tickets.venue_operation_policy_manager'),
      $this->container->get('myeventlane_tickets.timed_entry_policy_manager'),
      $this->container->get('myeventlane_tickets.session_entitlement_policy_manager'),
      $this->container->get('myeventlane_tickets.zone_access_policy_manager'),
      $this->container->get('myeventlane_tickets.device_operation_identity_manager'),
      $this->container->get('myeventlane_tickets.operational_continuity_policy_manager'),
      $this->container->get('myeventlane_tickets.occupancy_policy_manager'),
      $this->container->get('datetime.time'),
      $this->container->get('state'),
      $this->container->get('logger.channel.myeventlane_tickets'),
      $this->container->get('myeventlane_wallet.ticket_resolver'),
      $sink,
    );
  }

  private function loadOrder(): Order {
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_order');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('order_id')->execute();
    $this->assertNotEmpty($ids);
    $order = $storage->load(reset($ids));
    $this->assertInstanceOf(Order::class, $order);
    return $order;
  }

  /**
   * @return array<int, \Drupal\myeventlane_tickets\Entity\Ticket>
   */
  private function loadTicketsForOrder(int $order_id): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('myeventlane_ticket');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $order_id)
      ->sort('id')
      ->execute();
    if (!$ids) {
      return [];
    }
    $loaded = $storage->loadMultiple($ids);
    return array_values($loaded);
  }

  /**
   * @return array<string, array{type: string, settings: array<string, mixed>}>
   */
  private function eventFieldDefinitions(): array {
    return [
      'field_event_start' => [
        'type' => 'datetime',
        'settings' => ['datetime_type' => 'datetime'],
      ],
      'field_event_end' => [
        'type' => 'datetime',
        'settings' => ['datetime_type' => 'datetime'],
      ],
      'field_venue_name' => [
        'type' => 'string',
        'settings' => ['max_length' => 255],
      ],
      'field_event_vendor' => [
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'user'],
      ],
    ];
  }

  private function createEventFields(): void {
    foreach ($this->eventFieldDefinitions() as $field_name => $definition) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => $definition['type'],
        'settings' => $definition['settings'],
      ])->save();

      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => 'event',
        'label' => $field_name,
      ])->save();
    }
  }

  private function ensureProductEventField(): void {
    if (FieldStorageConfig::loadByName('commerce_product', 'field_event')) {
      return;
    }
    FieldStorageConfig::create([
      'field_name' => 'field_event',
      'entity_type' => 'commerce_product',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'node'],
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_event',
      'entity_type' => 'commerce_product',
      'bundle' => 'default',
      'label' => 'Event',
    ])->save();
  }

}
