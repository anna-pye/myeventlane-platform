<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Kernel;

use Drupal\commerce\Context;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductType;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\commerce_stock_local\LocalStockService;
use Drupal\commerce_stock_local\Entity\StockLocation;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\myeventlane_commerce\Exception\OperationalStockUnavailableException;
use Drupal\myeventlane_commerce\Service\OperationalStockHoldManager;
use Drupal\myeventlane_commerce\Service\OperationalStockHoldStore;
use Drupal\myeventlane_commerce\Service\OperationalStockLocations;
use Drupal\myeventlane_commerce\Service\OperationalStockMigration;
use Drupal\myeventlane_commerce\Service\OperationalStockOrderGuard;
use Drupal\myeventlane_commerce\Service\OperationalStockSaleManager;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use Psr\Log\NullLogger;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Uses real Commerce entities, stock transactions, holds and database locks.
 *
 * @group myeventlane_commerce
 */
#[RunTestsInSeparateProcesses]
final class OperationalStockLifecycleKernelTest extends CommerceKernelTestBase {

  // MEL is deliberately not enabled in this isolated Commerce fixture. Its
  // schema alter adds the dynamic variation mappings in a full installation.
  protected static $configSchemaCheckerExclusions = [
    'commerce_stock.service_manager',
    'myeventlane_commerce.operational_stock',
  ];

  protected static $modules = [
    'commerce_product', 'commerce_order', 'commerce_number_pattern',
    'profile', 'entity_reference_revisions', 'state_machine', 'node', 'text',
    'commerce_stock', 'commerce_stock_local', 'commerce_stock_field',
  ];

  private OperationalStockHoldStore $holdStore;
  private OperationalStockHoldManager $holds;
  private OperationalStockSaleManager $sales;
  private OperationalStockLocations $locations;
  private int $now = 1000;

