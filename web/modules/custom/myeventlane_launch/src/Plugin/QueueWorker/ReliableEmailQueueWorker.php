<?php

declare(strict_types=1);

namespace Drupal\myeventlane_launch\Plugin\QueueWorker;

use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_launch\Service\MELMonitoringService;
use Drupal\myeventlane_launch\Service\ReliableEmailSender;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes reliable launch emails with retries.
 *
 * @QueueWorker(
 *   id = "myeventlane_reliable_mail",
 *   title = @Translation("MyEventLane Launch Reliable Email Queue"),
 *   cron = {"time" = 60}
 * )
 */
final class ReliableEmailQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  private const MAX_RETRIES = 3;

  public function __construct(
    array $configuration,
    string $pluginId,
    mixed $pluginDefinition,
    private readonly MailManagerInterface $mailManager,
    private readonly QueueFactory $queueFactory,
    private readonly ReliableEmailSender $emailSender,
    private readonly MELMonitoringService $monitoringService,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.mail'),
      $container->get('queue'),
      $container->get('myeventlane_launch.email_sender'),
      $container->get('myeventlane_launch.monitoring'),
      $container->get('logger.channel.myeventlane_launch'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem(mixed $data): void {
    if (!is_array($data)) {
      return;
    }

    $messageId = (string) ($data['message_id'] ?? '');
    $to = (string) ($data['to'] ?? '');
    $subject = (string) ($data['subject'] ?? '');
    $body = (string) ($data['body'] ?? '');
    $headers = isset($data['headers']) && is_array($data['headers']) ? $data['headers'] : [];
    $attempt = (int) ($data['attempts'] ?? 0) + 1;

    if ($messageId === '' || $to === '') {
      $this->logger->error('Reliable email queue item is missing required fields.');
      return;
    }

    $result = $this->mailManager->mail(
      'myeventlane_launch',
      'reliable_delivery',
      $to,
      'en',
      [
        'subject' => $subject,
        'body' => $body,
        'headers' => $headers,
      ],
      NULL,
      TRUE
    );

    if (!empty($result['result'])) {
      $this->emailSender->setStatus($messageId, [
        'status' => 'sent',
        'attempt' => $attempt,
        'sent_at' => time(),
      ]);
      return;
    }

    $data['attempts'] = $attempt;
    $errorMessage = 'Mail manager reported a failed send result.';
    $this->emailSender->markFailure($messageId, $data, $errorMessage, $attempt);

    if ($attempt < self::MAX_RETRIES) {
      $this->queueFactory->get('myeventlane_reliable_mail')->createItem($data);
      $this->logger->warning('Reliable email retry scheduled for message {message_id}, attempt {attempt}.', [
        'message_id' => $messageId,
        'attempt' => $attempt,
      ]);
      return;
    }

    $this->monitoringService->monitorCriticalFailure('email_retry_exhausted', [
      'message_id' => $messageId,
      'recipient' => $to,
      'attempts' => $attempt,
    ]);
  }

}
