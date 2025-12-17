<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Queue;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_core\Service\CategoryDigestGenerator;
use Drupal\user\UserInterface;

/**
 * Queue worker for category digest emails.
 */
final class CategoryDigestQueue extends QueueWorkerBase {

  /**
   * Constructs a CategoryDigestQueue.
   */
  public function __construct(
    private readonly CategoryDigestGenerator $generator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!isset($data['user_id'])) {
      return;
    }

    $userStorage = \Drupal::entityTypeManager()->getStorage('user');
    $user = $userStorage->load($data['user_id']);

    if (!$user instanceof UserInterface || $user->isBlocked()) {
      return;
    }

    $this->generator->sendDigest($user);
  }

}




