  protected function setUp(): void {
    parent::setUp();
    foreach (['commerce_product', 'commerce_product_variation', 'commerce_order', 'commerce_order_item', 'commerce_stock_location', 'profile', 'node'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['commerce_product', 'commerce_order', 'commerce_stock', 'commerce_stock_local', 'commerce_stock_field']);
    \Drupal\commerce_order\Entity\OrderType::load('default')->setSendReceipt(FALSE)->save();
    \Drupal\node\Entity\NodeType::create(['type' => 'event', 'name' => 'Event'])->save();
    \Drupal\field\Entity\FieldStorageConfig::create([
      'entity_type' => 'commerce_order_item', 'field_name' => 'field_target_event',
      'type' => 'entity_reference', 'settings' => ['target_type' => 'node'],
    ])->save();
    \Drupal\field\Entity\FieldConfig::create([
      'entity_type' => 'commerce_order_item', 'bundle' => 'default',
      'field_name' => 'field_target_event', 'label' => 'Event',
    ])->save();
    $this->installSchema('commerce_stock_local', ['commerce_stock_transaction_type', 'commerce_stock_transaction', 'commerce_stock_location_level']);
    $this->installSchema('commerce_number_pattern', ['commerce_number_pattern_sequence']);
    require_once DRUPAL_ROOT . '/modules/custom/myeventlane_commerce/myeventlane_commerce.install';
    $database = $this->container->get('database');
    $schema = myeventlane_commerce_schema();
    foreach (['myeventlane_commerce_operational_stock_hold', 'myeventlane_commerce_operational_stock_sale', 'myeventlane_commerce_operational_stock_return'] as $table) {
      $database->schema()->createTable($table, $schema[$table]);
    }
    ProductVariationType::create(['id' => 'operational_merchandise_var', 'label' => 'Extra', 'orderItemType' => 'default', 'generateTitle' => FALSE])->save();
    ProductType::create(['id' => 'operational_merchandise', 'label' => 'Extras', 'variationTypes' => ['operational_merchandise_var']])->save();
    $config = $this->container->get('config.factory');
    $config->getEditable('commerce_stock.service_manager')
      ->set('default_service_id', 'always_in_stock')
      ->set('commerce_product_variation_operational_merchandise_var_service_id', 'local_stock')->save();
    $config->getEditable('commerce_stock.core_stock_events')
      ->set('core_stock_events_order_complete_event_type', 'disabled')
      ->set('core_stock_events_order_cancel', FALSE)
      ->set('core_stock_events_order_updates', FALSE)->save();
    $config->getEditable('myeventlane_commerce.operational_stock')
      ->set('paid_stock_enabled', TRUE)->set('hold_ttl', 900)->save();
    $time = $this->createMock(\Drupal\Component\Datetime\TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn() => $this->now);
    $this->holdStore = new OperationalStockHoldStore($database, $time, $config);
    $stock = $this->container->get('commerce_stock.service_manager');
    $lock = new DatabaseLockBackend($database);
    $this->locations = new OperationalStockLocations(
      $this->container->get('commerce_stock.local_stock_service_config'),
      $this->container->get('entity_type.manager'), $this->container->get('keyvalue'),
      $lock, $database, $config,
    );
    $stock->addService(new LocalStockService(
      $this->container->get('commerce_stock.local_stock_checker'),
      $this->container->get('commerce_stock.local_stock_updater'),
      $this->locations,
    ));
    $this->holds = new OperationalStockHoldManager($stock, $this->holdStore, $lock, new NullLogger(), $database);
    $this->sales = new OperationalStockSaleManager($stock, $this->holds, $this->holdStore, $database, $config, $this->container->get('entity_type.manager'));
    $this->container->get('event_dispatcher')->addSubscriber(new \Drupal\myeventlane_commerce\EventSubscriber\OperationalStockCommerceSubscriber(
      $this->holds, $this->holdStore, $this->createMock(\Drupal\commerce_cart\CartManagerInterface::class),
      $this->container->get('request_stack'), new NullLogger(), $this->sales,
    ));
  }

  public function testPaidSaleAndReplayAreAtomic(): void {
    $variation = $this->extra(2);
    $order = $this->order($variation, 1);
    $this->holds->refresh($order);
    self::assertSame(1, $this->holdStore->getHeldQuantity((int) $variation->id()));
    $order->setTotalPaid($order->getTotalPrice());
    $this->sales->commitPaid($order);
    $this->sales->commitPaid($order);
    self::assertSame(1, $this->level($variation));
    self::assertSame(1, $this->sales->soldQuantity($order, $variation));
    self::assertSame(0, $this->holdStore->getHeldQuantity((int) $variation->id()));
  }

  public function testUnpaidPlacementDoesNotDeductStock(): void {
    $variation = $this->extra(1);
    $order = $this->order($variation, 1);
    $order->getState()->applyTransitionById('place');
    $order->save();
    self::assertSame(1, $this->level($variation));
    self::assertSame(1, $this->holdStore->getHeldQuantity((int) $variation->id()));
    $this->expectException(OperationalStockUnavailableException::class);
    $this->sales->commitPaid($order);
  }

  public function testCommercePaidEventCommitsBeforeFulfilmentListeners(): void {
    $variation = $this->extra(1);
    $order = $this->order($variation, 1);
    $called = FALSE;
    $this->container->get('event_dispatcher')->addListener(\Drupal\commerce_order\Event\OrderEvents::ORDER_PAID,
      function () use ($variation, &$called): void {
        self::assertSame(0, $this->level($variation));
        $called = TRUE;
      }, 0);
    $order->setTotalPaid($order->getTotalPrice());
    $order->save();
    self::assertTrue($called);
    $order->getState()->applyTransitionById('place');
    $order->save();
    self::assertSame(1, $this->sales->soldQuantity($order, $variation), 'Placement after payment never deducts twice.');
  }

  public function testExpiredHoldDoesNotOversellOnLatePayment(): void {
    $variation = $this->extra(1);
    $first = $this->order($variation, 1);
    $second = $this->order($variation, 1);
    $this->holds->refresh($first);
    $this->now += 901;
    $this->holds->refresh($second);
    $second->setTotalPaid($second->getTotalPrice());
    $this->sales->commitPaid($second);
    self::assertSame(0, $this->level($variation));
    $first->setTotalPaid($first->getTotalPrice());
    $this->expectException(OperationalStockUnavailableException::class);
    $this->sales->commitPaid($first);
  }

  public function testCompetingBuyerCannotEnterBeforeCommit(): void {
    $variation = $this->extra(1);
    $order = $this->order($variation, 1);
    $database = $this->container->get('database');
    $competitor = new DatabaseLockBackend($database);
    $transaction = $database->startTransaction();
    $order->setTotalPaid($order->getTotalPrice());
    $this->sales->commitPaid($order);
    $name = 'myeventlane_operational_stock:' . $variation->id();
    self::assertFalse($competitor->acquire($name), 'The first checkout retains the lock until its outer transaction commits.');
    unset($transaction);
    self::assertTrue($competitor->acquire($name), 'The lock releases after commit.');
    $competitor->release($name);
    $second = $this->order($variation, 1);
    $second->setTotalPaid($second->getTotalPrice());
    $this->expectException(OperationalStockUnavailableException::class);
    $this->sales->commitPaid($second);
  }

  public function testRollbackRestoresStockReceiptAndHold(): void {
    $variation = $this->extra(1);
    $order = $this->order($variation, 1);
    $this->holds->refresh($order);
    $transaction = $this->container->get('database')->startTransaction();
    $order->setTotalPaid($order->getTotalPrice());
    $this->sales->commitPaid($order);
    $transaction->rollBack();
    unset($transaction);
    self::assertSame(1, $this->level($variation));
    self::assertSame(1, $this->holdStore->getHeldQuantity((int) $variation->id()));
    self::assertSame(0, $this->sales->soldQuantity($order, $variation));
    $this->sales->commitPaid($order);
    self::assertSame(0, $this->level($variation));
  }

  public function testSeparateDatabaseProcessSeesCommittedFinalUnit(): void {
    $db = $this->container->get('database');
    $options = $db->getConnectionOptions();
    if ($options['driver'] !== 'mysql') {
      self::markTestSkipped('Cross-process lock test requires the MariaDB test run.');
    }
    $variation = $this->extra(1);
    $order = $this->order($variation, 1);
    $transaction = $db->startTransaction();
    $order->setTotalPaid($order->getTotalPrice());
    $this->sales->commitPaid($order);
    // An independent database connection competes for the exact semaphore used
    // by checkout. It must not read stale availability before the sale commits.
    $worker = <<<'PHP'
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$o = $input['options'];
$prefix = is_array($o['prefix']) ? ($o['prefix']['default'] ?? '') : $o['prefix'];
if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) { exit(2); }
$pdo = new PDO('mysql:host=' . $o['host'] . ';port=' . ($o['port'] ?: 3306) . ';dbname=' . $o['database'], $o['username'], $o['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$insert = $pdo->prepare('INSERT INTO ' . $prefix . 'semaphore (name,value,expire) VALUES (?,?,?)');
$name = 'myeventlane_operational_stock:' . $input['variation'];
fwrite(STDOUT, "READY\n");
$deadline = microtime(true) + 8;
while (true) {
  try { $insert->execute([$name, 'independent-test-worker', microtime(true) + 30]); break; }
  catch (PDOException $e) {
    if (($e->errorInfo[1] ?? 0) !== 1062 || microtime(true) > $deadline) { throw $e; }
    usleep(10000);
  }
}
$read = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM ' . $prefix . 'commerce_stock_transaction WHERE entity_id=? AND entity_type=?');
$read->execute([$input['variation'], 'commerce_product_variation']);
fwrite(STDOUT, 'AVAILABLE=' . (int) $read->fetchColumn());
$pdo->prepare('DELETE FROM ' . $prefix . 'semaphore WHERE name=? AND value=?')->execute([$name, 'independent-test-worker']);
PHP;
    $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $worker], timeout: 12);
    $process->setInput(json_encode(['options' => $options, 'variation' => (int) $variation->id()], JSON_THROW_ON_ERROR));
    $process->start();
    try {
      self::assertTrue($process->waitUntil(static fn($type, $output) => str_contains($output, 'READY')));
      usleep(100000);
      self::assertStringNotContainsString('AVAILABLE=', $process->getOutput(), 'The competitor cannot enter during the sale transaction.');
      unset($transaction);
      self::assertSame(0, $process->wait(), $process->getErrorOutput());
      self::assertStringContainsString('AVAILABLE=0', $process->getOutput(), 'The competing process sees the committed final-unit sale.');
    }
    finally {
      if ($process->isRunning()) {
        $process->stop();
      }
    }
  }

