<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_messaging\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_messaging\Service\MessageStorage;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

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

}
