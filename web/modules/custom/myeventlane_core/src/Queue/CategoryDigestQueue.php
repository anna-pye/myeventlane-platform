<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Queue;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_core\Service\CategoryDigestGenerator;
use Drupal\user\UserInterface;

/**
 * Queue worker for category digest emails.
 */
final class CategoryDigestQueue extends QueueWorkerBase {

  /**
   * Constructs a CategoryDigestQueue.
   *
   * @param \Drupal\myeventlane_core\Service\CategoryDigestGenerator $generator
   *   The category digest generator.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly CategoryDigestGenerator $generator,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!isset($data['user_id'])) {
      return;
    }

    $userStorage = $this->entityTypeManager->getStorage('user');
    $user = $userStorage->load($data['user_id']);

    if (!$user instanceof UserInterface || $user->isBlocked()) {
      return;
    }

    $this->generator->sendDigest($user);
  }

}
