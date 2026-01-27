<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service\Delivery;

use Drupal\Core\Mail\MailManagerInterface;

/**
 * Delivery provider using Drupal plugin.manager.mail.
 */
final class DrupalMailProvider implements DeliveryProviderInterface {

  /**
   * Constructs DrupalMailProvider.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   The mail manager.
   */
  public function __construct(
    private readonly MailManagerInterface $mailManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'drupal_mail';
  }

  /**
   * {@inheritdoc}
   */
  public function send(array $params): bool {
    $to = $params['to'] ?? '';
    $subject = $params['subject'] ?? '(no subject)';
    $body = $params['body'] ?? '';
    $html = $params['html'] ?? $body;
    $langcode = $params['langcode'] ?? 'en';
    $attachments = $params['attachments'] ?? [];

    $messageParams = [
      'subject' => $subject,
      'body' => $html,
      'html' => $html,
    ];
    if (!empty($attachments) && is_array($attachments)) {
      $messageParams['attachments'] = $attachments;
    }
    if (!empty($params['from_name'])) {
      $messageParams['from_name'] = $params['from_name'];
    }
    if (!empty($params['from_email'])) {
      $messageParams['from_email'] = $params['from_email'];
    }
    if (!empty($params['reply_to'])) {
      $messageParams['reply_to'] = $params['reply_to'];
    }

    $result = $this->mailManager->mail(
      'myeventlane_messaging',
      'generic',
      $to,
      $langcode,
      $messageParams,
    );

    return !empty($result['result']);
  }

}
