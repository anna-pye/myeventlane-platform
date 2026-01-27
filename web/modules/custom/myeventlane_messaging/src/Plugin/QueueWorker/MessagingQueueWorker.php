<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\State\StateInterface;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes the canonical MyEventLane messaging queue.
 *
 * Queue items MUST contain only ['message_id' => 'uuid'].
 * Rate-limit and max-messages-per-run are enforced.
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
   * Default max messages to process per cron run (rate-limit guard).
   */
  private const DEFAULT_MAX_PER_RUN = 200;

  /**
   * State key for "messages processed this run" (reset each cron).
   */
  private const STATE_KEY_RUN_COUNT = 'myeventlane_messaging.cron_run_count';

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
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service (for rate-limit tracking).
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory (for max_messages_per_run).
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly MessagingManager $messagingManager,
    private readonly LoggerInterface $logger,
    private readonly QueueFactory $queueFactory,
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
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
      $container->get('state'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $messageId = isset($data['message_id']) && is_string($data['message_id']) ? $data['message_id'] : NULL;

    if ($messageId === '' || $messageId === NULL) {
      $this->logger->error('Messaging queue item missing message_id; dropping.', [
        'queue_name' => self::QUEUE_NAME,
        'data_keys' => is_array($data) ? array_keys($data) : [],
      ]);
      return;
    }

    // Rate-limit: cap messages per cron run.
    $maxPerRun = (int) ($this->getMaxMessagesPerRun());
    $runCount = (int) $this->state->get(self::STATE_KEY_RUN_COUNT, 0);
    if ($maxPerRun > 0 && $runCount >= $maxPerRun) {
      $this->logger->notice('Messaging rate-limit reached (@count/@max); releasing item for next run.', [
        '@count' => $runCount,
        '@max' => $maxPerRun,
        'queue_name' => self::QUEUE_NAME,
      ]);
      throw new \RuntimeException('Rate limit reached; item will be retried.');
    }

    try {
      $this->messagingManager->sendMessage($messageId);
      $this->state->set(self::STATE_KEY_RUN_COUNT, $runCount + 1);
    }
    catch (\Throwable $e) {
      $this->logger->error('Messaging queue item failed. message_id=@id @message', [
        '@id' => $messageId,
        '@message' => $e->getMessage(),
        'queue_name' => self::QUEUE_NAME,
      ]);
      throw $e;
    }
  }

  /**
   * Returns max messages to process per cron run (from config or default).
   */
  private function getMaxMessagesPerRun(): int {
    try {
      $config = $this->configFactory->get('myeventlane_messaging.settings');
      if (!$config->isNew()) {
        $v = $config->get('max_messages_per_run');
        if ($v !== NULL && $v !== '') {
          return (int) $v;
        }
        $v = $config->get('batch_size');
        if ($v !== NULL && $v !== '') {
          return (int) $v;
        }
      }
    }
    catch (\Throwable $e) {
      // Config may not exist yet.
    }
    return self::DEFAULT_MAX_PER_RUN;
  }

}
