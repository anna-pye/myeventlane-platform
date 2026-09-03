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
use Drupal\commerce_product\Entity\ProductType;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\commerce_store\Entity\Store;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Ticket\TicketIssuer;
use Drupal\myeventlane_tickets\Ticket\OperationalEntitlementIssuer;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\OperationalEntitlementFulfilmentManager;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\myeventlane_tickets\Kernel\Traits\RegistersTicketBackedClassifierStubTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Mixed-cart issuance keeps admission and operational entitlements separate.
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class TicketIssuerMixedOrderKernelTest extends KernelTestBase {

  use RegistersTicketBackedClassifierStubTrait;

  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'link',
    'path',
    'path_alias',
    'views',
    'options',
    'datetime',
    'datetime_range',
    'address',
    'node',
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
   * Admission issuer creates two tickets; operational issuer creates two extras.
   */
  public function testMixedOrderIssuesTicketsForTicketLinesOnly(): void {
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
    $this->installEntitySchema('mel_redemption_log');
    $this->installConfig(['commerce_store', 'commerce_product', 'commerce_order', 'user']);

    NodeType::create(['type' => 'event', 'name' => 'Event'])->save();
    $this->ensureCatalogTypes('default', 'default');
    $this->ensureCatalogTypes('operational_merchandise_var', 'operational_merchandise');
    $this->ensureProductEventField('default');
    $this->ensureProductEventField('operational_merchandise');
    $this->ensureOrderItemTargetEventField();

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

    $customer = User::create([
      'name' => 'mixed_order_customer',
      'mail' => 'mixed-order@example.test',
      'status' => 1,
    ]);
    $customer->save();

    $store = Store::create([
      'type' => 'online',
      'name' => 'Mixed Order Store',
      'mail' => 'mixed-store@example.test',
      'default_currency' => 'AUD',
      'status' => 1,
    ]);
    $store->save();

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

    $event = Node::create([
      'type' => 'event',
      'title' => 'Mixed order event',
      'uid' => $customer->id(),
      'status' => 1,
    ]);
    $event->save();

    $ticket_variation_a = $this->createVariation('default', 'TICKET-SKU-A', $store, $event, '25.00');
    $ticket_variation_b = $this->createVariation('default', 'TICKET-SKU-B', $store, $event, '25.00');
    $parking_variation = $this->createVariation('operational_merchandise_var', 'PARK-SKU', $store, $event, '12.00', 'operational_merchandise');
    $shirt_variation = $this->createVariation('operational_merchandise_var', 'SHIRT-SKU', $store, $event, '30.00', 'operational_merchandise');

    $order = Order::create([
      'type' => 'default',
      'store_id' => $store->id(),
      'state' => 'completed',
      'uid' => $customer->id(),
      'mail' => 'mixed-order@example.test',
      'placed' => time(),
    ]);
    $order->save();

    $event_id = (int) $event->id();
    $this->addOrderItem($order, $ticket_variation_a, 1, $event_id);
    $this->addOrderItem($order, $ticket_variation_b, 1, $event_id);
    $this->addOrderItem($order, $parking_variation, 1, $event_id);
    $this->addOrderItem($order, $shirt_variation, 1, $event_id);
    $order->save();

    $issuer = $this->container->get('myeventlane_tickets.ticket_issuer');
    $this->assertInstanceOf(TicketIssuer::class, $issuer);
    $issuer->issueForOrder($order);

    $this->assertSame(2, $this->countTicketsForOrder((int) $order->id()));

    $operational_issuer = new OperationalEntitlementIssuer(
      $this->container->get('entity_type.manager'),
      $this->container->get('myeventlane_tickets.ticket_code_generator'),
      $this->container->get('logger.factory'),
      $this->container->get('lock'),
      new OperationalMerchandiseManager(
        $this->container->get('entity_type.manager'),
        $this->container->get('string_translation'),
        $this->container->get('logger.channel.myeventlane_tickets'),
      ),
    );
    $this->assertInstanceOf(OperationalEntitlementIssuer::class, $operational_issuer);
    $operational_issuer->issueForOrder($order);
    $operational_issuer->issueForOrder($order);

    $this->assertSame(4, $this->countTicketsForOrder((int) $order->id()), 'Paid-event replay does not duplicate admission or add-on entitlements.');
    $storage = $this->container->get('entity_type.manager')->getStorage('myeventlane_ticket');
    $extra_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', (int) $order->id())
      ->condition('entitlement_type', Ticket::ENTITLEMENT_TICKET, '<>')
      ->sort('id', 'ASC')
      ->execute();
    $this->assertCount(2, $extra_ids);

    /** @var \Drupal\myeventlane_tickets\Entity\Ticket[] $extras */
    $extras = array_values($storage->loadMultiple($extra_ids));
    $this->assertSame(Ticket::ENTITLEMENT_MERCH, $extras[0]->getEntitlementType());
    $this->assertSame(Ticket::FULFILMENT_PENDING, $extras[0]->getFulfilmentStatus());
    $capabilities = $this->container->get('mel_ticket_capability.manager');
    $this->assertFalse($capabilities->canBeScanned($extras[0]), 'A pickup QR is blocked until the organiser marks it ready.');

    $fulfilment = new OperationalEntitlementFulfilmentManager(
      $this->container->get('entity_type.manager'),
      $this->container->get('lock'),
      $this->container->get('database'),
      $this->container->get('current_user'),
      $this->container->get('request_stack'),
      $this->container->get('datetime.time'),
      $this->container->get('logger.channel.myeventlane_tickets'),
    );
    $updated = $fulfilment->transition($extras[0], $event, Ticket::FULFILMENT_PREPARING);
    $updated = $fulfilment->transition($updated, $event, Ticket::FULFILMENT_READY);
    $this->assertTrue($capabilities->canBeScanned($updated));
    $updated = $fulfilment->transition($updated, $event, Ticket::FULFILMENT_COLLECTED);
    $this->assertSame(Ticket::FULFILMENT_COLLECTED, $updated->getFulfilmentStatus());
    $this->assertSame(1, $updated->getRedemptionCount());
    $this->assertFalse($capabilities->canBeScanned($updated));

    $recovered = $fulfilment->transition(
      $extras[1],
      $event,
      Ticket::FULFILMENT_READY,
      'Customer phone was unavailable; booking checked.',
      TRUE,
    );
    $this->assertSame(Ticket::FULFILMENT_READY, $recovered->getFulfilmentStatus());
    $log_count = (int) $this->container->get('entity_type.manager')->getStorage('mel_redemption_log')->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $this->assertSame(4, $log_count, 'Normal and recovery actions are append-only audited.');
  }

  private function createVariation(
    string $variation_type,
    string $sku,
    Store $store,
    Node $event,
    string $price,
    string $product_type = 'default',
  ): ProductVariation {
    $product = Product::create([
      'type' => $product_type,
      'title' => $sku,
      'stores' => [$store],
      'field_event' => $event->id(),
    ]);
    $product->save();

    $variation = ProductVariation::create([
      'type' => $variation_type,
      'sku' => $sku,
      'title' => $sku,
      'product_id' => $product->id(),
      'price' => new Price($price, 'AUD'),
      'status' => 1,
    ]);
    $variation->save();
    return $variation;
  }

  private function addOrderItem(Order $order, ProductVariation $variation, int $qty, int $event_id): void {
    $item = OrderItem::create([
      'type' => 'default',
      'purchased_entity' => $variation,
      'quantity' => $qty,
      'unit_price' => $variation->getPrice(),
      'order_id' => $order->id(),
    ]);
    if ($item->hasField('field_target_event')) {
      $item->set('field_target_event', $event_id);
    }
    $item->save();
    $order->addItem($item);
  }

  private function countTicketsForOrder(int $order_id): int {
    $storage = $this->container->get('entity_type.manager')->getStorage('myeventlane_ticket');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $order_id)
      ->execute();
    return count($ids);
  }

  private function ensureCatalogTypes(string $variation_type, string $product_type): void {
    if (!ProductType::load($product_type)) {
      ProductType::create(['id' => $product_type, 'label' => $product_type])->save();
    }
    if (!ProductVariationType::load($variation_type)) {
      ProductVariationType::create([
        'id' => $variation_type,
        'label' => $variation_type,
        'generateTitle' => TRUE,
      ])->save();
    }
  }

  private function ensureOrderItemTargetEventField(): void {
    if (FieldStorageConfig::loadByName('commerce_order_item', 'field_target_event')) {
      return;
    }

    FieldStorageConfig::create([
      'field_name' => 'field_target_event',
      'entity_type' => 'commerce_order_item',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'node'],
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_target_event',
      'entity_type' => 'commerce_order_item',
      'bundle' => 'default',
      'label' => 'Target event',
    ])->save();
  }

  private function ensureProductEventField(string $bundle): void {
    if (!FieldStorageConfig::loadByName('commerce_product', 'field_event')) {
      FieldStorageConfig::create([
        'field_name' => 'field_event',
        'entity_type' => 'commerce_product',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'node'],
      ])->save();
    }

    if (!FieldConfig::loadByName('commerce_product', $bundle, 'field_event')) {
      FieldConfig::create([
        'field_name' => 'field_event',
        'entity_type' => 'commerce_product',
        'bundle' => $bundle,
        'label' => 'Event',
      ])->save();
    }
  }

}
