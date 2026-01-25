<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued MyEventLane messages.
 *
 * @QueueWorker(
 *   id = "myeventlane_messaging",
 *   title = @Translation("MyEventLane Messaging queue"),
 *   cron = {"time" = 60}
 * )
 */
final class MessagingQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The queue name for this worker.
   */
  private const QUEUE_NAME = 'myeventlane_messaging';

  /**
   * Maximum number of attempts before giving up.
   */
  private const MAX_ATTEMPTS = 5;

  /**
   * Constructs MessagingQueueWorker.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\myeventlane_messaging\Service\MessagingManager $messagingManager
   *   The messaging manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly MessagingManager $messagingManager,
    private readonly LoggerInterface $logger,
    private readonly QueueFactory $queueFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('myeventlane_messaging.manager'),
      $container->get('logger.factory')->get('myeventlane_messaging'),
      $container->get('queue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $ctx = $data['context'] ?? [];

    $eventId = isset($ctx['event_id']) && is_numeric($ctx['event_id']) ? (int) $ctx['event_id'] : NULL;
    $orderId = isset($ctx['order_id']) && is_numeric($ctx['order_id']) ? (int) $ctx['order_id'] : NULL;
    $submissionId = isset($ctx['submission_id']) && is_numeric($ctx['submission_id']) ? (int) $ctx['submission_id'] : NULL;
    $messageType = $data['type'] ?? 'generic';

    $attempt = (int) ($data['attempt'] ?? 0);
    $attempt++;
    $data['attempt'] = $attempt;

    try {
      $this->messagingManager->sendNow($data);
    }
    catch (\Throwable $e) {
      if ($attempt >= self::MAX_ATTEMPTS) {
        $this->logger->error('Messaging queue item failed permanently after @attempt attempts. @message', [
          '@attempt' => $attempt,
          '@message' => $e->getMessage(),
          'queue_name' => self::QUEUE_NAME,
          'event_id' => $eventId,
          'order_id' => $orderId,
          'submission_id' => $submissionId,
          'message_type' => $messageType,
        ]);
        // Fail-safe: do not block cron/checkout/RSVP; drop after cap.
        return;
      }

      // Requeue with incremented attempt count and last error.
      $data['last_error'] = $e->getMessage();
      $this->queueFactory->get(self::QUEUE_NAME)->createItem($data);

      $this->logger->warning('Messaging queue item failed; requeued for retry (@attempt/@max). @message', [
        '@attempt' => $attempt,
        '@max' => self::MAX_ATTEMPTS,
        '@message' => $e->getMessage(),
        'queue_name' => self::QUEUE_NAME,
        'event_id' => $eventId,
        'order_id' => $orderId,
        'submission_id' => $submissionId,
        'message_type' => $messageType,
      ]);
      return;
    }
  }

}
