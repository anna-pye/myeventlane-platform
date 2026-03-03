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

/**
 * Kernel tests for Pro auto-boost and comms overrides.
 *
 * @group myeventlane_pro
 */
final class ProAutoBoostAndCommsKernelTest extends KernelTestBase {

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
    'field',
    'views',
    'options',
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
    $this->installEntitySchema('commerce_subscription');
    $this->installEntitySchema('myeventlane_boost_entitlement');
    $this->installEntitySchema('myeventlane_vendor');
    if ($this->container->get('entity_type.manager')->hasDefinition('advancedqueue_queue')) {
      $this->installEntitySchema('advancedqueue_queue');
    }
    if ($this->container->get('entity_type.manager')->hasDefinition('advancedqueue_job')) {
      $this->installEntitySchema('advancedqueue_job');
    }
    $this->installConfig([
      'node',
      'commerce_order',
      'commerce_product',
      'commerce_store',
      'advancedqueue',
      'myeventlane_messaging',
      'myeventlane_boost',
      'myeventlane_pro',
    ]);

    if (!Role::load('pro_organiser')) {
      Role::create([
        'id' => 'pro_organiser',
        'label' => 'Pro Organiser',
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
      'ticket_body' => '<p>Hello [customer:first_name], total [order:total].</p>',
      'brand_signature' => '<p>Thanks from vendor.</p>',
      'updated' => $this->container->get('datetime.time')->getRequestTime(),
    ])->save();

    $resolver = $this->container->get('myeventlane_pro.vendor_comms_resolver');
    $body = $resolver->resolveBody($store, 'order_receipt', [
      'first_name' => 'Chris',
      'order_total' => '19.00',
    ]);
    $this->assertIsString($body);
    $this->assertStringContainsString('Hello', (string) $body);
    $this->assertStringContainsString('MyEventLane', (string) $body);

    $store = $this->transitionStoreToInactivePro($store);
    $inactive = $resolver->resolveBody($store, 'order_receipt', [
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
      $roles[] = 'pro_organiser';
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
      $owner->removeRole('pro_organiser');
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
