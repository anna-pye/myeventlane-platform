<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\myeventlane_domain_events\Service\DomainEventRepository;
use Drupal\myeventlane_domain_events\Service\ProjectorRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued domain event projections.
 *
 * @QueueWorker(
 *   id = "myeventlane_domain_event_projector",
 *   title = @Translation("MyEventLane Domain Event Projector"),
 *   cron = {"time" = 60}
 * )
 */
final class DomainEventProjectorQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  private const MAX_RETRIES = 5;
  private const DEFAULT_BATCH_LIMIT = 100;
  private const MAX_BATCH_LIMIT = 500;
  private const PROJECTOR_QUEUE = 'myeventlane_domain_event_projector';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly DomainEventRepository $repository,
    private readonly ProjectorRegistry $registry,
    private readonly QueueFactory $queueFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('myeventlane_domain_events.repository'),
      $container->get('myeventlane_domain_events.projector_registry'),
      $container->get('queue'),
      $container->get('datetime.time'),
      $container->get('logger.channel.myeventlane_domain_events'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (isset($data['batch_after_id'])) {
      $afterId = (int) $data['batch_after_id'];
      $batchLimit = isset($data['batch_limit']) ? (int) $data['batch_limit'] : self::DEFAULT_BATCH_LIMIT;
      $batchLimit = max(1, min(self::MAX_BATCH_LIMIT, $batchLimit));
      $this->processBatch($afterId, $batchLimit);
      return;
    }

    $eventId = isset($data['event_id']) ? (int) $data['event_id'] : 0;
    if ($eventId <= 0) {
      return;
    }

    $event = $this->repository->fetchEvent($eventId);
    if ($event === NULL) {
      $this->logger->warning('Projection queue item references missing event {event_id}.', [
        'event_id' => $eventId,
      ]);
      return;
    }

    $this->processSingleEvent($event, FALSE);
  }

  /**
   * Processes a batch of events fetched from the event store.
   */
  private function processBatch(int $afterId, int $batchLimit): void {
    $events = $this->repository->fetchEventsAfterId($afterId, $batchLimit);
    foreach ($events as $event) {
      $eventId = (int) ($event['id'] ?? 0);
      if ($eventId <= 0) {
        continue;
      }

      $failedProjector = $this->processSingleEvent($event, TRUE);
      if ($failedProjector !== NULL) {
        $retryState = $this->repository->fetchProjectionState($failedProjector, $eventId);
        $retryCount = (int) ($retryState['retry_count'] ?? 0);
        if ($retryCount < self::MAX_RETRIES) {
          $this->queueFactory->get(self::PROJECTOR_QUEUE)->createItem([
            'batch_after_id' => $eventId - 1,
            'batch_limit' => $batchLimit,
          ]);
        }
        return;
      }
    }
  }

  /**
   * Processes all supporting projectors for one event.
   *
   * @param array<string, mixed> $event
   *   Normalized domain event row.
   *
   * @return string|null
   *   Failed projector ID when processing fails; NULL on success.
   */
  private function processSingleEvent(array $event, bool $isBatch): ?string {
    $eventId = (int) $event['id'];
    $projectors = $this->registry->getProjectorsForEventType((string) $event['event_type']);
    foreach ($projectors as $projector) {
      $projectorId = $projector->id();
      $state = $this->repository->fetchProjectionState($projectorId, $eventId);
      if ($state !== NULL && (string) $state['status'] === 'processed') {
        continue;
      }

      $retryCount = (int) ($state['retry_count'] ?? 0);
      try {
        $projector->project($event);
        $this->repository->saveProjectionState(
          $projectorId,
          $eventId,
          'processed',
          $this->time->getCurrentTime(),
          $retryCount,
          NULL,
        );
        $this->repository->saveProjectionCheckpoint(
          $projectorId,
          $eventId,
          isset($event['occurred_at']) ? (int) $event['occurred_at'] : NULL,
        );
      }
      catch (\Throwable $e) {
        $retryCount++;
        $this->repository->saveProjectionState(
          $projectorId,
          $eventId,
          'failed',
          NULL,
          $retryCount,
          $e->getMessage(),
        );

        $this->logger->error('Projector {projector_id} failed for event {event_id}: {message}', [
          'projector_id' => $projectorId,
          'event_id' => $eventId,
          'message' => $e->getMessage(),
        ]);

        if ($retryCount < self::MAX_RETRIES) {
          if (!$isBatch) {
            throw new RequeueException($e->getMessage());
          }
        }
        return $projectorId;
      }
    }

    return NULL;
  }

}