  public function testUnlimitedReceiptSurvivesLaterFiniteStock(): void {
    $variation = $this->extra(4);
    $variation->set('commerce_stock_always_in_stock', TRUE)->save();
    $order = $this->order($variation, 1);
    $order->setTotalPaid($order->getTotalPrice());
    $this->sales->commitPaid($order);
    $variation->set('commerce_stock_always_in_stock', FALSE)->save();
    $this->sales->commitPaid($order);
    self::assertSame(4, $this->level($variation), 'Changing catalogue stock mode cannot deduct an old unlimited sale.');
  }

  public function testOrganiserLocationsRejectCrossStoreOrders(): void {
    $variation = $this->extra(3);
    $other = $this->createStore('Other organiser', 'other@example.test');
    $firstLocation = $this->locations->ensureLocation($this->store);
    $otherLocation = $this->locations->ensureLocation($other);
    self::assertNotSame($firstLocation->getId(), $otherLocation->getId());
    $this->expectException(\LogicException::class);
    $this->locations->getAvailabilityLocations(new Context($this->container->get('current_user'), $other), $variation);
  }

  public function testPaidQuantityChangeIsRejected(): void {
    $variation = $this->extra(3);
    $order = $this->order($variation, 1);
    $order->setTotalPaid($order->getTotalPrice());
    $this->sales->commitPaid($order);
    $item = $order->getItems()[0];
    $item->setQuantity('2');
    $order->recalculateTotalPrice();
    $order->setTotalPaid($order->getTotalPrice());
    $this->expectException(OperationalStockUnavailableException::class);
    $this->sales->commitPaid($order);
  }

