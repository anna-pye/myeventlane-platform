<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\user\UserInterface;

/**
 * Hooks customer RSVP / order milestones into OnboardingManager (customer track).
 */
final class CustomerIdentityBridge {

  public function __construct(
    private readonly OnboardingManager $onboardingManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Progresses customer onboarding after an RSVP tied to a logged-in user.
   */
  public function onAuthenticatedRsvpUid(int $uid): void {
    if ($uid <= 0) {
      return;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      return;
    }
    try {
      $this->onboardingManager->progressCustomerAfterRsvp($user);
    }
    catch (\Throwable $e) {
      $this->logger->error('Customer RSVP onboarding progression failed uid=@uid: @m', [
        '@uid' => (string) $uid,
        '@m' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Progresses customer onboarding after a Commerce order is placed (completed flow).
   *
   * @param object $order
   *   A Commerce order entity.
   */
  public function onCommerceOrderPlaced(object $order): void {
    if (!interface_exists(\Drupal\commerce_order\Entity\OrderInterface::class)) {
      return;
    }
    if (!$order instanceof \Drupal\commerce_order\Entity\OrderInterface) {
      return;
    }
    $uid = (int) $order->getCustomerId();
    if ($uid <= 0) {
      return;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      return;
    }
    try {
      $this->onboardingManager->progressCustomerAfterPlacedOrder($user);
    }
    catch (\Throwable $e) {
      $this->logger->error('Customer order onboarding progression failed uid=@uid: @m', [
        '@uid' => (string) $uid,
        '@m' => $e->getMessage(),
      ]);
    }
  }

}
