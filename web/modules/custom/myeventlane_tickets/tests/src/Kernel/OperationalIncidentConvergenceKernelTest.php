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
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Access\OperationalIncidentAccessChecker;
use Drupal\myeventlane_tickets\Entity\RedemptionLog;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Ticket\TicketIssuer;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\OperationalIncidentBuilder
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class OperationalIncidentConvergenceKernelTest extends KernelTestBase {

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
    $this->installEntitySchema('mel_redemption_log');
    $this->installEntitySchema('mel_operational_incident');
    $this->installConfig(['commerce_store', 'commerce_product', 'commerce_order', 'user']);

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
      'name' => 'Incident Store',
      'mail' => 'store@example.test',
      'default_currency' => 'AUD',
      'status' => 1,
    ]);
    $store->save();

    $this->customer = User::create([
      'name' => 'incident_customer',
      'mail' => 'incident@example.test',
      'status' => 1,
    ]);
    $this->customer->save();

    $this->event = Node::create([
      'type' => 'event',
      'title' => 'Incident Event',
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
      'title' => 'Incident Product',
      'stores' => [$store],
      'field_event' => $this->event->id(),
    ]);
    $product->save();

    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => 'INCIDENT-SKU',
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
      'mail' => 'incident@example.test',
      'placed' => time(),
    ]);
    $order->save();

    $item = OrderItem::create([
      'type' => 'default',
      'purchased_entity' => $variation,
      'quantity' => 1,
      'unit_price' => new Price('25.00', 'AUD'),
      'order_id' => $order->id(),
    ]);
    $item->save();
    $order->addItem($item);
    $order->save();

    $this->issuer()->issueForOrder($order);
    foreach ($this->loadTicketsForOrder((int) $order->id()) as $ticket) {
      $ticket->set('event_id', $this->event->id());
      $ticket->set('holder_name', 'Sam');
      $ticket->set('holder_email', 'sam@example.test');
      $ticket->save();
    }
  }

  public function testIncidentNormalizerCoordinationSeverityTokens(): void {
    $n = new \Drupal\myeventlane_tickets\Service\OperationalIncidentNormalizer();
    $this->assertSame('critical', $n->normalizeCoordinationSeverity('critical'));
    $this->assertSame('moderate', $n->normalizeCoordinationSeverity('warn'));
    $this->assertSame('low', $n->normalizeCoordinationSeverity('ok'));
  }

  public function testIncidentNormalizerSeverityTokens(): void {
    $n = new \Drupal\myeventlane_tickets\Service\OperationalIncidentNormalizer();
    $this->assertSame('critical', $n->normalizeSeverity('critical'));
    $this->assertSame('warning', $n->normalizeSeverity('warn'));
    $this->assertSame('info', $n->normalizeSeverity('ok'));
  }

  public function testIncidentNormalizerTypesAreMachineSafe(): void {
    $n = new \Drupal\myeventlane_tickets\Service\OperationalIncidentNormalizer();
    $this->assertSame('orphan_ticket_rows', $n->normalizeIncidentType('orphan_ticket_rows'));
    $this->assertSame('bad_state', $n->normalizeIncidentType('Bad State!'));
  }

  public function testOperationalIncidentAccessChecker(): void {
    $this->installConfig(['user']);
    $role = Role::create([
      'id' => 'mel_ops_staff',
      'label' => 'MEL ops',
    ]);
    $role->grantPermission('view mel venue operations workspace');
    $role->save();
    $staff = User::create([
      'name' => 'ops_staff',
      'roles' => ['mel_ops_staff'],
      'status' => 1,
    ]);
    $staff->save();

    $checker = new OperationalIncidentAccessChecker();
    $this->assertTrue($checker->accessOperationalIncidentWorkspace($staff)->isAllowed());
    $this->assertTrue($checker->accessOperationalIncidentWorkspace(new AnonymousUserSession())->isForbidden());
  }

  public function testWorkspaceSectionsIncludeIncidentSurfaces(): void {
    /** @var \Drupal\myeventlane_tickets\Service\OperationalWorkspaceBuilder $builder */
    $builder = $this->container->get('myeventlane_tickets.operational_workspace_builder');
    $workspace = $builder->build($this->event);
    $ids = array_column($workspace['sections'], 'id');
    $this->assertContains('operational_incidents', $ids);
    $this->assertContains('recovery_visibility', $ids);
    $this->assertContains('incident_severity', $ids);
    $this->assertContains('operational_integrity_execution', $ids);
  }

  public function testWorkspaceOutputStripsSensitiveMaterial(): void {
    /** @var \Drupal\myeventlane_tickets\Service\OperationalWorkspaceBuilder $builder */
    $builder = $this->container->get('myeventlane_tickets.operational_workspace_builder');
    $workspace = $builder->build($this->event);
    $blob = json_encode($workspace['sections']);
    $this->assertIsString($blob);
    $this->assertStringNotContainsString('replay_token', $blob);
    $this->assertStringNotContainsString('hmac_material', $blob);
    $this->assertStringNotContainsString('device_fingerprint', $blob);
  }

  public function testDeniedScanAggregationCountsWithoutLeakingIntegrity(): void {
    $tickets = $this->loadTicketsForOrder((int) $this->loadOrder()->id());
    $this->assertNotEmpty($tickets);
    $ticket = $tickets[0];
    $log = RedemptionLog::create([
      'ticket_id' => $ticket->id(),
      'entitlement_type' => Ticket::ENTITLEMENT_TICKET,
      'event_id' => $this->event->id(),
      'action_type' => RedemptionLog::ACTION_ADMIT,
      'metadata_json' => [
        'ok' => FALSE,
        'result' => 'denied_timing',
        'venue_operation_integrity' => [
          'replay_token' => 'secret-replay',
          'operation_id' => 'op_test',
        ],
      ],
    ]);
    $log->save();

    /** @var \Drupal\myeventlane_tickets\Service\OperationalIncidentBuilder $incidents */
    $incidents = $this->container->get('myeventlane_tickets.operational_incident_builder');
    $counts = $incidents->aggregateDeniedScanResults([(int) $ticket->id()], (int) $this->event->id());
    $this->assertGreaterThanOrEqual(1, array_sum($counts));

    $workspace = $this->container->get('myeventlane_tickets.operational_workspace_builder')->build($this->event);
    $blob = json_encode($workspace['sections']);
    $this->assertStringNotContainsString('secret-replay', $blob);
  }

  public function testRecoverySummaryUsesMergedCompatibility(): void {
    $order = $this->loadOrder();
    $diag = $this->container->get('myeventlane_tickets.operational_integrity_inspector')->inspectOrder($order);
    $merged = [
      'recovery' => $diag['recovery'],
      'artifacts' => $diag['artifacts'],
      'guest_continuity' => $diag['guest_continuity'],
      'compatibility' => $diag['compatibility'],
    ];
    $builder = new \Drupal\myeventlane_tickets\Service\OperationalRecoverySummaryBuilder();
    $section = $builder->buildSection([(int) $order->id() => $diag], $merged);
    $this->assertSame('recovery_visibility', $section['id']);
    $this->assertNotEmpty($section['cards']);
  }

  private function issuer(): TicketIssuer {
    return $this->container->get('myeventlane_tickets.ticket_issuer');
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
   * @return array<int, Ticket>
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
