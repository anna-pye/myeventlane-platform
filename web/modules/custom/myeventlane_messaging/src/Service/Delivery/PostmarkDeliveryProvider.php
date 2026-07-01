<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service\Delivery;

use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Delivery provider using Postmark API.
 */
final class PostmarkDeliveryProvider implements DeliveryProviderInterface {

  /**
   * Postmark API endpoint.
   */
  private const API_ENDPOINT = 'https://api.postmarkapp.com/email';

  /**
   * Constructs PostmarkDeliveryProvider.
   *
   * @param \Drupal\Core\Http\ClientFactory $httpClientFactory
   *   The HTTP client factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    private readonly ClientFactory $httpClientFactory,
    private readonly \Drupal\Core\Config\ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'postmark';
  }

  /**
   * {@inheritdoc}
   */
  public function send(array $params): bool {
    $this->lastMessageId = NULL;

    $config = $this->configFactory->get('myeventlane_messaging.settings');
    $serverToken = $config->get('postmark.server_token');

    if (empty($serverToken)) {
      $this->logger->error('Postmark server token not configured.');
      return FALSE;
    }

    $to = $params['to'] ?? '';
    $subject = $params['subject'] ?? '(no subject)';
    $htmlBody = $params['html'] ?? $params['body'] ?? '';
    $fromEmail = $params['from_email'] ?? $config->get('from_email') ?? '';
    $fromName = $params['from_name'] ?? $config->get('from_name') ?? 'MyEventLane';
    $replyTo = $params['reply_to'] ?? $config->get('reply_to') ?? '';

    if (empty($to) || empty($fromEmail)) {
      $this->logger->error('Postmark send: missing required parameters (to or from_email).');
      return FALSE;
    }

    $messageStream = $config->get('postmark.message_stream');
    if (!is_string($messageStream) || $messageStream === '') {
      $messageStream = 'outbound';
    }

    $payload = [
      'From' => !empty($fromName) ? "{$fromName} <{$fromEmail}>" : $fromEmail,
      'To' => $to,
      'Subject' => $subject,
      'HtmlBody' => $htmlBody,
      'MessageStream' => $messageStream,
    ];

    if (!empty($replyTo)) {
      $payload['ReplyTo'] = $replyTo;
    }

    // Handle attachments if provided. Three input shapes are accepted, in order:
    //   1. In-memory MEL shape ['filename', 'content' => raw bytes, 'mime'] — what
    //      every MEL producer emits (ticket PDFs, ICS). Raw bytes are base64-encoded.
    //   2. Already-Postmark shape ['Name', 'Content' => base64, 'ContentType'] —
    //      preserved as-is (no re-encoding).
    //   3. File-on-disk shape ['path', 'name', 'content_type'] — read and encoded.
    // Unreadable, empty, or malformed entries are skipped without throwing.
    if (!empty($params['attachments']) && is_array($params['attachments'])) {
      $attachments = [];
      foreach ($params['attachments'] as $attachment) {
        if (!is_array($attachment)) {
          continue;
        }
        $name = $attachment['filename'] ?? $attachment['name'] ?? $attachment['Name'] ?? NULL;
        $contentType = $attachment['mime'] ?? $attachment['content_type'] ?? $attachment['ContentType'] ?? 'application/octet-stream';

        if (isset($attachment['Content']) && $attachment['Content'] !== '') {
          // Already base64-encoded Postmark shape: preserve existing behaviour.
          $encoded = (string) $attachment['Content'];
        }
        elseif (isset($attachment['content']) && $attachment['content'] !== '') {
          // In-memory raw bytes from MEL producers: base64-encode.
          $encoded = base64_encode((string) $attachment['content']);
        }
        elseif (isset($attachment['path']) && is_string($attachment['path']) && file_exists($attachment['path'])) {
          // File on disk: read and base64-encode; skip if unreadable.
          $bytes = file_get_contents($attachment['path']);
          if ($bytes === FALSE) {
            continue;
          }
          $encoded = base64_encode($bytes);
          $name = $name ?? basename($attachment['path']);
        }
        else {
          continue;
        }

        $attachments[] = [
          'Name' => (string) ($name ?? 'attachment'),
          'Content' => $encoded,
          'ContentType' => (string) $contentType,
        ];
      }
      if (!empty($attachments)) {
        $payload['Attachments'] = $attachments;
      }
    }

    try {
      $client = $this->httpClientFactory->fromOptions([
        'headers' => [
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
          'X-Postmark-Server-Token' => $serverToken,
        ],
      ]);

      $response = $client->post(self::API_ENDPOINT, [
        'json' => $payload,
      ]);

      $statusCode = $response->getStatusCode();
      $body = (string) $response->getBody();
      $data = json_decode($body, TRUE);

      if ($statusCode === 200 && isset($data['MessageID'])) {
        // Store provider message ID for retrieval by MessagingManager.
        $this->lastMessageId = (string) $data['MessageID'];
        $this->logger->info('Postmark message sent. MessageID=@id attachments=@count names=@names', [
          '@id' => $this->lastMessageId,
          '@count' => isset($payload['Attachments']) ? count($payload['Attachments']) : 0,
          '@names' => isset($payload['Attachments']) ? implode(', ', array_column($payload['Attachments'], 'Name')) : '(none)',
        ]);
        return TRUE;
      }

      $this->logger->error('Postmark send failed. Status=@status, Response=@response', [
        '@status' => $statusCode,
        '@response' => $body,
      ]);
      return FALSE;
    }
    catch (\Exception $e) {
      $this->logger->error('Postmark send exception: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Last message ID from send() response.
   *
   * @var string|null
   */
  private ?string $lastMessageId = NULL;

  /**
   * {@inheritdoc}
   */
  public function getLastProviderMessageId(): ?string {
    return $this->lastMessageId;
  }

}
