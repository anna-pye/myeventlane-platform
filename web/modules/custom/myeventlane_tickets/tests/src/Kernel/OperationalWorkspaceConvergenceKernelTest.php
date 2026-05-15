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
use Drupal\myeventlane_tickets\Access\OperationalWorkspaceAccessChecker;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\myeventlane_tickets\Service\OperationalEscalationAuditProjector;
use Drupal\myeventlane_tickets\Service\OperationalIntegrityInspector;
use Drupal\myeventlane_tickets\Service\OperationalWorkspaceBuilder;
use Drupal\myeventlane_tickets\Ticket\TicketIssuer;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\OperationalWorkspaceBuilder
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class OperationalWorkspaceConvergenceKernelTest extends KernelTestBase {

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
    $this->installConfig(['commerce_store', 'commerce_product', 'commerce_order', 'user']);
    $this->ensureWorkspaceRoles();

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
      'name' => 'Workspace Store',
      'mail' => 'store@example.test',
      'default_currency' => 'AUD',
      'status' => 1,
    ]);
    $store->save();

    $customer = User::create([
      'name' => 'workspace_customer',
      'mail' => 'workspace@example.test',
      'status' => 1,
    ]);
    $customer->save();

    $this->event = Node::create([
      'type' => 'event',
      'title' => 'Workspace Event',
      'uid' => $customer->id(),
      'status' => 1,
      'field_event_start' => '2026-08-01T10:00:00',
      'field_event_end' => '2026-08-01T12:00:00',
      'field_venue_name' => 'Hall A',
      'field_event_vendor' => $customer->id(),
    ]);
    $this->event->save();

    $product = Product::create([
      'type' => 'default',
      'title' => 'Workspace Product',
      'stores' => [$store],
      'field_event' => $this->event->id(),
    ]);
    $product->save();

    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => 'WORKSPACE-SKU',
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
      'uid' => $customer->id(),
      'mail' => 'workspace@example.test',
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
  }

  public function testWorkspaceAccessAnonymousDenied(): void {
    $access = new OperationalWorkspaceAccessChecker();
    $this->assertTrue($access->accessWorkspace(new AnonymousUserSession())->isForbidden());
  }

  public function testWorkspaceAccessVendorPermissionAloneDenied(): void {
    $access = new OperationalWorkspaceAccessChecker();
    $vendor = User::create([
      'name' => 'workspace_vendor',
      'mail' => 'vendor-ws@example.test',
      'status' => 1,
    ]);
    $vendor->addRole('mel_ws_vendor_only');
    $vendor->save();
    $vendor = User::load($vendor->id());
    $this->assertNotFalse($vendor);
    $this->assertTrue($access->accessWorkspace($vendor)->isForbidden());
  }

  public function testWorkspaceAccessStaffAllowed(): void {
    $access = new OperationalWorkspaceAccessChecker();
    $staff = User::create([
      'name' => 'workspace_staff',
      'mail' => 'staff-ws@example.test',
      'status' => 1,
    ]);
    $staff->addRole('mel_ws_staff');
    $staff->save();
    $staff = User::load($staff->id());
    $this->assertNotFalse($staff);
    $this->assertTrue($access->accessWorkspace($staff)->isAllowed());
  }

  public function testBuilderSectionsExcludeSensitiveTokens(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $tickets = $this->loadTicketsForOrder((int) $order->id());
    $this->assertNotEmpty($tickets);
    foreach ($tickets as $ticket) {
      $ticket->set('event_id', $this->event->id());
      $ticket->save();
    }

    $builder = $this->workspaceBuilder();
    $workspace = $builder->build($this->event);
    $encoded = json_encode($workspace['sections'], JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('replay_token', $encoded);
    $this->assertStringNotContainsString('device_fingerprint', $encoded);
    $this->assertStringNotContainsString('reconciliation_fingerprint', $encoded);
    $this->assertStringNotContainsString('continuity_descriptor_token', $encoded);
    $this->assertNotEmpty($workspace['sections']);
  }

  public function testBuilderUsesInspectorAndPolicyManagers(): void {
    $ref = new ReflectionClass(OperationalWorkspaceBuilder::class);
    $params = $ref->getConstructor()?->getParameters() ?? [];
    $types = [];
    foreach ($params as $p) {
      $t = $p->getType();
      if ($t instanceof \ReflectionNamedType) {
        $types[] = $t->getName();
      }
    }
    $this->assertContains(OperationalIntegrityInspector::class, $types);
    $this->assertContains('Drupal\myeventlane_tickets\Service\VenueOperationPolicyManager', $types);
    $this->assertContains('Drupal\myeventlane_tickets\Service\EntitlementCapabilityRegistry', $types);
  }

  public function testBuilderUsesGovernanceProjector(): void {
    $ref = new ReflectionClass(OperationalWorkspaceBuilder::class);
    $params = $ref->getConstructor()?->getParameters() ?? [];
    $types = [];
    foreach ($params as $p) {
      $t = $p->getType();
      if ($t instanceof \ReflectionNamedType) {
        $types[] = $t->getName();
      }
    }
    $this->assertContains(OperationalEscalationAuditProjector::class, $types);
  }

  public function testGovernanceSectionsIncludedForStaffWithEscalationPermission(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $tickets = $this->loadTicketsForOrder((int) $order->id());
    $this->assertNotEmpty($tickets);
    foreach ($tickets as $ticket) {
      $ticket->set('event_id', $this->event->id());
      $ticket->save();
    }

    $staff = User::create([
      'name' => 'workspace_staff_gov',
      'mail' => 'staff-gov-ws@example.test',
      'status' => 1,
    ]);
    $staff->addRole('mel_ws_staff');
    $staff->save();
    $staff = User::load($staff->id());
    $this->assertNotFalse($staff);

    /** @var \Drupal\Core\Session\AccountSwitcherInterface $switcher */
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($staff);

    $workspace = $this->workspaceBuilder()->build($this->event);
    $this->assertTrue($workspace['meta']['governance_projection_enabled']);
    $ids = array_column($workspace['sections'], 'id');
    $this->assertContains('escalation_governance', $ids);
    $this->assertContains('resolution_governance', $ids);
    $this->assertContains('suppression_governance', $ids);
    $this->assertContains('escalation_audit', $ids);

    $switcher->switchBack();
  }

  public function testGovernanceSectionsOmittedWithoutEscalationPermission(): void {
    $order = $this->loadOrder();
    $this->issuer()->issueForOrder($order);
    $tickets = $this->loadTicketsForOrder((int) $order->id());
    foreach ($tickets as $ticket) {
      $ticket->set('event_id', $this->event->id());
      $ticket->save();
    }

    $viewer = User::create([
      'name' => 'workspace_ops_view',
      'mail' => 'ops-view-ws@example.test',
      'status' => 1,
    ]);
    $viewer->addRole('mel_ws_ops_view');
    $viewer->save();
    $viewer = User::load($viewer->id());
    $this->assertNotFalse($viewer);

    /** @var \Drupal\Core\Session\AccountSwitcherInterface $switcher */
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($viewer);

    $workspace = $this->workspaceBuilder()->build($this->event);
    $this->assertFalse($workspace['meta']['governance_projection_enabled']);
    $ids = array_column($workspace['sections'], 'id');
    $this->assertNotContains('escalation_governance', $ids);

    $switcher->switchBack();
  }

  public function testGlobalCatalogSectionPresentWithoutEvent(): void {
    $workspace = $this->workspaceBuilder()->build(NULL);
    $ids = array_column($workspace['sections'], 'id');
    $this->assertContains('entitlement_operations', $ids);
    $this->assertSame('global', $workspace['meta']['scope']);
  }

  private function ensureWorkspaceRoles(): void {
    if (!Role::load('mel_ws_vendor_only')) {
      Role::create([
        'id' => 'mel_ws_vendor_only',
        'label' => 'MEL workspace vendor-only',
      ])->grantPermission('manage own events tickets')->save();
    }
    if (!Role::load('mel_ws_staff')) {
      Role::create([
        'id' => 'mel_ws_staff',
        'label' => 'MEL workspace staff',
      ])
        ->grantPermission('view mel venue operations workspace')
        ->grantPermission('govern mel operational escalations')
        ->save();
    }
    if (!Role::load('mel_ws_ops_view')) {
      Role::create([
        'id' => 'mel_ws_ops_view',
        'label' => 'MEL workspace view only',
      ])
        ->grantPermission('view mel venue operations workspace')
        ->save();
    }
  }

  private function issuer(): TicketIssuer {
    return $this->container->get('myeventlane_tickets.ticket_issuer');
  }

  private function workspaceBuilder(): OperationalWorkspaceBuilder {
    return $this->container->get('myeventlane_tickets.operational_workspace_builder');
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
