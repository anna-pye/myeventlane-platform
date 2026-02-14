<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_ai\Entity\AiJob;
use Psr\Log\LoggerInterface;

/**
 * Cleans up expired AI jobs (done/error) via cron.
 *
 * Never deletes queued or running jobs.
 */
final class AiJobCleanupService {

  private const BATCH_LIMIT = 100;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Deletes up to BATCH_LIMIT expired jobs.
   *
   * @return int
   *   Number of jobs deleted.
   */
  public function run(): int {
    $now = time();
    $storage = $this->entityTypeManager->getStorage('ai_job');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', [AiJob::STATUS_DONE, AiJob::STATUS_ERROR], 'IN')
      ->condition('expires_at', 0, '>')
      ->condition('expires_at', $now, '<')
      ->range(0, self::BATCH_LIMIT)
      ->execute();

    if ($ids === []) {
      return 0;
    }

    $entities = $storage->loadMultiple($ids);
    $storage->delete($entities);

    $count = count($ids);
    $this->logger->debug('AI job cleanup: deleted @count expired jobs.', [
      '@count' => $count,
    ]);

    return $count;
  }

}
