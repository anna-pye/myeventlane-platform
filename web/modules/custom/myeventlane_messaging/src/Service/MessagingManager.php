<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;

/**
 * Queues and sends transactional messages.
 */
final class MessagingManager {

  /**
   * Constructs a MessagingManager.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   The mail manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $lang
   *   The language manager.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\myeventlane_messaging\Service\MessageRenderer $messageRenderer
   *   The message renderer.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MailManagerInterface $mailManager,
    private readonly LanguageManagerInterface $lang,
    private readonly QueueFactory $queueFactory,
    private readonly LoggerInterface $logger,
    private readonly MessageRenderer $messageRenderer,
  ) {}

  /**
   * Adds a message payload to the messaging queue.
   *
   * @param string $type
   *   The template key.
   * @param string $to
   *   The recipient email.
   * @param array $context
   *   The template context.
   * @param array $opts
   *   Additional options (e.g. langcode).
   */
  public function queue(string $type, string $to, array $context = [], array $opts = []): void {
    $payload = [
      'type' => $type,
      'to' => $to,
      'context' => $context,
      'opts' => $opts,
    ];

    $this->queueFactory->get('myeventlane_messaging')->createItem($payload);
  }

  /**
   * Sends a prepared message payload immediately.
   *
   * @param array $payload
   *   The queued message payload.
   */
  public function sendNow(array $payload): void {
    $type = $payload['type'] ?? 'generic';
    $to = $payload['to'] ?? '';
    $ctx = $payload['context'] ?? [];
    $opts = $payload['opts'] ?? [];

    if (!$to) {
      $this->logger->warning('Skipping message with empty recipient.');
      return;
    }

    $conf = $this->configFactory->get("myeventlane_messaging.template.$type");
    if (!$conf || !$conf->get('enabled')) {
      $this->logger->notice('Template @type disabled or missing.', ['@type' => $type]);
      return;
    }

    // SUBJECT: render as Twig string (no theme), then strip and truncate.
    $subject_tpl = (string) ($conf->get('subject') ?? '');
    $subject_raw = $this->messageRenderer->renderString($subject_tpl, $ctx);
    $subject = Html::decodeEntities(strip_tags($subject_raw));

    // Keep well under common varchar limits (e.g., 255) and Easy Email’s field.
    $subject = Unicode::truncate($subject, 150, TRUE, TRUE);

    // BODY: render inner HTML (Twig string) and wrap with theme shell.
    $body = $this->messageRenderer->renderHtmlBody($conf, $ctx);

    $langcode = $opts['langcode'] ?? $this->lang->getDefaultLanguage()->getId();

    $params = [
      'subject' => $subject,
      // Plain fallback (ok if HTML too).
      'body' => $body,
      // Used by symfony_mailer_lite / Easy Email.
      'html' => $body,
      // Headers are set in hook_mail(), not here.
    ];

    // Add attachments if provided.
    if (!empty($opts['attachments']) && is_array($opts['attachments'])) {
      $params['attachments'] = $opts['attachments'];
    }

    $result = $this->mailManager->mail(
      'myeventlane_messaging',
      'generic',
      $to,
      $langcode,
      $params,
    );

    if (!empty($result['result'])) {
      $this->logger->info('Sent message @type to @to.', ['@type' => $type, '@to' => $to]);
    }
    else {
      $this->logger->error('Failed sending message @type to @to.', ['@type' => $type, '@to' => $to]);
    }
  }

}
