<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Queue\QueueFactory;

/**
 * Encapsulates Sunday-only category digest queueing for hook_cron().
 *
 * Sends weekly category digests on Sundays by enqueueing user IDs for
 * the CategoryDigestQueue worker. Behaviour is identical to the prior
 * inline hook_cron logic.
 */
final class CategoryDigestCron {

  /**
   * Constructs a CategoryDigestCron.
   *
   * @param \Drupal\myeventlane_core\Service\CategoryDigestGenerator $digestGenerator
   *   The category digest generator.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   */
  public function __construct(
    private readonly CategoryDigestGenerator $digestGenerator,
    private readonly QueueFactory $queueFactory,
  ) {}

  /**
   * Runs Sunday-only digest: enqueues user IDs for mel_category_digest.
   *
   * No-op when the current day is not Sunday. Otherwise loads users with
   * followed categories and creates one queue item per user.
   */
  public function runSundayDigest(): void {
    // 0 = Sunday.
    $dayOfWeek = (int) date('w');
    if ($dayOfWeek !== 0) {
      return;
    }

    $userIds = $this->digestGenerator->getUsersWithFollowedCategories();

    if (empty($userIds)) {
      return;
    }

    $queue = $this->queueFactory->get('mel_category_digest');
    foreach ($userIds as $userId) {
      $queue->createItem(['user_id' => $userId]);
    }
  }

}