  public function testMigrationTransfersBalancesAndPreservesHistory(): void {
    $variation = $this->extra(0);
    $stock = $this->container->get('commerce_stock.service_manager');
    $old = StockLocation::create(['type' => 'default', 'name' => 'Legacy shared', 'status' => TRUE]);
    $old->save();
    $stock->receiveStock($variation, $old->id(), '', 7, NULL, NULL, 'Legacy fixture');
    $db = $this->container->get('database');
    $before = $db->select('commerce_stock_transaction', 't')->fields('t')->execute()->fetchAllAssoc('id');
    $migration = new OperationalStockMigration($stock, $this->locations, $this->holds, $this->sales,
      $this->container->get('entity_type.manager'), $db, $this->container->get('config.factory'), $this->container->get('keyvalue'));
    $migration->migrate();
    self::assertSame(7, $this->level($variation));
    self::assertSame(0, (int) $stock->getService($variation)->getStockChecker()->getTotalStockLevel($variation, [$old->id() => $old]));
    $after = $db->select('commerce_stock_transaction', 't')->fields('t')->execute()->fetchAllAssoc('id');
    foreach ($before as $id => $row) {
      self::assertEquals($row, $after[$id], 'Original transaction is unchanged.');
    }
    $count = count($after);
    $migration->migrate();
    self::assertSame($count, (int) $db->select('commerce_stock_transaction')->countQuery()->execute()->fetchField());
  }

  public function testUnpaidCancellationCannotCreateStock(): void {
    $variation = $this->extra(2);
    $order = $this->order($variation, 1);
    self::assertSame(0, $this->returns([])->reconcileCancellation($order, 'completed'));
    self::assertSame(2, $this->level($variation));
  }

  public function testPaidCancellationReturnsOnceAcrossRetries(): void {
    $variation = $this->extra(2);
    $order = $this->order($variation, 1);
    $order->setTotalPaid($order->getTotalPrice());
    $this->sales->commitPaid($order);
    $returns = $this->returns([]);
    self::assertSame(1, $returns->reconcileCancellation($order, 'completed'));
    self::assertSame(0, $returns->reconcileCancellation($order, 'completed'));
    self::assertSame(2, $this->level($variation));
  }

