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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\myeventlane_messaging\Service\MessageStorage $messageStorage
   *   The message storage.
   * @param \Drupal\myeventlane_messaging\Service\MessagePreferenceStorage $preferenceStorage
   *   The preference storage.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MessageStorage $messageStorage,
    private readonly MessagePreferenceStorage $preferenceStorage,
  ) {}

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
    $message = $this->messageStorage->findByProviderMessageId($messageId);

    if ($message && $message->status === 'sent') {
      // Update status to 'delivered'.
      $this->messageStorage->update($message->id, ['status' => 'delivered']);
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
    $message = $this->messageStorage->findByProviderMessageId($messageId);

    if ($message) {
      $this->messageStorage->update($message->id, ['status' => 'bounced']);
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
      // If no secret configured, allow (for development).
      // In production, this should be required.
      return TRUE;
    }

    // Postmark webhooks can include signature validation.
    // For now, we'll use a simple shared secret check.
    // In production, implement proper HMAC signature validation.
    $authHeader = $request->headers->get('Authorization');
    if ($authHeader && str_contains($authHeader, $secret)) {
      return TRUE;
    }

    // Also check for secret in custom header.
    $customHeader = $request->headers->get('X-Webhook-Secret');
    if ($customHeader && hash_equals($secret, $customHeader)) {
      return TRUE;
    }

    return FALSE;
  }

}
