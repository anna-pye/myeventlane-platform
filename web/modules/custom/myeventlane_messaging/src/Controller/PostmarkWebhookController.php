<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\myeventlane_messaging\Service\MessagePreferenceStorage;
use Drupal\myeventlane_messaging\Service\MessageStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles Postmark webhooks for delivery and bounce events.
 */
final class PostmarkWebhookController extends ControllerBase {

  /**
   * Constructs PostmarkWebhookController.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\myeventlane_messaging\Service\MessageStorage $messageStorage
   *   The message storage.
   * @param \Drupal\myeventlane_messaging\Service\MessagePreferenceStorage $preferenceStorage
   *   The preference storage.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    private readonly MessageStorage $messageStorage,
    private readonly MessagePreferenceStorage $preferenceStorage,
  ) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('myeventlane_messaging.message_storage'),
      $container->get('myeventlane_messaging.message_preference_storage'),
    );
  }

  /**
   * Handles Postmark delivery webhook.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Response.
   */
  public function delivery(Request $request): Response {
    if (!$this->validateWebhook($request)) {
      return new Response('Unauthorized', 401);
    }

    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!$payload || !isset($payload['MessageID'])) {
      return new JsonResponse(['error' => 'Invalid payload'], 400);
    }

    $messageId = $payload['MessageID'];
    $recipient = $payload['Recipient'] ?? '';

    // Update message status if we can find it by provider message ID.
    $message = $this->resolveMessage($payload);

    if ($message) {
      $this->messageStorage->update($message->id, [
        'status' => 'delivered',
        'sent' => (int) ($message->sent ?: time()),
        'claimed_at' => 0,
        'provider' => 'postmark',
        'provider_message_id' => $messageId,
      ]);
    }

    $this->getLogger('myeventlane_messaging')->info('Postmark delivery webhook received. MessageID=@id, Recipient=@recipient', [
      '@id' => $messageId,
      '@recipient' => $recipient,
    ]);

    return new JsonResponse(['status' => 'ok']);
  }

  /**
   * Handles Postmark bounce/complaint webhook.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Response.
   */
  public function bounce(Request $request): Response {
    if (!$this->validateWebhook($request)) {
      return new Response('Unauthorized', 401);
    }

    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!$payload || !isset($payload['MessageID'])) {
      return new JsonResponse(['error' => 'Invalid payload'], 400);
    }

    $messageId = $payload['MessageID'];
    $recipient = $payload['Email'] ?? $payload['Recipient'] ?? '';
    $bounceType = $payload['Type'] ?? 'unknown';
    $inactive = isset($payload['Inactive']) && $payload['Inactive'] === TRUE;

    // Suppress future sends to this recipient.
    if (!empty($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
      // Add to suppression list via preference storage.
      $this->preferenceStorage->setMarketingOptOut($recipient, 'email', TRUE);
      $this->preferenceStorage->setOperationalReminderOptOut($recipient, 'email', TRUE);

      $this->getLogger('myeventlane_messaging')->warning('Postmark bounce/complaint: suppressed recipient @recipient. Type=@type, Inactive=@inactive', [
        '@recipient' => $recipient,
        '@type' => $bounceType,
        '@inactive' => $inactive ? 'true' : 'false',
      ]);
    }

    // Update message status if found.
    $message = $this->resolveMessage($payload);

    if ($message) {
      $this->messageStorage->update($message->id, [
        'status' => 'bounced',
        'claimed_at' => 0,
        'provider' => 'postmark',
        'provider_message_id' => $messageId,
      ]);
    }

    $this->getLogger('myeventlane_messaging')->info('Postmark bounce webhook received. MessageID=@id, Recipient=@recipient', [
      '@id' => $messageId,
      '@recipient' => $recipient,
    ]);

    return new JsonResponse(['status' => 'ok']);
  }

  /**
   * Validates webhook request using shared secret.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  private function validateWebhook(Request $request): bool {
    $config = $this->configFactory->get('myeventlane_messaging.settings');
    $secret = $config->get('postmark.webhook_secret');

    if (empty($secret)) {
      $this->getLogger('myeventlane_messaging')->warning('Postmark webhook rejected: postmark.webhook_secret is not configured. Configure it in Messaging settings to accept webhooks.');
      return FALSE;
    }

    $providedSecret = $request->headers->get('X-Webhook-Secret');
    if ($providedSecret === NULL || $providedSecret === '') {
      return FALSE;
    }

    return hash_equals($secret, $providedSecret);
  }

  /**
   * Resolves a message by provider ID or trusted Postmark metadata.
   */
  private function resolveMessage(array $payload): ?object {
    $messageId = (string) ($payload['MessageID'] ?? '');
    $message = $this->messageStorage->findByProviderMessageId($messageId);
    if ($message !== NULL) {
      return $message;
    }

    $internalId = $payload['Metadata']['mel_message_id'] ?? '';
    if (!is_string($internalId) || $internalId === '') {
      return NULL;
    }
    $message = $this->messageStorage->load($internalId);
    if ($message === NULL) {
      return NULL;
    }

    $recipient = strtolower(trim((string) (
      $payload['Recipient'] ?? $payload['Email'] ?? ''
    )));
    if ($recipient === ''
      || strtolower((string) $message->recipient) !== $recipient) {
      return NULL;
    }

    return $message;
  }

}