  public function testCollectedUnitNeverReturnsToStockOnCancellation(): void {
    $variation = $this->extra(2);
    $order = $this->order($variation, 1);
    $order->setTotalPaid($order->getTotalPrice());
    $this->sales->commitPaid($order);
    $entitlement = $this->entitlement('assigned', 'collected');
    self::assertSame(0, $this->returns([$entitlement])->reconcileCancellation($order, 'completed'));
    self::assertSame(1, $this->level($variation));
  }

  public function testStockConfigurationDriftFailsClosed(): void {
    $variation = $this->extra(2);
    $order = $this->order($variation, 1);
    $order->setTotalPaid($order->getTotalPrice());
    $this->container->get('config.factory')->getEditable('commerce_stock.core_stock_events')
      ->set('core_stock_events_order_updates', TRUE)->save();
    $this->expectException(OperationalStockUnavailableException::class);
    $this->sales->commitPaid($order);
  }

  public function testRefundRestocksOnlyUncollectedUnitsAndCannotReplay(): void {
    $variation = $this->extra(3);
    $order = $this->order($variation, 2);
    $event = \Drupal\node\Entity\Node::create(['type' => 'event', 'title' => 'Fixture event']);
    $event->save();
    $item = $order->getItems()[0];
    $item->set('field_target_event', $event->id())->save();
    $order->setTotalPaid($order->getTotalPrice());
    $this->sales->commitPaid($order);
    $ready = $this->entitlement('assigned', 'ready');
    $collected = $this->entitlement('assigned', 'collected');
    $returns = $this->returns([$ready, $collected]);
    $log = ['id' => 123, 'operational_item_quantities_json' => json_encode([$item->id() => 2])];
    self::assertSame(2, $returns->reconcile($order, $event, $log));
    self::assertSame(2, $this->level($variation), 'Only the ready unit is restocked.');
    self::assertSame('refunded', $ready->get('status')->value);
    self::assertSame('cancelled', $ready->get('fulfilment_status')->value);
    self::assertSame('refunded', $collected->get('status')->value);
    self::assertSame('collected', $collected->get('fulfilment_status')->value);
    self::assertSame(0, $returns->reconcile($order, $event, $log));
    self::assertSame(0, $returns->reconcileCancellation($order, 'completed'));
    self::assertSame(2, $this->level($variation));
  }

  public function testFiniteSaleCanReturnAfterCatalogueBecomesUnlimited(): void {
    $variation = $this->extra(2);
    $order = $this->order($variation, 1);
    $order->setTotalPaid($order->getTotalPrice());
    $this->sales->commitPaid($order);
    $variation->set('commerce_stock_always_in_stock', TRUE)->save();
    self::assertSame(1, $this->returns([])->reconcileCancellation($order, 'completed'));
    $variation->set('commerce_stock_always_in_stock', FALSE)->save();
    self::assertSame(2, $this->level($variation));
  }

  public function testPlacedItemGuardRejectsDeletionAndQuantityChanges(): void {
    $variation = $this->extra(3);
    $order = $this->order($variation, 1);
    $order->setTotalPaid($order->getTotalPrice());
    $order->save();
    $item = $order->getItems()[0];
    $item->setOriginal(clone $item);
    $guard = new OperationalStockOrderGuard($this->sales);
    $guard->protectItem($item);
    $item->setQuantity('2');
    try {
      $guard->protectItem($item);
      self::fail('Paid quantity edits must be rejected.');
    }
    catch (OperationalStockUnavailableException) {
      self::assertSame(2, $this->level($variation));
    }
    $this->expectException(OperationalStockUnavailableException::class);
    $guard->protectOrder($order, TRUE);
  }

