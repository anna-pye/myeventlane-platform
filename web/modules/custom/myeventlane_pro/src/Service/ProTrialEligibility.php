<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;

/**
 * Enforces the once-per-organiser MEL Pro trial policy.
 */
final class ProTrialEligibility {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns TRUE only when the organiser has never held a Pro subscription.
   */
  public function isEligible(UserInterface $user): bool {
    $ids = $this->entityTypeManager->getStorage('commerce_subscription')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $user->id())
      ->condition('billing_schedule', ProBillingSchedule::ALL, 'IN')
      ->range(0, 1)
      ->execute();

    return $ids === [];
  }

}
