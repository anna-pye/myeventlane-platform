<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_messaging\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_messaging\Service\MessageStorage;
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
   * create() persists provider and provider_message_id.
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
   * update() can set provider and provider_message_id.
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
   * findByProviderMessageId() returns a hydrated row.
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
   * findByProviderMessageId() returns NULL when not found.
   */
  public function testFindByProviderMessageIdNotFound(): void {
    $this->assertNull($this->storage->findByProviderMessageId('missing-id'));
    $this->assertNull($this->storage->findByProviderMessageId(''));
  }

}
