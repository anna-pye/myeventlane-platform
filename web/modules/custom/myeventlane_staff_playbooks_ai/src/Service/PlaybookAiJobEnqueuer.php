<?php

declare(strict_types=1);

namespace Drupal\myeventlane_staff_playbooks_ai\Service;

use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;

/**
 * Enqueues AI summary jobs for staff playbooks.
 */
final class PlaybookAiJobEnqueuer {

  public const QUEUE_NAME = 'myeventlane_staff_playbooks_ai.summary';

  public function __construct(
    private readonly QueueFactory $queueFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Enqueues a summary job for the given playbook node.
   *
   * @param int $node_id
   *   The staff_playbook node ID.
   * @param string $insight_type
   *   Insight type (default: summary).
   * @param int|null $requested_by_uid
   *   UID of the staff member who triggered this.
   */
  public function enqueue(int $node_id, string $insight_type = 'summary', ?int $requested_by_uid = NULL): void {
    $queue = $this->queueFactory->get(self::QUEUE_NAME, TRUE);

    $item = [
      'node_id' => $node_id,
      'insight_type' => $insight_type,
      'requested_by_uid' => $requested_by_uid,
      'created' => time(),
    ];

    $queue->createItem($item);

    $this->logger->info('Queued playbook AI summary job node_id={id}', [
      'id' => $node_id,
    ]);
  }

}