  public function testOrganiserStockSaveRetainsLockUntilCommit(): void {
    $variation = $this->extra(3);
    \Drupal\field\Entity\FieldStorageConfig::create([
      'entity_type' => 'commerce_product_variation', 'field_name' => 'field_stock_level',
      'type' => 'commerce_stock_level',
    ])->save();
    \Drupal\field\Entity\FieldConfig::create([
      'entity_type' => 'commerce_product_variation', 'bundle' => 'operational_merchandise_var',
      'field_name' => 'field_stock_level', 'label' => 'Stock',
    ])->save();
    $variation = $this->container->get('entity_type.manager')->getStorage('commerce_product_variation')->loadUnchanged($variation->id());
    $db = $this->container->get('database');
    $resolver = new \Drupal\myeventlane_commerce\Service\OperationalVariationStockResolver(
      $this->container->get('string_translation'), $this->container->get('commerce_stock.service_manager'),
      $this->holdStore, $this->holds, $db,
    );
    $transaction = $db->startTransaction();
    $resolver->saveStockFields($variation, ['stock_quantity' => 5]);
    self::assertSame(5, $this->level($variation));
    $competitor = new DatabaseLockBackend($db);
    $lock = 'myeventlane_operational_stock:' . $variation->id();
    self::assertFalse($competitor->acquire($lock));
    unset($transaction);
    self::assertTrue($competitor->acquire($lock));
    $competitor->release($lock);
    $this->holds->refresh($this->order($variation, 2));
    $this->expectException(\InvalidArgumentException::class);
    $resolver->saveStockFields($variation, ['stock_quantity' => 1]);
  }

  /**
   * Real stock database with a controlled entitlement-storage fixture.
   */
  private function returns(array $entitlements): \Drupal\myeventlane_tickets\Service\OperationalStockReturnManager {
    $storage = $this->createMock(\Drupal\Core\Entity\EntityStorageInterface::class);
    $query = $this->createMock(\Drupal\Core\Entity\Query\QueryInterface::class);
    foreach (['accessCheck', 'condition', 'sort'] as $method) {
      $query->method($method)->willReturnSelf();
    }
    $query->method('execute')->willReturn(array_keys($entitlements));
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn($entitlements);
    $manager = $this->createMock(\Drupal\Core\Entity\EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('myeventlane_ticket')->willReturn($storage);
    return new \Drupal\myeventlane_tickets\Service\OperationalStockReturnManager(
      $manager, $this->container->get('database'), $this->container->get('commerce_stock.service_manager'),
      new DatabaseLockBackend($this->container->get('database')), new NullLogger(),
    );
  }

  private function entitlement(string $status, string $fulfilment): \Drupal\Core\Entity\ContentEntityInterface {
    $entity = $this->createMock(\Drupal\Core\Entity\ContentEntityInterface::class);
    $values = ['status' => $status, 'fulfilment_status' => $fulfilment];
    $entity->method('get')->willReturnCallback(function (string $name) use (&$values) {
      $field = $this->createMock(\Drupal\Core\Field\FieldItemListInterface::class);
      $field->method('__isset')->with('value')->willReturn(TRUE);
      $field->method('__get')->with('value')->willReturn($values[$name]);
      return $field;
    });
    $entity->method('set')->willReturnCallback(function (string $name, $value) use (&$values, $entity) {
      $values[$name] = $value;
      return $entity;
    });
    return $entity;
  }

  private function extra(int $stockLevel): ProductVariation {
    $variation = ProductVariation::create([
      'type' => 'operational_merchandise_var', 'sku' => $this->randomMachineName(),
      'title' => 'Shirt', 'status' => TRUE, 'price' => new Price('10', 'USD'),
      'commerce_stock_always_in_stock' => FALSE,
    ]);
    $variation->save();
    $product = Product::create(['type' => 'operational_merchandise', 'title' => 'Shirt',
      'stores' => [$this->store], 'variations' => [$variation], 'status' => TRUE]);
    $product->save();
    $variation = ProductVariation::load($variation->id());
    $location = $this->locations->ensureLocation($this->store);
    if ($stockLevel > 0) {
      $this->container->get('commerce_stock.service_manager')->receiveStock($variation, $location->getId(), '', $stockLevel, NULL, NULL);
    }
    return $variation;
  }

  private function order(ProductVariation $variation, int $quantity): Order {
    $item = OrderItem::create(['type' => 'default', 'purchased_entity' => $variation, 'quantity' => (string) $quantity,
      'unit_price' => new Price('10', 'USD')]);
    $item->save();
    $order = Order::create(['type' => 'default', 'store_id' => $this->store, 'order_items' => [$item], 'state' => 'draft',
      'mail' => 'buyer@example.test', 'uid' => 0]);
    $order->save();
    return $order;
  }

  private function level(ProductVariation $variation): int {
    return (int) $this->container->get('commerce_stock.service_manager')->getStockLevel($variation);
  }

}
