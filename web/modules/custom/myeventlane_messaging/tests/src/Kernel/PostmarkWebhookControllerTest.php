<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_messaging\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_messaging\Controller\PostmarkWebhookController;
use Drupal\myeventlane_messaging\Service\MessagePreferenceStorage;
use Drupal\myeventlane_messaging\Service\MessageStorage;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests Postmark webhook delivery and bounce handling.
 *
 * @group myeventlane_messaging
 */
#[RunTestsInSeparateProcesses]
final class PostmarkWebhookControllerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * Webhook shared secret used in tests.
   */
  private const WEBHOOK_SECRET = 'test-webhook-secret';

  /**
   * Message storage under test.
   */
  private MessageStorage $messageStorage;

  /**
   * Webhook controller under test.
   */
  private PostmarkWebhookController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once dirname(__DIR__, 3) . '/myeventlane_messaging.install';

    $schema = $this->container->get('database')->schema();
    $tables = myeventlane_messaging_schema();

    foreach (['myeventlane_message', 'myeventlane_message_preference'] as $table) {
      if ($schema->tableExists($table)) {
        $schema->dropTable($table);
      }
      $schema->createTable($table, $tables[$table]);
    }

    $this->container->get('config.factory')
      ->getEditable('myeventlane_messaging.settings')
      ->set('postmark.webhook_secret', self::WEBHOOK_SECRET)
      ->save();

    $this->messageStorage = new MessageStorage($this->container->get('database'));
    $preferenceStorage = new MessagePreferenceStorage($this->container->get('database'));

    $this->container->set('myeventlane_messaging.message_storage', $this->messageStorage);
    $this->container->set('myeventlane_messaging.message_preference_storage', $preferenceStorage);

    $this->controller = PostmarkWebhookController::create($this->container);
  }

  /**
   * Delivery webhook updates known message status to delivered.
   */
  public function testDeliveryUpdatesKnownMessage(): void {
    $id = $this->messageStorage->create([
      'template' => 'test',
      'channel' => 'email',
      'recipient' => 'delivery@example.com',
      'context' => [],
      'context_hash' => hash('sha256', 'delivery-test'),
      'status' => 'sent',
      'provider' => 'postmark',
      'provider_message_id' => 'delivery-msg-id',
    ]);

    $request = $this->buildRequest(
      ['MessageID' => 'delivery-msg-id', 'Recipient' => 'delivery@example.com'],
      self::WEBHOOK_SECRET,
    );

    $response = $this->controller->delivery($request);
    $this->assertSame(200, $response->getStatusCode());

    $row = $this->messageStorage->load($id);
    $this->assertNotNull($row);
    $this->assertSame('delivered', $row->status);
  }

  /**
   * Delivery metadata reconciles an uncertain provider dispatch.
   */
  public function testDeliveryMetadataReconcilesUnknownDispatch(): void {
    $id = $this->messageStorage->create([
      'template' => 'test',
      'channel' => 'email',
      'recipient' => 'reconcile@example.com',
      'context' => [],
      'context_hash' => hash('sha256', 'reconcile-test'),
      'status' => 'delivery_unknown',
      'provider' => 'postmark',
    ]);

    $request = $this->buildRequest([
      'MessageID' => 'reconciled-provider-id',
      'Recipient' => 'reconcile@example.com',
      'Metadata' => ['mel_message_id' => $id],
    ], self::WEBHOOK_SECRET);

    $response = $this->controller->delivery($request);
    $this->assertSame(200, $response->getStatusCode());

    $row = $this->messageStorage->load($id);
    $this->assertSame('delivered', $row->status);
    $this->assertSame('postmark', $row->provider);
    $this->assertSame('reconciled-provider-id', $row->provider_message_id);
    $this->assertGreaterThan(0, (int) $row->sent);
  }

  /**
   * Metadata cannot reconcile a message for another recipient.
   */
  public function testDeliveryMetadataRequiresMatchingRecipient(): void {
    $id = $this->messageStorage->create([
      'template' => 'test',
      'channel' => 'email',
      'recipient' => 'expected@example.com',
      'context' => [],
      'context_hash' => hash('sha256', 'recipient-test'),
      'status' => 'delivery_unknown',
      'provider' => 'postmark',
    ]);

    $request = $this->buildRequest([
      'MessageID' => 'wrong-recipient-provider-id',
      'Recipient' => 'other@example.com',
      'Metadata' => ['mel_message_id' => $id],
    ], self::WEBHOOK_SECRET);

    $response = $this->controller->delivery($request);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(
      'delivery_unknown',
      $this->messageStorage->load($id)->status,
    );
  }

  /**
   * Bounce webhook updates known message status to bounced.
   */
  public function testBounceUpdatesKnownMessage(): void {
    $id = $this->messageStorage->create([
      'template' => 'test',
      'channel' => 'email',
      'recipient' => 'bounce@example.com',
      'context' => [],
      'context_hash' => hash('sha256', 'bounce-test'),
      'status' => 'sent',
      'provider' => 'postmark',
      'provider_message_id' => 'bounce-msg-id',
    ]);

    $request = $this->buildRequest(
      ['MessageID' => 'bounce-msg-id', 'Email' => 'bounce@example.com'],
      self::WEBHOOK_SECRET,
    );

    $response = $this->controller->bounce($request);
    $this->assertSame(200, $response->getStatusCode());

    $row = $this->messageStorage->load($id);
    $this->assertNotNull($row);
    $this->assertSame('bounced', $row->status);
  }

  /**
   * Missing or invalid secret returns 401.
   */
  public function testInvalidSecretReturns401(): void {
    $request = $this->buildRequest(['MessageID' => 'unknown-id'], NULL);
    $response = $this->controller->delivery($request);
    $this->assertSame(401, $response->getStatusCode());

    $request = $this->buildRequest(['MessageID' => 'unknown-id'], 'wrong-secret');
    $response = $this->controller->delivery($request);
    $this->assertSame(401, $response->getStatusCode());
  }

  /**
   * Unknown MessageID returns 200 without error.
   */
  public function testUnknownMessageIdReturns200(): void {
    $request = $this->buildRequest(['MessageID' => 'missing-msg-id'], self::WEBHOOK_SECRET);
    $response = $this->controller->delivery($request);
    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Builds a JSON POST request with optional webhook secret header.
   *
   * @param array $payload
   *   JSON payload.
   * @param string|null $secret
   *   X-Webhook-Secret header value, or NULL to omit.
   */
  private function buildRequest(array $payload, ?string $secret): Request {
    $headers = ['CONTENT_TYPE' => 'application/json'];
    if ($secret !== NULL) {
      $headers['HTTP_X_WEBHOOK_SECRET'] = $secret;
    }

    return Request::create(
      '/webhooks/postmark/delivery',
      'POST',
      [],
      [],
      [],
      $headers,
      (string) json_encode($payload),
    );
  }

}
