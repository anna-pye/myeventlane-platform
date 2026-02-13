<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_ai\Service;

use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;

/**
 * Centralised enqueue logic (no duplication).
 */
final class EscalationAiJobEnqueuer {

  /**
   * Queue name for escalation AI jobs.
   */
  public const QUEUE_NAME = 'myeventlane_escalations_ai.jobs';

  public function __construct(
    private readonly QueueFactory $queueFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Enqueues an AI job for the given escalation.
   *
   * @param int $escalation_id
   *   The escalation entity ID.
   * @param string $task
   *   One of: triage, reply_suggestion, risk_flag, breach_soon.
   * @param int|null $requested_by_uid
   *   UID of the staff member who triggered this, if applicable.
   * @param array $metadata
   *   Optional metadata to include in the queue item (e.g. hours_remaining).
   */
  public function enqueue(int $escalation_id, string $task, ?int $requested_by_uid = NULL, array $metadata = []): void {
    $queue = $this->queueFactory->get(self::QUEUE_NAME, TRUE);

    $item = [
      'escalation_id' => $escalation_id,
      'task' => $task,
      'requested_by_uid' => $requested_by_uid,
      'created' => time(),
    ] + $metadata;

    $queue->createItem($item);

    $this->logger->info('Queued AI job task={task} escalation_id={id}', [
      'task' => $task,
      'id' => $escalation_id,
    ]);
  }

}
