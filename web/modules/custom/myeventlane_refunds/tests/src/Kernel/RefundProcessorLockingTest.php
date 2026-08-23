<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_refunds\Kernel;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\myeventlane_refunds\Service\BuyerRefundEligibilityService;
use Drupal\myeventlane_refunds\Service\RefundAccessResolver;
use Drupal\myeventlane_refunds\Service\RefundOrderInspectorInterface;
use Drupal\myeventlane_refunds\Service\RefundOrderItemsInterface;
use Drupal\myeventlane_core\Service\StripeService;
use Drupal\myeventlane_refunds\Service\RefundProcessor;
use Drupal\myeventlane_refunds\Service\RefundRequestStorage;
use Drupal\myeventlane_refunds\Service\RefundAdjustmentNoteService;
use Drupal\myeventlane_refunds\Service\StoreEventOwnershipInterface;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Kernel tests for refund processing lock behavior.
 *
 * @group myeventlane_refunds
 */
#[RunTestsInSeparateProcesses]
final class RefundProcessorLockingTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
  ];

  /**
   * Tests replayed work cannot reopen a completed refund.
   */
  public function testCompletedRefundReplayPreservesCanonicalState(): void {
    $database = $this->container->get('database');
    $database->schema()->createTable('myeventlane_refund_log', [
      'fields' => [
        'id' => ['type' => 'serial', 'not null' => TRUE],
        'status' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE],
        'stripe_refund_id' => ['type' => 'varchar', 'length' => 255, 'not null' => FALSE],
        'updated' => ['type' => 'int', 'not null' => FALSE],
      ],
      'primary key' => ['id'],
    ]);
    $logId = (int) $database->insert('myeventlane_refund_log')->fields([
      'status' => RefundProcessor::STATUS_COMPLETED,
      'stripe_refund_id' => 're_test_completed',
      'updated' => 123456,
    ])->execute();

    $reflection = new \ReflectionClass(RefundProcessor::class);
    $processor = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('database')->setValue($processor, $database);

    $processor->requestRefundByLogId($logId);

    $row = $database->select('myeventlane_refund_log', 'r')
      ->fields('r', ['status', 'stripe_refund_id', 'updated'])
      ->condition('id', $logId)
      ->execute()
      ->fetchAssoc();
    $this->assertSame(RefundProcessor::STATUS_COMPLETED, $row['status']);
    $this->assertSame('re_test_completed', $row['stripe_refund_id']);
    $this->assertSame('123456', $row['updated']);
  }

  /**
   * Tests later queue batches reuse the persisted cancellation refund.
   */
  public function testEventCancellationRefundReusesExistingLog(): void {
    $database = $this->container->get('database');
    $database->schema()->createTable('myeventlane_refund_log', [
      'fields' => [
        'id' => ['type' => 'serial', 'not null' => TRUE],
        'order_id' => ['type' => 'int', 'not null' => TRUE],
        'event_id' => ['type' => 'int', 'not null' => TRUE],
        'refund_type' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE],
        'refund_scope' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE],
        'donation_refunded' => ['type' => 'int', 'not null' => TRUE],
        'reason' => ['type' => 'text', 'not null' => FALSE],
      ],
      'primary key' => ['id'],
    ]);
    $logId = (int) $database->insert('myeventlane_refund_log')->fields([
      'order_id' => 111,
      'event_id' => 222,
      'refund_type' => 'full',
      'refund_scope' => 'tickets_only',
      'donation_refunded' => 0,
      'reason' => 'Event cancelled',
    ])->execute();

    $order = $this->createMock(OrderInterface::class);
    $order->method('id')->willReturn(111);
    $event = $this->createMock(NodeInterface::class);
    $event->method('id')->willReturn(222);
    $account = $this->createMock(AccountInterface::class);

    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())
      ->method('acquire')
      ->with('event_cancel_refund:222:111', 30.0)
      ->willReturn(TRUE);
    $lock->expects($this->once())
      ->method('release')
      ->with('event_cancel_refund:222:111')
      ->willReturn(TRUE);

    $reflection = new \ReflectionClass(RefundProcessor::class);
    $processor = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('database')->setValue($processor, $database);
    $reflection->getProperty('lock')->setValue($processor, $lock);

    $this->assertSame(
      $logId,
      $processor->requestEventCancellationRefund($order, $event, $account),
    );
    $this->assertSame(
      1,
      (int) $database->select('myeventlane_refund_log')->countQuery()->execute()->fetchField(),
    );
  }

  /**
   * Tests second process call is blocked by lock.
   */
  public function testProcessRefundLockPreventsSecondExecution(): void {
    $database = $this->container->get('database');
    $schema = $database->schema();
    if (!$schema->tableExists('myeventlane_refund_log')) {
      $schema->createTable('myeventlane_refund_log', [
        'fields' => [
          'id' => ['type' => 'serial', 'not null' => TRUE],
          'order_id' => ['type' => 'int', 'not null' => TRUE],
          'event_id' => ['type' => 'int', 'not null' => TRUE],
          'vendor_uid' => ['type' => 'int', 'not null' => TRUE],
          'refund_type' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE],
          'refund_scope' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE],
          'amount_cents' => ['type' => 'int', 'not null' => TRUE],
          'currency' => ['type' => 'varchar', 'length' => 3, 'not null' => TRUE],
          'donation_refunded' => ['type' => 'int', 'size' => 'tiny', 'not null' => TRUE, 'default' => 0],
          'stripe_refund_id' => ['type' => 'varchar', 'length' => 255, 'not null' => FALSE],
          'status' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE],
          'failure_reason' => ['type' => 'text', 'not null' => FALSE],
          'reason' => ['type' => 'text', 'not null' => FALSE],
          'created' => ['type' => 'int', 'not null' => TRUE],
          'updated' => ['type' => 'int', 'not null' => FALSE],
          'completed' => ['type' => 'int', 'not null' => FALSE],
          'error_message' => ['type' => 'text', 'not null' => FALSE],
          'refund_request_id' => ['type' => 'int', 'not null' => FALSE],
          'retry_count' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
          'next_attempt_at' => ['type' => 'int', 'not null' => FALSE],
          'retry_of' => ['type' => 'int', 'not null' => FALSE],
          'actual_refunded_cents' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        ],
        'primary key' => ['id'],
      ]);
    }

    $logId = (int) $database->insert('myeventlane_refund_log')->fields([
      'order_id' => 111,
      'event_id' => 222,
      'vendor_uid' => 333,
      'refund_type' => 'full',
      'refund_scope' => 'tickets_only',
      'amount_cents' => 1000,
      'currency' => 'aud',
      'donation_refunded' => 0,
      'status' => RefundProcessor::STATUS_PROCESSING,
      'created' => 123456,
      'updated' => 123456,
    ])->execute();

    $orderState = new class() {

      /** Returns the workflow state ID. */
      public function getId(): string {
        return 'completed';
      }

    };
    $paymentState = new class() {

      /** Returns the workflow state ID. */
      public function getId(): string {
        return 'partially_refunded';
      }

    };

    $order = $this->createMock(OrderInterface::class);
    $order->method('id')->willReturn(111);
    $order->method('getEmail')->willReturn(NULL);
    $order->method('getState')->willReturn($orderState);
    $order->method('getAdjustments')->willReturn([]);
    $order->method('getItems')->willReturn([]);

    $event = $this->createMock(NodeInterface::class);
    $vendor = $this->createMock(AccountInterface::class);

    $refundPlugin = new class() {
      /** Number of refund calls. */
      public int $calls = 0;

      /** Records a refund call. */
      public function refundPayment(PaymentInterface $payment, Price $amount): void {
        $this->calls++;
      }

    };

    $gateway = $this->createMock(PaymentGatewayInterface::class);
    $gateway->method('getPlugin')->willReturn($refundPlugin);

    $payment = $this->createMock(PaymentInterface::class);
    $payment->method('id')->willReturn(1);
    $payment->method('getState')->willReturn($paymentState);
    $payment->method('getAmount')->willReturn(new Price('50.00', 'AUD'));
    $payment->method('getRefundedAmount')->willReturn(new Price('0.00', 'AUD'));
    $payment->method('getPaymentGateway')->willReturn($gateway);
    $payment->method('hasField')->with('remote_id')->willReturn(FALSE);

    $refundedState = new class() {

      /** Returns the workflow state ID. */
      public function getId(): string {
        return 'partially_refunded';
      }

    };
    $reloadedPayment = $this->createMock(PaymentInterface::class);
    $reloadedPayment->method('id')->willReturn(1);
    $reloadedPayment->method('getState')->willReturn($refundedState);
    $reloadedPayment->method('getAmount')->willReturn(new Price('50.00', 'AUD'));
    $reloadedPayment->method('getRefundedAmount')->willReturn(new Price('10.00', 'AUD'));
    $reloadedPayment->method('getRemoteId')->willReturn(NULL);

    $paymentQuery = $this->createMock(QueryInterface::class);
    $paymentQuery->method('accessCheck')->with(FALSE)->willReturnSelf();
    $paymentQuery->method('condition')->willReturnSelf();
    $paymentQuery->method('sort')->willReturnSelf();
    $paymentQuery->method('execute')->willReturn([1]);

    $paymentStorage = $this->createMock(EntityStorageInterface::class);
    $paymentStorage->method('getQuery')->willReturn($paymentQuery);
    $paymentStorage->method('loadMultiple')->willReturn([1 => $payment]);
    $paymentStorage->method('load')->with(1)->willReturn($reloadedPayment);

    $orderStorage = $this->createMock(EntityStorageInterface::class);
    $orderStorage->method('load')->with(111)->willReturn($order);

    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('load')->with(222)->willReturn($event);

    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->method('load')->with(333)->willReturn($vendor);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['commerce_order', $orderStorage],
      ['node', $nodeStorage],
      ['user', $userStorage],
      ['commerce_payment', $paymentStorage],
    ]);

    $accessResolver = $this->createPermissiveAccessResolver($vendor);

    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnOnConsecutiveCalls(TRUE, FALSE);
    $lock->method('release')->willReturn(TRUE);

    $siteConfig = $this->createMock(ImmutableConfig::class);
    $siteConfig->method('get')->willReturnCallback(
      static fn(string $key): ?string => $key === 'public_domain' ? 'https://example.com' : NULL,
    );
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($siteConfig);

    $processor = new RefundProcessor(
      $entityTypeManager,
      $this->createMock(RefundOrderInspectorInterface::class),
      $accessResolver,
      $this->uninitialized(BuyerRefundEligibilityService::class),
      $this->uninitialized(RefundRequestStorage::class),
      $this->uninitialized(MessagingManager::class),
      $this->uninitialized(StripeService::class),
      $configFactory,
      $loggerFactory,
      $this->createMock(QueueFactory::class),
      $this->createMock(AccountProxyInterface::class),
      $database,
      $this->container->get('datetime.time'),
      $lock,
      $this->createMock(ModuleHandlerInterface::class),
      new DomainDetector(new RequestStack(), $configFactory),
      new RefundAdjustmentNoteService($database, $this->container->get('datetime.time')),
    );

    $processor->processRefund($logId);
    $processor->processRefund($logId);

    $row = $database->select('myeventlane_refund_log', 'r')
      ->fields('r', ['status', 'error_message'])
      ->condition('id', $logId)
      ->execute()
      ->fetchAssoc();

    $this->assertSame('completed', $row['status'], (string) ($row['error_message'] ?? ''));
    $this->assertSame(1, $refundPlugin->calls);
  }

  /**
   * Tests refund amount is split across completed payments safely.
   */
  public function testProcessRefundSplitsAcrossMultiplePayments(): void {
    $database = $this->container->get('database');
    $schema = $database->schema();
    if (!$schema->tableExists('myeventlane_refund_log')) {
      $schema->createTable('myeventlane_refund_log', [
        'fields' => [
          'id' => ['type' => 'serial', 'not null' => TRUE],
          'order_id' => ['type' => 'int', 'not null' => TRUE],
          'event_id' => ['type' => 'int', 'not null' => TRUE],
          'vendor_uid' => ['type' => 'int', 'not null' => TRUE],
          'refund_type' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE],
          'refund_scope' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE],
          'amount_cents' => ['type' => 'int', 'not null' => TRUE],
          'currency' => ['type' => 'varchar', 'length' => 3, 'not null' => TRUE],
          'donation_refunded' => ['type' => 'int', 'size' => 'tiny', 'not null' => TRUE, 'default' => 0],
          'stripe_refund_id' => ['type' => 'varchar', 'length' => 255, 'not null' => FALSE],
          'status' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE],
          'failure_reason' => ['type' => 'text', 'not null' => FALSE],
          'reason' => ['type' => 'text', 'not null' => FALSE],
          'created' => ['type' => 'int', 'not null' => TRUE],
          'updated' => ['type' => 'int', 'not null' => FALSE],
          'completed' => ['type' => 'int', 'not null' => FALSE],
          'error_message' => ['type' => 'text', 'not null' => FALSE],
          'refund_request_id' => ['type' => 'int', 'not null' => FALSE],
          'retry_count' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
          'next_attempt_at' => ['type' => 'int', 'not null' => FALSE],
          'retry_of' => ['type' => 'int', 'not null' => FALSE],
          'actual_refunded_cents' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        ],
        'primary key' => ['id'],
      ]);
    }

    $logId = (int) $database->insert('myeventlane_refund_log')->fields([
      'order_id' => 2001,
      'event_id' => 3001,
      'vendor_uid' => 4001,
      'refund_type' => 'partial',
      'refund_scope' => 'tickets_only',
      'amount_cents' => 1000,
      'currency' => 'aud',
      'donation_refunded' => 0,
      'status' => RefundProcessor::STATUS_PROCESSING,
      'created' => 123456,
      'updated' => 123456,
    ])->execute();

    $orderState = new class() {

      /** Returns the workflow state ID. */
      public function getId(): string {
        return 'completed';
      }

    };
    $paymentState = new class() {

      /** Returns the workflow state ID. */
      public function getId(): string {
        return 'partially_refunded';
      }

    };

    $order = $this->createMock(OrderInterface::class);
    $order->method('id')->willReturn(2001);
    $order->method('getEmail')->willReturn(NULL);
    $order->method('getState')->willReturn($orderState);
    $order->method('getAdjustments')->willReturn([]);
    $order->method('getItems')->willReturn([]);

    $event = $this->createMock(NodeInterface::class);
    $vendor = $this->createMock(AccountInterface::class);

    $refundPlugin = new class() {
      /** Refunded decimal amounts. */
      public array $amounts = [];

      /** Records a refund amount. */
      public function refundPayment(PaymentInterface $payment, Price $amount): void {
        $this->amounts[] = $amount->getNumber();
      }

      /** Returns the test plugin ID. */
      public function getPluginId(): string {
        return 'test_refund_plugin';
      }

    };

    $gateway = $this->createMock(PaymentGatewayInterface::class);
    $gateway->method('getPlugin')->willReturn($refundPlugin);

    $paymentA = $this->createMock(PaymentInterface::class);
    $paymentA->method('id')->willReturn(11);
    $paymentA->method('getState')->willReturn($paymentState);
    $paymentA->method('getAmount')->willReturn(new Price('5.00', 'AUD'));
    $paymentA->method('getRefundedAmount')->willReturn(new Price('0.00', 'AUD'));
    $paymentA->method('getPaymentGateway')->willReturn($gateway);
    $paymentA->method('hasField')->with('remote_id')->willReturn(FALSE);

    $paymentB = $this->createMock(PaymentInterface::class);
    $paymentB->method('id')->willReturn(12);
    $paymentB->method('getState')->willReturn($paymentState);
    $paymentB->method('getAmount')->willReturn(new Price('7.00', 'AUD'));
    $paymentB->method('getRefundedAmount')->willReturn(new Price('0.00', 'AUD'));
    $paymentB->method('getPaymentGateway')->willReturn($gateway);
    $paymentB->method('hasField')->with('remote_id')->willReturn(FALSE);

    $refundedState = new class() {

      /** Returns the workflow state ID. */
      public function getId(): string {
        return 'partially_refunded';
      }

    };
    $reloadedPaymentA = $this->createMock(PaymentInterface::class);
    $reloadedPaymentA->method('id')->willReturn(11);
    $reloadedPaymentA->method('getState')->willReturn($refundedState);
    $reloadedPaymentA->method('getAmount')->willReturn(new Price('5.00', 'AUD'));
    $reloadedPaymentA->method('getRefundedAmount')->willReturn(new Price('5.00', 'AUD'));
    $reloadedPaymentA->method('getRemoteId')->willReturn(NULL);

    $reloadedPaymentB = $this->createMock(PaymentInterface::class);
    $reloadedPaymentB->method('id')->willReturn(12);
    $reloadedPaymentB->method('getState')->willReturn($refundedState);
    $reloadedPaymentB->method('getAmount')->willReturn(new Price('7.00', 'AUD'));
    $reloadedPaymentB->method('getRefundedAmount')->willReturn(new Price('5.00', 'AUD'));
    $reloadedPaymentB->method('getRemoteId')->willReturn(NULL);

    $paymentQuery = $this->createMock(QueryInterface::class);
    $paymentQuery->method('accessCheck')->with(FALSE)->willReturnSelf();
    $paymentQuery->method('condition')->willReturnSelf();
    $paymentQuery->method('sort')->willReturnSelf();
    $paymentQuery->method('execute')->willReturn([11, 12]);

    $paymentStorage = $this->createMock(EntityStorageInterface::class);
    $paymentStorage->method('getQuery')->willReturn($paymentQuery);
    $paymentStorage->method('loadMultiple')->willReturn([11 => $paymentA, 12 => $paymentB]);
    $paymentStorage->method('load')->willReturnMap([
      [11, $reloadedPaymentA],
      [12, $reloadedPaymentB],
    ]);

    $orderStorage = $this->createMock(EntityStorageInterface::class);
    $orderStorage->method('load')->with(2001)->willReturn($order);

    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('load')->with(3001)->willReturn($event);

    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->method('load')->with(4001)->willReturn($vendor);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['commerce_order', $orderStorage],
      ['node', $nodeStorage],
      ['user', $userStorage],
      ['commerce_payment', $paymentStorage],
    ]);

    $accessResolver = $this->createPermissiveAccessResolver($vendor);

    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $lock->method('release')->willReturn(TRUE);

    $siteConfig = $this->createMock(ImmutableConfig::class);
    $siteConfig->method('get')->willReturnCallback(
      static fn(string $key): ?string => $key === 'public_domain' ? 'https://example.com' : NULL,
    );
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($siteConfig);

    $processor = new RefundProcessor(
      $entityTypeManager,
      $this->createMock(RefundOrderInspectorInterface::class),
      $accessResolver,
      $this->uninitialized(BuyerRefundEligibilityService::class),
      $this->uninitialized(RefundRequestStorage::class),
      $this->uninitialized(MessagingManager::class),
      $this->uninitialized(StripeService::class),
      $configFactory,
      $loggerFactory,
      $this->createMock(QueueFactory::class),
      $this->createMock(AccountProxyInterface::class),
      $database,
      $this->container->get('datetime.time'),
      $lock,
      $this->createMock(ModuleHandlerInterface::class),
      new DomainDetector(new RequestStack(), $configFactory),
      new RefundAdjustmentNoteService($database, $this->container->get('datetime.time')),
    );

    $processor->processRefund($logId);

    $row = $database->select('myeventlane_refund_log', 'r')
      ->fields('r', ['status', 'error_message'])
      ->condition('id', $logId)
      ->execute()
      ->fetchAssoc();

    $this->assertSame('completed', $row['status'], (string) ($row['error_message'] ?? ''));
    $this->assertCount(2, $refundPlugin->amounts);
    $this->assertSame('5.00', $refundPlugin->amounts[0]);
    $this->assertSame('5.00', $refundPlugin->amounts[1]);
  }

  /**
   * Builds a real final access resolver with bounded permissive collaborators.
   */
  private function createPermissiveAccessResolver(AccountInterface $vendor): RefundAccessResolver {
    $vendor->method('hasPermission')->willReturn(TRUE);

    $orderItems = $this->createMock(RefundOrderItemsInterface::class);
    $orderItems->method('extractItemsForEvent')->willReturn([new \stdClass()]);

    return new RefundAccessResolver(
      $this->createMock(StoreEventOwnershipInterface::class),
      $orderItems,
      $this->createMock(EventVendorAccessCheckerInterface::class),
    );
  }

  /**
   * Instantiates an unused final collaborator without invoking its constructor.
   */
  private function uninitialized(string $class): object {
    return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
  }

}
