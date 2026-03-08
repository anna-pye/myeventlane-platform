<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;

/**
 * Publishes durable domain events and queues projector processing.
 */
final class DomainEventBus {

  private const PROJECTOR_QUEUE = 'myeventlane_domain_event_projector';

  public function __construct(
    private readonly UuidInterface $uuid,
    private readonly QueueFactory $queueFactory,
    private readonly TimeInterface $time,
    private readonly DomainEventRepository $repository,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Publishes one domain event and returns its event-store row ID.
   *
   * @param array<string, mixed> $payload
   *   Event payload.
   * @param array<string, mixed> $context
   *   Event metadata/context.
   */
  public function publish(
    string $eventType,
    string $aggregateType,
    string $aggregateId,
    array $payload,
    array $context = [],
  ): int {
    $eventUuid = $this->uuid->generate();
    $context += [
      'source_module' => 'myeventlane_domain_events',
      'occurred_at' => $this->time->getCurrentTime(),
    ];

    $eventId = $this->repository->insertEvent(
      $eventUuid,
      $eventType,
      $aggregateType,
      $aggregateId,
      $payload,
      $context,
    );

    $this->queueFactory
      ->get(self::PROJECTOR_QUEUE)
      ->createItem(['event_id' => $eventId]);

    $this->logger->info('Published domain event {event_type} (#{event_id}).', [
      'event_type' => $eventType,
      'event_id' => $eventId,
    ]);

    return $eventId;
  }

}

