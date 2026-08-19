<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Kernel;

use Drupal\advancedqueue\Job;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\Store;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Logger\LoggerChannel;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_pro\EventSubscriber\ProRecoveryAttributionSubscriber;
use Drupal\myeventlane_pro\Plugin\AdvancedQueue\JobType\ProAbandonedCartJob;
use Drupal\myeventlane_pro\Service\AbandonedCartScheduler;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolver;
use Drupal\myeventlane_vendor\Service\VendorBrandMediaManager;
use Drupal\myeventlane_vendor\Service\VendorSubscriptionService;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Kernel coverage for Pro abandoned cart engine.
 *
 * @group myeventlane_pro
 */
#[RunTestsInSeparateProcesses]
final class AbandonedCartEngineKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'address',
    'user',
    'field',
    'filter',
    'text',
    'options',
    'entity',
    'entity_reference_revisions',
    'paragraphs',
    'profile',
    'path',
    'path_alias',
    'views',
    'commerce',
    'commerce_price',
    'commerce_store',
    'commerce_order',
    'commerce_cart',
    'commerce_number_pattern',
    'commerce_payment',
    'commerce_recurring',
    'state_machine',
    'advancedqueue',
    'myeventlane_messaging',
    'myeventlane_pro',
  ];

  /**
   * The scheduler under test.
   *
   * @var \Drupal\myeventlane_pro\Service\AbandonedCartScheduler
   */
  private AbandonedCartScheduler $scheduler;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    foreach ([
      'myeventlane_checkout_flow.order_pricing_breakdown',
      'myeventlane_checkout_flow.tax_invoice_presentation',
      'myeventlane_attendee.repository_resolver',
      'myeventlane_boost.entitlement_manager',
      'myeventlane_tickets.event_access',
    ] as $serviceId) {
      $container->register($serviceId, \stdClass::class);
    }
    $container->register('logger.channel.myeventlane_vendor', LoggerChannel::class)
      ->setArguments(['myeventlane_vendor']);
    $container->register('myeventlane_core.domain_detector', DomainDetector::class)
      ->setArguments([new Reference('request_stack'), new Reference('config.factory')]);
    $container->register('myeventlane_vendor.current_vendor_resolver', CurrentVendorResolver::class)
      ->setArguments([new Reference('entity_type.manager'), new Reference('current_user'), new Reference('logger.channel.myeventlane_vendor')]);
    $container->register('myeventlane_vendor.brand_media_manager', VendorBrandMediaManager::class)
      ->setArguments([new Reference('entity_type.manager'), new Reference('file_system'), new Reference('logger.channel.myeventlane_vendor')]);
    $container->register('myeventlane_vendor.subscription', VendorSubscriptionService::class)
      ->setArguments([new Reference('entity_type.manager'), new Reference('module_handler'), new Reference('logger.channel.myeventlane_vendor')]);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('commerce_store');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    if ($this->container->get('entity_type.manager')->hasDefinition('advancedqueue_queue')) {
      $this->installEntitySchema('advancedqueue_queue');
    }
    if ($this->container->get('entity_type.manager')->hasDefinition('advancedqueue_job')) {
      $this->installEntitySchema('advancedqueue_job');
    }
    $this->installConfig(['commerce_price', 'commerce_store', 'commerce_order', 'advancedqueue']);
    $this->installSchema('commerce_number_pattern', ['commerce_number_pattern_sequence']);
    $this->installSchema('myeventlane_messaging', [
      'myeventlane_message',
      'myeventlane_message_preference',
    ]);
    foreach (['w1', 'w2'] as $step) {
      $this->container->get('config.factory')
        ->getEditable("myeventlane_messaging.template.pro_cart_abandoned_{$step}")
        ->setData([
          'enabled' => TRUE,
          'subject' => 'Finish setting up MyEventLane Pro',
          'preheader' => 'Return to your secure Pro checkout.',
          'body_html' => '<p>Hi {{ first_name }}, return to <a href="{{ cart_url }}">your Pro checkout</a>.</p>',
        ])
        ->save();
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
    $this->installSchema('myeventlane_pro', [
      'myeventlane_pro_abandoned_cart',
      'myeventlane_pro_recovery_attribution',
    ]);

    if (!Role::load('mel_pro')) {
      Role::create([
        'id' => 'mel_pro',
        'label' => 'MEL Pro',
      ])->save();
    }

    if (!OrderItemType::load('default')) {
      OrderItemType::create([
        'id' => 'default',
        'label' => 'Default',
        'orderType' => 'default',
      ])->save();
    }

    $this->scheduler = $this->container->get('myeventlane_pro.abandoned_cart_scheduler');
  }

  /**
   * Attribution row is created when a reminder is sent successfully.
   */
  public function testAttributionRowCreatedOnSend(): void {
    $owner = User::create([
      'name' => 'pro_vendor_send',
      'mail' => 'pro-send@example.com',
      'status' => 1,
      'roles' => ['authenticated', 'mel_pro'],
    ]);
    $owner->save();

    $store = $this->createStore((int) $owner->id(), 'Pro Send Store');
    $order = $this->createDraftOrder((int) $store->id(), (int) $owner->id());
    $db = $this->container->get('database');

    $trackingId = (int) $db->insert('myeventlane_pro_abandoned_cart')
      ->fields([
        'order_id' => (int) $order->id(),
        'store_id' => (int) $store->id(),
        'step' => 'w1',
        'scheduled' => $this->container->get('datetime.time')->getRequestTime() - 10,
        'status' => 'queued',
      ])
      ->execute();

    $plugin = $this->buildJobPlugin();
    $result = $plugin->process(Job::create('pro_abandoned_cart_job', ['tracking_id' => $trackingId]));
    $this->assertSame(Job::STATE_SUCCESS, $result->getState());
    $this->assertStringContainsString('Sent abandoned cart', $result->getMessage());

    $attribution = $db->select('myeventlane_pro_recovery_attribution', 'a')
      ->fields('a', ['order_id', 'tracking_step', 'recovered'])
      ->condition('order_id', (int) $order->id())
      ->condition('tracking_step', 'w1')
      ->execute()
      ->fetchAssoc();
    $this->assertIsArray($attribution);
    $this->assertSame('0', (string) $attribution['recovered']);
  }

  /**
   * Attribution rows are marked recovered when the order is placed.
   */
  public function testAttributionMarkedRecoveredOnOrderPlacement(): void {
    $owner = User::create([
      'name' => 'pro_vendor_recovery',
      'mail' => 'recovery@example.com',
      'status' => 1,
      'roles' => ['authenticated', 'mel_pro'],
    ]);
    $owner->save();

    $store = $this->createStore((int) $owner->id(), 'Recovery Store');
    $order = $this->createDraftOrder((int) $store->id(), (int) $owner->id());

    $db = $this->container->get('database');
    $db->insert('myeventlane_pro_recovery_attribution')
      ->fields([
        'order_id' => (int) $order->id(),
        'store_id' => (int) $store->id(),
        'tracking_step' => 'w1',
        'sent_at' => $this->container->get('datetime.time')->getRequestTime() - 60,
        'recovered' => 0,
        'created' => $this->container->get('datetime.time')->getRequestTime() - 60,
      ])
      ->execute();

    $subscriber = new ProRecoveryAttributionSubscriber(
      $this->container->get('database'),
      $this->container->get('datetime.time'),
      $this->container->get('logger.channel.myeventlane_pro'),
    );
    $workflow = $order->getState()->getWorkflow();
    $transition = $workflow->getTransition('place');
    $event = new WorkflowTransitionEvent($transition, $workflow, $order, 'state');
    $subscriber->onOrderPlaced($event);

    $row = $db->select('myeventlane_pro_recovery_attribution', 'a')
      ->fields('a', ['recovered', 'recovered_at', 'order_total', 'currency'])
      ->condition('order_id', (int) $order->id())
      ->execute()
      ->fetchAssoc();
    $this->assertSame('1', (string) $row['recovered']);
    $this->assertNotEmpty($row['recovered_at']);
    $this->assertSame('AUD', (string) $row['currency']);
  }

  /**
   * ROI calculation is correct for a one-month horizon.
   */
  public function testEstimateProRoi(): void {
    $owner = User::create([
      'name' => 'pro_vendor_roi',
      'mail' => 'roi@example.com',
      'status' => 1,
      'roles' => ['authenticated', 'mel_pro'],
    ]);
    $owner->save();

    $store = $this->createStore((int) $owner->id(), 'ROI Store');
    $now = $this->container->get('datetime.time')->getRequestTime();
    $db = $this->container->get('database');

    $db->insert('myeventlane_pro_recovery_attribution')
      ->fields([
        'order_id' => 9001,
        'store_id' => (int) $store->id(),
        'tracking_step' => 'w1',
        'sent_at' => $now - 3600,
        'recovered_at' => $now - 1800,
        'order_total' => '100.00',
        'currency' => 'AUD',
        'recovered' => 1,
        'created' => $now - 3600,
      ])
      ->execute();

    $db->insert('myeventlane_pro_recovery_attribution')
      ->fields([
        'order_id' => 9002,
        'store_id' => (int) $store->id(),
        'tracking_step' => 'w2',
        'sent_at' => $now - 7200,
        'recovered_at' => $now - 3600,
        'order_total' => '50.00',
        'currency' => 'AUD',
        'recovered' => 1,
        'created' => $now - 7200,
      ])
      ->execute();

    /** @var \Drupal\myeventlane_pro\Service\ProRecoveryAnalyticsService $analytics */
    $analytics = $this->container->get('myeventlane_pro.recovery_analytics');
    $roi = $analytics->estimateProROI((int) $store->id(), 1);
    $this->assertSame(49.0, $roi['pro_cost']);
    $this->assertSame(150.0, $roi['recovered_revenue']);
    $this->assertSame(3.06, $roi['roi_multiple']);
  }

  /**
   * Terminal state detection is discovered dynamically from workflow.
   */
  public function testTerminalStateDetectionIsDynamic(): void {
    $owner = User::create([
      'name' => 'pro_vendor_terminal',
      'mail' => 'terminal@example.com',
      'status' => 1,
      'roles' => ['authenticated', 'mel_pro'],
    ]);
    $owner->save();

    $store = $this->createStore((int) $owner->id(), 'Terminal Store');
    $order = $this->createDraftOrder((int) $store->id(), (int) $owner->id());
    $this->assertTrue($this->scheduler->qualifiesForReminder($order));

    $order->set('state', 'completed');
    $order->set('cart', 0);

    $this->assertFalse($this->scheduler->qualifiesForReminder($order));
  }

  /**
   * Creates a store with owner.
   */
  private function createStore(int $ownerId, string $name): Store {
    $store = Store::create([
      'type' => 'default',
      'name' => $name,
      'mail' => 'store@example.com',
      'default_currency' => 'AUD',
      'is_default' => TRUE,
      'uid' => $ownerId,
    ]);
    $store->save();
    return $store;
  }

  /**
   * Creates a draft cart order with one non-zero order item.
   */
  private function createDraftOrder(int $storeId, int $ownerId): Order {
    $order = Order::create([
      'type' => 'default',
      'store_id' => $storeId,
      'state' => 'draft',
      'uid' => $ownerId,
      'mail' => 'buyer@example.com',
      'cart' => 1,
    ]);
    $order->save();

    $item = OrderItem::create([
      'type' => 'default',
      'quantity' => 1,
      'unit_price' => new Price('12.00', 'AUD'),
    ]);
    $item->save();
    $order->addItem($item);
    $order->save();

    return $order;
  }

  /**
   * Creates the abandoned cart queue job plugin with container dependencies.
   */
  private function buildJobPlugin(): ProAbandonedCartJob {
    return new ProAbandonedCartJob(
      [],
      'pro_abandoned_cart_job',
      [],
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('datetime.time'),
      $this->container->get('logger.channel.myeventlane_pro'),
      $this->container->get('myeventlane_pro.active_resolver'),
      $this->container->get('config.factory'),
      $this->container->get('plugin.manager.mail'),
      $this->container->get('myeventlane_pro.abandoned_cart_terminal_state_resolver'),
      $this->container->get('myeventlane_messaging.manager'),
    );
  }

}
