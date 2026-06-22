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
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Ticket\TicketCodeGenerator;
use Drupal\myeventlane_tickets\Ticket\TicketIssuer;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\myeventlane_tickets\Kernel\Traits\RegistersTicketBackedClassifierStubTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Per-order-item idempotency and partial issuance repair for TicketIssuer.
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class TicketIssuerPartialRepairKernelTest extends KernelTestBase {

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
  ];

  private ?TicketCodeGenerator $realCodeGenerator = NULL;

  private ?TicketCodeGenerator $activeCodeGenerator = NULL;

  private User $customer;

  private Store $store;

  private ProductVariation $variation;

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
    $this->store = Store::create([
      'type' => 'online',
      'name' => 'Partial Repair Test Store',
      'mail' => 'partial-repair@example.test',
      'default_currency' => 'AUD',
      'status' => 1,
    ]);
    $this->store->save();

    $this->customer = User::create([
      'name' => 'partial_repair_customer',
      'mail' => 'partial-repair@example.test',
      'status' => 1,
    ]);
    $this->customer->save();

    $this->event = Node::create([
      'type' => 'event',
      'title' => 'Partial Repair Event',
      'uid' => $this->customer->id(),
      'status' => 1,
      'field_event_start' => '2026-08-01T10:00:00',
      'field_event_end' => '2026-08-01T12:00:00',
      'field_venue_name' => 'Hall B',
      'field_event_vendor' => $this->customer->id(),
    ]);
    $this->event->save();

    $product = Product::create([
      'type' => 'default',
      'title' => 'Partial Repair Ticket Product',
      'stores' => [$this->store],
      'field_event' => $this->event->id(),
    ]);
    $product->save();

    $this->variation = ProductVariation::create([
      'type' => 'default',
      'sku' => 'PARTIAL-REPAIR-SKU-1',
      'title' => 'GA',
      'product_id' => $product->id(),
      'price' => new Price('25.00', 'AUD'),
      'status' => 1,
    ]);
    $this->variation->save();

    $this->realCodeGenerator = $this->container->get('myeventlane_tickets.ticket_code_generator');
    $this->activeCodeGenerator = $this->realCodeGenerator;
  }

  /**
   * Simulated interrupted first run (2 of 3) is repaired on replay.
   */
  public function testPartialIssuanceRepairCreatesMissingTicketsOnly(): void {
    $order = $this->createOrderWithQuantity(3);
    $item = array_values($order->getItems())[0];
    $this->seedTicketsForOrderItem($order, $item, 2);

    $this->issuer()->issueForOrder($order);

    $this->assertSame(3, $this->countTicketsForOrderItem((int) $item->id()));
    $this->assertSame(3, $this->countTicketsForOrder((int) $order->id()));
  }

  /**
   * Full replay after repair must not create duplicate tickets.
   */
  public function testPartialIssuanceRepairIdempotentReplay(): void {
    $order = $this->createOrderWithQuantity(3);
    $item = array_values($order->getItems())[0];
    $this->seedTicketsForOrderItem($order, $item, 2);

    $issuer = $this->issuer();
    $issuer->issueForOrder($order);
    $issuer->issueForOrder($order);

    $this->assertSame(3, $this->countTicketsForOrderItem((int) $item->id()));
    $this->assertSame(3, $this->countTicketsForOrder((int) $order->id()));
  }

  /**
   * First run failure mid-item leaves partial rows; second run fills the gap.
   */
  public function testIssuanceFailureThenRepairCreatesMissingTicketOnly(): void {
    $order = $this->createOrderWithQuantity(3);
    $item = array_values($order->getItems())[0];
    $this->registerFailingCodeGeneratorAfter(2);

    try {
      $this->issuer()->issueForOrder($order);
      $this->fail('Expected issuance to fail after two tickets.');
    }
    catch (\RuntimeException) {
      // Expected simulated failure.
    }

    $this->assertSame(2, $this->countTicketsForOrderItem((int) $item->id()));

    $this->resetCodeGenerator();
    $this->issuer()->issueForOrder($order);

    $this->assertSame(3, $this->countTicketsForOrderItem((int) $item->id()));
    $this->assertSame(3, $this->countTicketsForOrder((int) $order->id()));
  }

  /**
   * Per-order-item idempotency: one complete line must not block another partial line.
   */
  public function testMultiItemPartialRepairDoesNotSkipIncompleteLine(): void {
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'completed',
      'uid' => $this->customer->id(),
      'mail' => 'partial-repair@example.test',
      'placed' => time(),
    ]);
    $order->save();

    $complete_item = OrderItem::create([
      'type' => 'default',
      'purchased_entity' => $this->variation,
      'quantity' => 2,
      'unit_price' => new Price('25.00', 'AUD'),
      'order_id' => $order->id(),
    ]);
    $complete_item->save();
    $order->addItem($complete_item);

    $partial_item = OrderItem::create([
      'type' => 'default',
      'purchased_entity' => $this->variation,
      'quantity' => 3,
      'unit_price' => new Price('25.00', 'AUD'),
      'order_id' => $order->id(),
    ]);
    $partial_item->save();
    $order->addItem($partial_item);
    $order->save();

    $this->seedTicketsForOrderItem($order, $complete_item, 2);
    $this->seedTicketsForOrderItem($order, $partial_item, 1);

    $this->issuer()->issueForOrder($order);

    $this->assertSame(2, $this->countTicketsForOrderItem((int) $complete_item->id()));
    $this->assertSame(3, $this->countTicketsForOrderItem((int) $partial_item->id()));
    $this->assertSame(5, $this->countTicketsForOrder((int) $order->id()));
  }

  private function createOrderWithQuantity(int $quantity): Order {
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'completed',
      'uid' => $this->customer->id(),
      'mail' => 'partial-repair@example.test',
      'placed' => time(),
    ]);
    $order->save();

    $item = OrderItem::create([
      'type' => 'default',
      'purchased_entity' => $this->variation,
      'quantity' => $quantity,
      'unit_price' => new Price('25.00', 'AUD'),
      'order_id' => $order->id(),
    ]);
    $item->save();
    $order->addItem($item);
    $order->save();

    return $order;
  }

  /**
   * Seeds ticket rows to simulate an interrupted first issuance run.
   */
  private function seedTicketsForOrderItem(Order $order, OrderItem $order_item, int $count): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('myeventlane_ticket');
    $generator = $this->realCodeGenerator ?? $this->container->get('myeventlane_tickets.ticket_code_generator');
    for ($i = 0; $i < $count; $i++) {
      $ticket = $storage->create([
        'ticket_code' => $generator->generateUniqueTicketCode(),
        'event_id' => $this->event->id(),
        'order_id' => $order->id(),
        'order_item_id' => $order_item->id(),
        'purchased_entity' => $this->variation->id(),
        'purchaser_uid' => $this->customer->id(),
        'status' => Ticket::STATUS_ISSUED_UNASSIGNED,
      ]);
      $ticket->save();
    }
  }

  private function registerFailingCodeGeneratorAfter(int $success_count): void {
    $attempts = 0;
    $inner = $this->realCodeGenerator;
    $this->assertInstanceOf(TicketCodeGenerator::class, $inner);

    $generator = $this->createMock(TicketCodeGenerator::class);
    $generator->method('generateUniqueTicketCode')->willReturnCallback(function () use (&$attempts, $success_count, $inner): string {
      $attempts++;
      if ($attempts > $success_count) {
        throw new \RuntimeException('Simulated ticket code generation failure.');
      }
      return $inner->generateUniqueTicketCode();
    });
    $this->activeCodeGenerator = $generator;
  }

  private function resetCodeGenerator(): void {
    $this->activeCodeGenerator = $this->realCodeGenerator;
  }

  private function issuer(): TicketIssuer {
    return new TicketIssuer(
      $this->container->get('entity_type.manager'),
      $this->activeCodeGenerator ?? $this->container->get('myeventlane_tickets.ticket_code_generator'),
      $this->container->get('logger.factory'),
      $this->container->get('myeventlane_commerce.ticket_backed_order_item_classifier'),
    );
  }

  private function countTicketsForOrder(int $order_id): int {
    $storage = $this->container->get('entity_type.manager')->getStorage('myeventlane_ticket');
    return (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $order_id)
      ->count()
      ->execute();
  }

  private function countTicketsForOrderItem(int $order_item_id): int {
    $storage = $this->container->get('entity_type.manager')->getStorage('myeventlane_ticket');
    return (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_item_id', $order_item_id)
      ->count()
      ->execute();
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
