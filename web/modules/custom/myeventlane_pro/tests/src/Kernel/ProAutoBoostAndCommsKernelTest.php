<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Kernel;

use Drupal\commerce_store\Entity\Store;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_pro\Entity\ProVendorComms;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Kernel tests for Pro auto-boost and comms overrides.
 *
 * @group myeventlane_pro
 */
#[RunTestsInSeparateProcesses]
final class ProAutoBoostAndCommsKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('myeventlane_donations.vendor_event_mel_support', \stdClass::class);
    $container->register('myeventlane_commerce.ticket_availability', \stdClass::class);
    $container->register('myeventlane_checkout_flow.order_pricing_breakdown', \stdClass::class);
    $container->register('myeventlane_checkout_flow.tax_invoice_presentation', \stdClass::class);
    $container->register('myeventlane_tickets.event_access', \stdClass::class);
    foreach ([
      'myeventlane_event_studio.save',
      'myeventlane_event_studio.commerce_sales_summary_builder',
      'myeventlane_event_studio.extras_configured_summary_builder',
      'myeventlane_commerce.order_item_classifier',
      'myeventlane_commerce.vendor_operational_addon_order_builder',
      'myeventlane_commerce.ticket_tier_analytics',
      'myeventlane_commerce.event_operational_extras_sales_summary_builder',
      'myeventlane_legal.gatekeeper',
      'myeventlane_onboarding.manager',
      'myeventlane_surface.state_readiness_helper',
      'myeventlane_surface.data_presentation_manager',
      'myeventlane_surface.vendor_dashboard_action_queue_governance',
    ] as $serviceId) {
      $container->register($serviceId, \stdClass::class);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'path',
    'path_alias',
    'image',
    'crop',
    'focal_point',
    'field',
    'views',
    'options',
    'entity',
    'inline_entity_form',
    'entity_reference_revisions',
    'profile',
    'telephone',
    'address',
    'text',
    'datetime',
    'node',
    'taxonomy',
    'flag',
    'commerce',
    'commerce_order',
    'commerce_number_pattern',
    'commerce_price',
    'commerce_payment',
    'commerce_product',
    'commerce_store',
    'commerce_recurring',
    'state_machine',
    'advancedqueue',
    'myeventlane_core',
    'myeventlane_analytics',
    'myeventlane_metrics',
    'myeventlane_attendee',
    'myeventlane_capacity',
    'myeventlane_vendor_analytics',
    'myeventlane_event_state',
    'myeventlane_event',
    'myeventlane_event_attendees',
    'myeventlane_messaging',
    'myeventlane_boost',
    'myeventlane_vendor',
    'myeventlane_pro',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('commerce_store');
    $this->installEntitySchema('myeventlane_boost_entitlement');
    $this->installEntitySchema('myeventlane_vendor');
    $this->installEmptySubscriptionFixture();
    if ($this->container->get('entity_type.manager')->hasDefinition('advancedqueue_queue')) {
      $this->installEntitySchema('advancedqueue_queue');
    }
    if ($this->container->get('entity_type.manager')->hasDefinition('advancedqueue_job')) {
      $this->installEntitySchema('advancedqueue_job');
    }
    $this->installConfig([
      'node',
      'commerce_store',
    ]);
    $this->container->get('config.factory')
      ->getEditable('myeventlane_pro.settings')
      ->set('pro_boost_days', 7)
      ->save();

    if (!Role::load('mel_pro')) {
      Role::create([
        'id' => 'mel_pro',
        'label' => 'MEL Pro',
      ])->save();
    }

    if (!NodeType::load('event')) {
      NodeType::create([
        'type' => 'event',
        'name' => 'Event',
      ])->save();
    }

    $this->ensureEventFieldStorage('field_event_store', 'entity_reference', [
      'target_type' => 'commerce_store',
    ]);
    $this->ensureEventFieldStorage('field_event_start', 'datetime', [
      'datetime_type' => 'datetime',
    ]);
    $this->ensureEventFieldStorage('field_promoted', 'boolean', []);
    $this->ensureEventFieldStorage('field_promo_expires', 'datetime', [
      'datetime_type' => 'datetime',
    ]);
  }

  /**
   * Installs the queryable empty-subscription fixture used by Pro resolution.
   *
   * The test exercises the compatibility entitlement path and does not need a
   * Commerce subscription bundle. The production resolver still needs to be
   * able to prove that no canonical subscription exists before falling back.
   */
  private function installEmptySubscriptionFixture(): void {
    $schema = $this->container->get('database')->schema();
    if ($schema->tableExists('commerce_subscription')) {
      return;
    }

    $schema->createTable('commerce_subscription', [
      'fields' => [
        'subscription_id' => [
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'uid' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
        ],
        'billing_schedule' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
          'default' => '',
        ],
      ],
      'primary key' => ['subscription_id'],
      'indexes' => [
        'subscription_owner_schedule' => ['uid', 'billing_schedule'],
      ],
    ]);
  }

  /**
   * Pro-active store events are promoted by entitlement sync.
   */
  public function testProProvisionerPromotesEligibleEvent(): void {
    $owner = $this->createProUser(TRUE);
    $store = $this->createStoreForUser($owner);
    $event = $this->createEvent($owner, $store, strtotime('+2 days'));

    $stats = $this->container->get('myeventlane_pro.pro_boost_provisioner')->syncStore($store);
    $this->assertGreaterThanOrEqual(1, (int) ($stats['created'] ?? 0));

    $reloaded = Node::load((int) $event->id());
    $this->assertNotNull($reloaded);
    $this->assertSame('1', (string) $reloaded->get('field_promoted')->value);
  }

  /**
   * Pro-end revocation clears promoted field after sync.
   */
  public function testProProvisionerRevokesWhenProInactive(): void {
    $owner = $this->createProUser(TRUE);
    $store = $this->createStoreForUser($owner);
    $event = $this->createEvent($owner, $store, strtotime('+3 days'));

    $provisioner = $this->container->get('myeventlane_pro.pro_boost_provisioner');
    $provisioner->syncStore($store);

    $store = $this->transitionStoreToInactivePro($store);
    $provisioner->syncStore($store);

    $reloaded = Node::load((int) $event->id());
    $this->assertNotNull($reloaded);
    $this->assertSame('0', (string) $reloaded->get('field_promoted')->value);
  }

  /**
   * Vendor comms override resolves only while Pro is active.
   */
  public function testVendorCommsResolverHonorsProState(): void {
    $owner = $this->createProUser(TRUE);
    $store = $this->createStoreForUser($owner);

    ProVendorComms::create([
      'id' => 'store_' . (int) $store->id(),
      'label' => 'Store comms',
      'store_id' => (int) $store->id(),
      'enabled' => TRUE,
      'ticket_body' => '<p onclick="alert(1)">Hello [customer:first_name], total [order:total].</p><script>alert(1)</script>',
      'brand_signature' => '<p>Thanks from vendor.</p>',
      'updated' => $this->container->get('datetime.time')->getRequestTime(),
    ])->save();

    $resolver = $this->container->get('myeventlane_pro.vendor_comms_resolver');
    $body = $resolver->resolveBody($store, 'order_confirmation', [
      'first_name' => 'Chris',
      'order_total' => '19.00',
    ]);
    $this->assertIsString($body);
    $this->assertStringContainsString('Hello', (string) $body);
    $this->assertStringContainsString('MyEventLane', (string) $body);
    $this->assertStringNotContainsString('<script', (string) $body);
    $this->assertStringNotContainsString('onclick=', (string) $body);

    $store = $this->transitionStoreToInactivePro($store);
    $inactive = $resolver->resolveBody($store, 'order_confirmation', [
      'first_name' => 'Chris',
      'order_total' => '19.00',
    ]);
    $this->assertNull($inactive);
  }

  /**
   * Creates event field storage + field config for event bundle.
   */
  private function ensureEventFieldStorage(string $fieldName, string $fieldType, array $settings): void {
    if (!FieldStorageConfig::loadByName('node', $fieldName)) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'node',
        'type' => $fieldType,
        'settings' => $settings,
        'cardinality' => 1,
      ])->save();
    }

    if (!FieldConfig::loadByName('node', 'event', $fieldName)) {
      FieldConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'node',
        'bundle' => 'event',
        'label' => $fieldName,
        'settings' => $settings,
      ])->save();
    }
  }

  /**
   * Creates a Pro-capable user.
   */
  private function createProUser(bool $withPro): User {
    $roles = ['authenticated'];
    if ($withPro) {
      $roles[] = 'mel_pro';
    }
    $user = User::create([
      'name' => 'vendor_' . uniqid('', TRUE),
      'mail' => uniqid('vendor_', TRUE) . '@example.com',
      'status' => 1,
      'roles' => $roles,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Creates a store for the owner.
   */
  private function createStoreForUser(User $owner): Store {
    $store = Store::create([
      'type' => 'online',
      'name' => 'Store ' . uniqid('', TRUE),
      'mail' => 'store-' . uniqid('', TRUE) . '@example.com',
      'uid' => (int) $owner->id(),
      'default_currency' => 'AUD',
    ]);
    $store->save();
    return $store;
  }

  /**
   * Creates an event with store and start date.
   */
  private function createEvent(User $owner, Store $store, int $startTimestamp): Node {
    $node = Node::create([
      'type' => 'event',
      'title' => 'Test event ' . uniqid('', TRUE),
      'uid' => (int) $owner->id(),
      'status' => 1,
      'field_event_store' => (int) $store->id(),
      'field_event_start' => gmdate('Y-m-d\TH:i:s', $startTimestamp),
      'field_promoted' => 0,
      'field_promo_expires' => NULL,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Forces vendor compatibility fallback to non-Pro for a user.
   */
  private function deactivateVendorForOwner(User $owner): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('myeventlane_vendor');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', (int) $owner->id())
      ->execute();
    foreach ($storage->loadMultiple($ids) as $vendor) {
      if ($vendor->hasField('is_pro')) {
        $vendor->set('is_pro', 0);
        $vendor->save();
      }
    }
  }

  /**
   * Applies a deterministic Pro->inactive transition for a store owner.
   */
  private function transitionStoreToInactivePro(Store $store): Store {
    $owner = $store->getOwner();
    if ($owner instanceof User) {
      $owner->removeRole('mel_pro');
      $owner->save();
      $this->deactivateVendorForOwner($owner);
      $this->deleteOwnerProSubscriptions($owner);
      $this->container->get('entity_type.manager')->getStorage('user')->resetCache([(int) $owner->id()]);
    }

    $storeId = (int) $store->id();
    $this->container->get('entity_type.manager')->getStorage('commerce_store')->resetCache([$storeId]);
    $this->container->get('cache_tags.invalidator')->invalidateTags([
      'pro_subscription:' . $storeId,
      'commerce_store:' . $storeId,
    ]);

    /** @var \Drupal\commerce_store\Entity\Store|null $reloaded */
    $reloaded = Store::load($storeId);
    $this->assertNotNull($reloaded);
    return $reloaded;
  }

  /**
   * Deletes Pro subscription entities for a specific owner.
   */
  private function deleteOwnerProSubscriptions(User $owner): void {
    if (!$this->container->get('database')->schema()->tableExists('commerce_subscription')) {
      return;
    }
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_subscription');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', (int) $owner->id())
      ->condition('billing_schedule', 'mel_pro_monthly')
      ->execute();
    if ($ids !== []) {
      $storage->delete($storage->loadMultiple($ids));
    }
  }

}
