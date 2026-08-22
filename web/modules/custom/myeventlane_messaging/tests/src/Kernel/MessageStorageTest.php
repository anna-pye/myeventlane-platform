<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_messaging\Kernel;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_messaging\Service\Delivery\DeliveryProviderManager;
use Drupal\myeventlane_messaging\Service\MessagePreferenceStorage;
use Drupal\myeventlane_messaging\Service\MessageRenderer;
use Drupal\myeventlane_messaging\Service\MessageStorage;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;

/**
 * Tests MessageStorage provider tracking (create, update, lookup).
 *
 * @group myeventlane_messaging
 */
#[RunTestsInSeparateProcesses]
final class MessageStorageTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
  ];

  /**
   * Message storage under test.
   */
  private MessageStorage $storage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once dirname(__DIR__, 3) . '/myeventlane_messaging.install';

    $schema = $this->container->get('database')->schema();
    $tables = myeventlane_messaging_schema();
    if ($schema->tableExists('myeventlane_message')) {
      $schema->dropTable('myeventlane_message');
    }
    $schema->createTable('myeventlane_message', $tables['myeventlane_message']);

    $this->storage = new MessageStorage($this->container->get('database'));
  }

  /**
   * Create() persists provider and provider_message_id.
   */
  public function testCreatePersistsProviderFields(): void {
    $id = $this->storage->create([
      'template' => 'test',
      'channel' => 'email',
      'recipient' => 'test@example.com',
      'context' => ['key' => 'value'],
      'context_hash' => hash('sha256', 'create-test'),
      'status' => 'sent',
      'provider' => 'postmark',
      'provider_message_id' => 'create-test-id',
    ]);

    $row = $this->storage->load($id);
    $this->assertNotNull($row);
    $this->assertSame('postmark', $row->provider);
    $this->assertSame('create-test-id', $row->provider_message_id);
    $this->assertSame(['key' => 'value'], $row->context);
  }

  /**
   * Update() can set provider and provider_message_id.
   */
  public function testUpdateProviderFields(): void {
    $id = $this->storage->create([
      'template' => 'test',
      'channel' => 'email',
      'recipient' => 'test@example.com',
      'context' => [],
      'context_hash' => hash('sha256', 'update-test'),
      'status' => 'queued',
    ]);

    $updated = $this->storage->update($id, [
      'status' => 'sent',
      'provider' => 'postmark',
      'provider_message_id' => 'update-test-id',
    ]);
    $this->assertSame(1, $updated);

    $row = $this->storage->load($id);
    $this->assertNotNull($row);
    $this->assertSame('sent', $row->status);
    $this->assertSame('postmark', $row->provider);
    $this->assertSame('update-test-id', $row->provider_message_id);
  }

  /**
   * FindByProviderMessageId() returns a hydrated row.
   */
  public function testFindByProviderMessageId(): void {
    $this->storage->create([
      'template' => 'test',
      'channel' => 'email',
      'recipient' => 'lookup@example.com',
      'context' => ['lookup' => TRUE],
      'context_hash' => hash('sha256', 'lookup-test'),
      'status' => 'sent',
      'provider' => 'postmark',
      'provider_message_id' => 'lookup-test-id',
    ]);

    $row = $this->storage->findByProviderMessageId('lookup-test-id');
    $this->assertNotNull($row);
    $this->assertSame('lookup@example.com', $row->recipient);
    $this->assertSame('postmark', $row->provider);
    $this->assertSame(['lookup' => TRUE], $row->context);
  }

  /**
   * FindByProviderMessageId() returns NULL when not found.
   */
  public function testFindByProviderMessageIdNotFound(): void {
    $this->assertNull($this->storage->findByProviderMessageId('missing-id'));
    $this->assertNull($this->storage->findByProviderMessageId(''));
  }

  /**
   * A business idempotency key can be inserted only once.
   */
  public function testIdempotencyKeyIsUnique(): void {
    $row = [
      'template' => 'boost_expired',
      'channel' => 'email',
      'recipient' => 'anna@example.com',
      'context' => ['entitlement_id' => 42],
      'context_hash' => hash('sha256', 'boost-expired-42'),
      'idempotency_key' => 'boost_expired:entitlement:42',
      'status' => 'queued',
    ];

    $this->storage->create($row);
    $existing = $this->storage->findByIdempotencyKey(
      'boost_expired:entitlement:42',
    );
    $this->assertNotNull($existing);

    $this->expectException(IntegrityConstraintViolationException::class);
    $this->storage->create($row);
  }

  /**
   * Only one worker can atomically claim a message.
   */
  public function testClaimForDeliveryIsAtomicAndFailedCanRetry(): void {
    $id = $this->storage->create([
      'template' => 'test',
      'channel' => 'email',
      'recipient' => 'worker@example.com',
      'context' => [],
      'context_hash' => hash('sha256', 'claim-test'),
      'idempotency_key' => 'test:claim:1',
      'status' => 'queued',
    ]);

    $this->assertTrue($this->storage->claimForDelivery($id, 100));
    $this->assertFalse($this->storage->claimForDelivery($id, 101));
    $claimed = $this->storage->load($id);
    $this->assertSame('processing', $claimed->status);
    $this->assertSame('100', (string) $claimed->claimed_at);

    $this->assertTrue($this->storage->releaseFailedClaim($id));
    $this->assertFalse($this->storage->releaseFailedClaim($id));
    $this->assertTrue($this->storage->claimForDelivery($id, 102));
  }

  /**
   * A producer failure cannot overwrite a concurrent worker claim or send.
   */
  public function testQueueInsertFailureOnlyMarksUnclaimedQueuedMessage(): void {
    $queuedId = $this->storage->create([
      'template' => 'test',
      'channel' => 'email',
      'recipient' => 'queued@example.com',
      'context' => [],
      'context_hash' => hash('sha256', 'queue-insert-failed'),
      'status' => 'queued',
    ]);
    $processingId = $this->storage->create([
      'template' => 'test',
      'channel' => 'email',
      'recipient' => 'processing@example.com',
      'context' => [],
      'context_hash' => hash('sha256', 'queue-insert-processing'),
      'status' => 'queued',
    ]);
    $sentId = $this->storage->create([
      'template' => 'test',
      'channel' => 'email',
      'recipient' => 'sent@example.com',
      'context' => [],
      'context_hash' => hash('sha256', 'queue-insert-sent'),
      'status' => 'sent',
      'sent' => 200,
    ]);

    $this->assertTrue($this->storage->claimForDelivery($processingId, 100));
    $this->assertTrue($this->storage->markQueueInsertFailed($queuedId));
    $this->assertFalse($this->storage->markQueueInsertFailed($processingId));
    $this->assertFalse($this->storage->markQueueInsertFailed($sentId));

    $queued = $this->storage->load($queuedId);
    $processing = $this->storage->load($processingId);
    $sent = $this->storage->load($sentId);
    $this->assertSame('failed', $queued->status);
    $this->assertSame('processing', $processing->status);
    $this->assertSame('100', (string) $processing->claimed_at);
    $this->assertSame('sent', $sent->status);
    $this->assertSame('200', (string) $sent->sent);
  }

  /**
   * Stale work is retried only before the provider-dispatch boundary.
   */
  public function testStaleClaimRecoveryQuarantinesProviderDispatch(): void {
    $id = $this->storage->create([
      'template' => 'test',
      'channel' => 'email',
      'recipient' => 'restart@example.com',
      'context' => [],
      'context_hash' => hash('sha256', 'restart-test'),
      'idempotency_key' => 'test:restart:1',
      'status' => 'queued',
    ]);

    $this->assertTrue($this->storage->claimForDelivery($id, 100));
    $this->assertFalse($this->storage->reclaimStaleProcessing($id, 99, 200));
    $this->assertTrue($this->storage->reclaimStaleProcessing($id, 100, 200));
    $this->assertTrue($this->storage->markDispatching($id, 201));
    $this->assertFalse($this->storage->markDispatching($id, 202));
    $this->assertFalse($this->storage->releaseFailedClaim($id));
    $this->assertFalse($this->storage->reclaimStaleProcessing($id, 300, 301));
    $this->assertFalse($this->storage->markStaleDispatchUnknown($id, 200));
    $this->assertTrue($this->storage->markStaleDispatchUnknown($id, 201));

    $message = $this->storage->load($id);
    $this->assertSame('delivery_unknown', $message->status);
    $this->assertSame('0', (string) $message->claimed_at);
    $this->assertFalse($this->storage->claimForDelivery($id, 302));
  }

  /**
   * Legacy backfill prefers a sent row and normalizes recipient casing.
   */
  public function testLegacyIdempotencyBackfillPrefersSentMessage(): void {
    $context = ['order_id' => 42];
    $failedId = $this->storage->create([
      'template' => 'order_confirmation',
      'channel' => 'email',
      'recipient' => 'Anna@Example.com',
      'context' => $context,
      'context_hash' => 'legacy-failed-hash',
      'status' => 'failed',
      'created' => 1,
    ]);
    $sentId = $this->storage->create([
      'template' => 'order_confirmation',
      'channel' => 'email',
      'recipient' => 'anna@example.com',
      'context' => $context,
      'context_hash' => 'legacy-sent-hash',
      'status' => 'sent',
      'created' => 2,
      'sent' => 3,
    ]);

    myeventlane_messaging_update_10007();

    $contextHash = hash(
      'sha256',
      'order_confirmation|anna@example.com|{"order_id":42}',
    );
    $canonicalKey = 'message:order_confirmation:' . $contextHash;
    $failed = $this->storage->load($failedId);
    $sent = $this->storage->load($sentId);
    $this->assertSame('anna@example.com', $failed->recipient);
    $this->assertSame('anna@example.com', $sent->recipient);
    $this->assertSame($canonicalKey, $sent->idempotency_key);
    $this->assertSame('legacy-duplicate:' . $failedId, $failed->idempotency_key);
    $this->assertCount(
      1,
      $this->storage->findSentOrderConfirmationsForOrder(
        42,
        'ANNA@EXAMPLE.COM',
      ),
    );
  }

  /**
   * Queueing a retryable failed occurrence reuses and requeues its row.
   */
  public function testFailedOccurrenceIsRequeuedWithoutDuplicateRow(): void {
    $idempotencyKey = 'order_confirmation:order:42';
    $id = $this->storage->create([
      'template' => 'order_confirmation',
      'channel' => 'email',
      'recipient' => 'anna@example.com',
      'context' => ['order_id' => 42],
      'context_hash' => hash('sha256', 'failed-order-42'),
      'idempotency_key' => $idempotencyKey,
      'status' => 'failed',
      'attempts' => 1,
    ]);

    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->once())
      ->method('createItem')
      ->with(['message_id' => $id]);
    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->method('get')
      ->with('myeventlane_messaging')
      ->willReturn($queue);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getDefaultLanguage')->willReturn($language);

    $manager = new MessagingManager(
      $configFactory,
      $languageManager,
      $queueFactory,
      $this->createMock(LoggerInterface::class),
      (new \ReflectionClass(MessageRenderer::class))
        ->newInstanceWithoutConstructor(),
      $this->storage,
      new MessagePreferenceStorage($this->container->get('database')),
      (new \ReflectionClass(DeliveryProviderManager::class))
        ->newInstanceWithoutConstructor(),
      $this->createMock(EntityTypeManagerInterface::class),
    );

    $this->assertSame($id, $manager->queue(
      'order_confirmation',
      'Anna@Example.com',
      ['order_id' => 42],
      ['idempotency_key' => $idempotencyKey],
    ));
    $count = $this->container->get('database')
      ->select('myeventlane_message', 'm')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(1, (int) $count);
  }

}
