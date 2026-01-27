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

    $payload = [
      'From' => !empty($fromName) ? "{$fromName} <{$fromEmail}>" : $fromEmail,
      'To' => $to,
      'Subject' => $subject,
      'HtmlBody' => $htmlBody,
      'MessageStream' => 'outbound',
    ];

    if (!empty($replyTo)) {
      $payload['ReplyTo'] = $replyTo;
    }

    // Handle attachments if provided.
    if (!empty($params['attachments']) && is_array($params['attachments'])) {
      $attachments = [];
      foreach ($params['attachments'] as $attachment) {
        if (isset($attachment['path']) && file_exists($attachment['path'])) {
          $content = file_get_contents($attachment['path']);
          if ($content !== FALSE) {
            $attachments[] = [
              'Name' => $attachment['name'] ?? basename($attachment['path']),
              'Content' => base64_encode($content),
              'ContentType' => $attachment['content_type'] ?? 'application/octet-stream',
            ];
          }
        }
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
        $this->logger->info('Postmark message sent. MessageID=@id', [
          '@id' => $this->lastMessageId,
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
   * Gets the last provider message ID.
   *
   * @return string|null
   *   Provider message ID from last send() call.
   */
  public function getLastMessageId(): ?string {
    return $this->lastMessageId;
  }

}
