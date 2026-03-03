<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_boost\Kernel;

use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductType;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\commerce_store\Entity\Store;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_boost\Access\BoostRouteAccess;
use Drupal\myeventlane_boost\Form\BoostSelectForm;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Kernel tests for boost route access and form rendering.
 *
 * @group myeventlane_boost
 */
#[\Drupal\Tests\RunTestsInSeparateProcesses]
final class BoostAccessKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'text',
    'options',
    'path',
    'path_alias',
    'address',
    'state_machine',
    'profile',
    'commerce',
    'commerce_price',
    'commerce_store',
    'commerce_product',
    'commerce_order',
    'commerce_cart',
    'myeventlane_core',
    'myeventlane_schema',
    'myeventlane_event',
    'myeventlane_boost',
  ];

  /**
   * Vendor owner with purchase permission and stripe connected.
   */
  private UserInterface $vendorWithPermission;

  /**
   * Vendor owner without purchase permission.
   */
  private UserInterface $vendorWithoutPermission;

  /**
   * Non-owner user with purchase permission.
   */
  private UserInterface $otherVendorWithPermission;

  /**
   * Admin user with boost admin permission.
   */
  private UserInterface $adminUser;

  /**
   * Published event owned by vendorWithPermission.
   */
  private Node $publishedEvent;

  /**
   * Published event owned by vendorWithoutPermission.
   */
  private Node $publishedEventNoPermission;

  /**
   * Unpublished event owned by vendorWithPermission.
   */
  private Node $unpublishedEvent;

  /**
   * Non-event node.
   */
  private Node $pageNode;

  /**
   * IDs of published boost variations.
   *
   * @var int[]
   */
  private array $boostVariationIds = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('commerce_currency');
    $this->installEntitySchema('commerce_store');
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');

    $this->installConfig([
      'node',
      'system',
      'user',
      'commerce_price',
      'commerce_store',
      'commerce_product',
      'commerce_order',
      'myeventlane_boost',
    ]);

    $this->ensureNodeTypes();
    $this->ensureRoles();
    $this->ensureCommerceDefaults();
    $this->ensureStripeFields();
    $this->ensureBoostCatalogSchema();
    $this->createUsersAndStores();
    $this->createNodes();
    $this->createBoostProductAndVariations();
  }

  /**
   * Tests owner + permission + published + stripe connected is allowed.
   */
  public function testOwnerWithPermissionPublishedStripeConnectedAllowed(): void {
    $result = $this->accessChecker()->access($this->publishedEvent, $this->vendorWithPermission);
    $this->assertTrue($result->isAllowed());
    $this->assertAccessResultCacheability($result);
  }

  /**
   * Tests owner missing purchase permission is forbidden.
   */
  public function testOwnerMissingPermissionForbidden(): void {
    $result = $this->accessChecker()->access($this->publishedEventNoPermission, $this->vendorWithoutPermission);
    $this->assertTrue($result->isForbidden());
    $this->assertAccessResultCacheability($result);
  }

  /**
   * Tests non-owner with purchase permission is forbidden.
   */
  public function testNotOwnerWithPermissionForbidden(): void {
    $result = $this->accessChecker()->access($this->publishedEvent, $this->otherVendorWithPermission);
    $this->assertTrue($result->isForbidden());
    $this->assertAccessResultCacheability($result);
  }

  /**
   * Tests owner with unpublished event is forbidden.
   */
  public function testOwnerUnpublishedForbidden(): void {
    $result = $this->accessChecker()->access($this->unpublishedEvent, $this->vendorWithPermission);
    $this->assertTrue($result->isForbidden());
    $this->assertAccessResultCacheability($result);
  }

  /**
   * Tests owner with permission but without Stripe is forbidden.
   */
  public function testOwnerWithPermissionWithoutStripeForbidden(): void {
    $event = Node::create([
      'type' => 'event',
      'title' => 'Published Event Owner Missing Stripe',
      'status' => 1,
      'uid' => $this->otherVendorWithPermission->id(),
    ]);
    $event->save();

    $result = $this->accessChecker()->access($event, $this->otherVendorWithPermission);
    $this->assertTrue($result->isForbidden());
    $this->assertAccessResultCacheability($result);
  }

  /**
   * Tests admin override is allowed regardless of ownership/published/stripe.
   */
  public function testAdminOverrideAllowed(): void {
    $result = $this->accessChecker()->access($this->unpublishedEvent, $this->adminUser);
    $this->assertTrue($result->isAllowed());
    $this->assertAccessResultCacheability($result);
  }

  /**
   * Tests non-event bundle node is forbidden.
   */
  public function testNonEventBundleForbidden(): void {
    $result = $this->accessChecker()->access($this->pageNode, $this->vendorWithPermission);
    $this->assertTrue($result->isForbidden());
    $this->assertAccessResultCacheability($result);
  }

  /**
   * Tests boost select form build includes radios and row containers.
   */
  public function testBoostSelectFormBuildContainsRadiosAndRows(): void {
    $form = $this->container->get('form_builder')->getForm(BoostSelectForm::class, $this->publishedEvent);

    $this->assertArrayHasKey('choices', $form);
    $this->assertSame('radios', $form['choices']['#type']);
    $this->assertNotEmpty($form['choices']['#options']);
    $this->assertCount(2, $form['choices']['#options']);

    $this->assertArrayHasKey('styled_list', $form);
    $this->assertSame('container', $form['styled_list']['#type']);

    foreach ($this->boostVariationIds as $variationId) {
      $this->assertArrayHasKey((string) $variationId, $form['styled_list']);
      $this->assertSame('container', $form['styled_list'][(string) $variationId]['#type']);
      $this->assertContains('boost-row', $form['styled_list'][(string) $variationId]['#attributes']['class']);
    }
  }

  /**
   * Asserts cacheability metadata is valid and regression-safe.
   */
  private function assertAccessResultCacheability(AccessResultInterface $result): void {
    $contexts = $result->getCacheContexts();

    $this->assertNotEmpty($contexts);
    $this->assertContains('user', $contexts);
    $this->assertContains('user.permissions', $contexts);
    $this->assertNotContains('node', $contexts);

    $this->container->get('cache_contexts_manager')->validateTokens($contexts);
  }

  /**
   * Ensures required node bundles exist.
   */
  private function ensureNodeTypes(): void {
    if (!NodeType::load('event')) {
      NodeType::create([
        'type' => 'event',
        'name' => 'Event',
      ])->save();
    }

    if (!NodeType::load('page')) {
      NodeType::create([
        'type' => 'page',
        'name' => 'Page',
      ])->save();
    }
  }

  /**
   * Ensures roles used by tests exist.
   */
  private function ensureRoles(): void {
    if (!Role::load('vendor_boost_purchase')) {
      Role::create([
        'id' => 'vendor_boost_purchase',
        'label' => 'Vendor Boost Purchase',
      ])->grantPermission('purchase boost for events')->save();
    }

    if (!Role::load('vendor_boost_no_purchase')) {
      Role::create([
        'id' => 'vendor_boost_no_purchase',
        'label' => 'Vendor Boost No Purchase',
      ])->save();
    }

    if (!Role::load('boost_admin')) {
      Role::create([
        'id' => 'boost_admin',
        'label' => 'Boost Admin',
      ])->grantPermission('administer myeventlane boost')->save();
    }
  }

  /**
   * Ensures baseline commerce entities/config required by fixtures exist.
   */
  private function ensureCommerceDefaults(): void {
    if (!Currency::load('AUD')) {
      Currency::create([
        'currencyCode' => 'AUD',
        'name' => 'Australian Dollar',
        'numericCode' => '036',
        'symbol' => '$',
        'fractionDigits' => 2,
      ])->save();
    }

    $storeTypeStorage = $this->container->get('entity_type.manager')->getStorage('commerce_store_type');
    if (!$storeTypeStorage->load('online')) {
      $storeTypeStorage->create([
        'id' => 'online',
        'label' => 'Online',
      ])->save();
    }

    if (!OrderType::load('default')) {
      OrderType::create([
        'id' => 'default',
        'label' => 'Default',
        'workflow' => 'order_default',
      ])->save();
    }

    if (!OrderItemType::load('default')) {
      OrderItemType::create([
        'id' => 'default',
        'label' => 'Default',
        'orderType' => 'default',
        'purchasableEntityType' => 'commerce_product_variation',
      ])->save();
    }
  }

  /**
   * Ensures stripe fields exist on commerce_store online bundle.
   */
  private function ensureStripeFields(): void {
    if (!FieldStorageConfig::loadByName('commerce_store', 'field_stripe_account_id')) {
      FieldStorageConfig::create([
        'field_name' => 'field_stripe_account_id',
        'entity_type' => 'commerce_store',
        'type' => 'string',
      ])->save();
    }

    if (!FieldConfig::loadByName('commerce_store', 'online', 'field_stripe_account_id')) {
      FieldConfig::create([
        'field_name' => 'field_stripe_account_id',
        'entity_type' => 'commerce_store',
        'bundle' => 'online',
        'label' => 'Stripe Account ID',
      ])->save();
    }

    if (!FieldStorageConfig::loadByName('commerce_store', 'field_stripe_connected')) {
      FieldStorageConfig::create([
        'field_name' => 'field_stripe_connected',
        'entity_type' => 'commerce_store',
        'type' => 'boolean',
      ])->save();
    }

    if (!FieldConfig::loadByName('commerce_store', 'online', 'field_stripe_connected')) {
      FieldConfig::create([
        'field_name' => 'field_stripe_connected',
        'entity_type' => 'commerce_store',
        'bundle' => 'online',
        'label' => 'Stripe Connected',
      ])->save();
    }
  }

  /**
   * Ensures boost product schema exists.
   */
  private function ensureBoostCatalogSchema(): void {
    if (!ProductVariationType::load('boost_duration')) {
      ProductVariationType::create([
        'id' => 'boost_duration',
        'label' => 'Boost Duration',
        'orderItemType' => 'default',
        'generateTitle' => TRUE,
      ])->save();
    }

    if (!ProductType::load('boost_upgrade')) {
      ProductType::create([
        'id' => 'boost_upgrade',
        'label' => 'Boost Upgrade',
        'variationType' => 'boost_duration',
        'injectVariationFields' => TRUE,
      ])->save();
    }

    if (!FieldStorageConfig::loadByName('commerce_product_variation', 'field_boost_days')) {
      FieldStorageConfig::create([
        'field_name' => 'field_boost_days',
        'entity_type' => 'commerce_product_variation',
        'type' => 'integer',
      ])->save();
    }

    if (!FieldConfig::loadByName('commerce_product_variation', 'boost_duration', 'field_boost_days')) {
      FieldConfig::create([
        'field_name' => 'field_boost_days',
        'entity_type' => 'commerce_product_variation',
        'bundle' => 'boost_duration',
        'label' => 'Boost Days',
      ])->save();
    }
  }

  /**
   * Creates fixture users and required stores.
   */
  private function createUsersAndStores(): void {
    // Reserve UID 1 so vendor fixtures are explicitly non-admin/non-UID1.
    User::create([
      'name' => 'bootstrap_user',
      'mail' => 'bootstrap@example.test',
      'status' => 1,
    ])->save();

    $this->vendorWithPermission = User::create([
      'name' => 'vendor_with_permission',
      'mail' => 'vendor_with_permission@example.test',
      'status' => 1,
      'roles' => ['vendor_boost_purchase'],
    ]);
    $this->vendorWithPermission->save();

    $this->vendorWithoutPermission = User::create([
      'name' => 'vendor_without_permission',
      'mail' => 'vendor_without_permission@example.test',
      'status' => 1,
      'roles' => ['vendor_boost_no_purchase'],
    ]);
    $this->vendorWithoutPermission->save();

    $this->otherVendorWithPermission = User::create([
      'name' => 'other_vendor_with_permission',
      'mail' => 'other_vendor_with_permission@example.test',
      'status' => 1,
      'roles' => ['vendor_boost_purchase'],
    ]);
    $this->otherVendorWithPermission->save();

    $this->adminUser = User::create([
      'name' => 'boost_admin_user',
      'mail' => 'boost_admin_user@example.test',
      'status' => 1,
      'roles' => ['boost_admin'],
    ]);
    $this->adminUser->save();

    $this->assertNotSame(1, (int) $this->vendorWithPermission->id());

    $this->createConnectedStoreForOwner($this->vendorWithPermission, 'Vendor Connected Store');
    $this->createConnectedStoreForOwner($this->vendorWithoutPermission, 'Vendor No Permission Connected Store');
  }

  /**
   * Creates a stripe-connected store for owner.
   */
  private function createConnectedStoreForOwner(UserInterface $owner, string $name): void {
    $store = Store::create([
      'type' => 'online',
      'uid' => $owner->id(),
      'name' => $name,
      'mail' => strtolower(str_replace(' ', '-', $name)) . '@example.test',
      'default_currency' => 'AUD',
      'status' => TRUE,
      'is_default' => FALSE,
    ]);
    $store->set('field_stripe_account_id', 'acct_' . $owner->id());
    $store->set('field_stripe_connected', 1);
    $store->save();
  }

  /**
   * Creates node fixtures for all access permutations.
   */
  private function createNodes(): void {
    $this->publishedEvent = Node::create([
      'type' => 'event',
      'title' => 'Published Event Owner Allowed',
      'status' => 1,
      'uid' => $this->vendorWithPermission->id(),
    ]);
    $this->publishedEvent->save();

    $this->publishedEventNoPermission = Node::create([
      'type' => 'event',
      'title' => 'Published Event Owner Missing Permission',
      'status' => 1,
      'uid' => $this->vendorWithoutPermission->id(),
    ]);
    $this->publishedEventNoPermission->save();

    $this->unpublishedEvent = Node::create([
      'type' => 'event',
      'title' => 'Unpublished Event Owner Forbidden',
      'status' => 0,
      'uid' => $this->vendorWithPermission->id(),
    ]);
    $this->unpublishedEvent->save();

    $this->pageNode = Node::create([
      'type' => 'page',
      'title' => 'Non-event bundle node',
      'status' => 1,
      'uid' => $this->vendorWithPermission->id(),
    ]);
    $this->pageNode->save();
  }

  /**
   * Creates boost product and published variations for form build assertions.
   */
  private function createBoostProductAndVariations(): void {
    $storeStorage = $this->container->get('entity_type.manager')->getStorage('commerce_store');
    $storeIds = $storeStorage->getQuery()->accessCheck(FALSE)->range(0, 1)->execute();
    $storeId = (int) reset($storeIds);

    $product = Product::create([
      'type' => 'boost_upgrade',
      'title' => 'Event Boost',
      'status' => 1,
      'stores' => [$storeId],
    ]);
    $product->save();

    $variation7 = ProductVariation::create([
      'type' => 'boost_duration',
      'sku' => 'boost_7d',
      'title' => 'Boost 7 Days',
      'status' => 1,
      'price' => new Price('10.00', 'AUD'),
      'field_boost_days' => 7,
    ]);
    $variation7->save();

    $variation14 = ProductVariation::create([
      'type' => 'boost_duration',
      'sku' => 'boost_14d',
      'title' => 'Boost 14 Days',
      'status' => 1,
      'price' => new Price('18.00', 'AUD'),
      'field_boost_days' => 14,
    ]);
    $variation14->save();

    $product->addVariation($variation7);
    $product->addVariation($variation14);
    $product->save();

    $this->boostVariationIds = [
      (int) $variation7->id(),
      (int) $variation14->id(),
    ];
  }

  /**
   * Returns the boost route access checker service.
   */
  private function accessChecker(): BoostRouteAccess {
    return $this->container->get('myeventlane_boost.access');
  }

}

