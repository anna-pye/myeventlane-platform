<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\commerce_recurring\Entity\SubscriptionInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\user\UserInterface;

/**
 * Real-time Pro subscription reporting for admin use.
 */
final class ProReportingService {

  private const BILLING_SCHEDULE = 'mel_pro_monthly';
  private const PRO_ROLE = 'mel_pro';
  private const MANAGED_FIELD = 'field_pro_subscription_managed';
  private const BATCH_SIZE = 50;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $logger,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns aggregate Pro subscription metrics.
   *
   * @return array{active_count: int, cancelled_30d_count: int, mrr: float, arr: float}
   */
  public function getMetrics(): array {
    try {
      $activeCount = $this->getActiveSubscriptionCount();
      $cancelled30d = $this->getCancelledLast30DaysCount();
      $mrr = $this->computeMrr();

      return [
        'active_count' => $activeCount,
        'cancelled_30d_count' => $cancelled30d,
        'mrr' => $mrr,
        'arr' => round($mrr * 12, 2),
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to compute Pro metrics: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [
        'active_count' => 0,
        'cancelled_30d_count' => 0,
        'mrr' => 0.0,
        'arr' => 0.0,
      ];
    }
  }

  /**
   * Returns vendor-level subscription detail rows.
   *
   * @return array<int, array{uid: int, username: string, email: string, subscription_id: int, subscription_state: string, subscription_state_id: string, next_renewal: ?string, created: string, is_managed: bool, has_pro_role: bool}>
   */
  public function getVendorRows(): array {
    try {
      return $this->buildVendorRows();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to build Pro vendor rows: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  private function getActiveSubscriptionCount(): int {
    return (int) $this->entityTypeManager->getStorage('commerce_subscription')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('billing_schedule', self::BILLING_SCHEDULE)
      ->condition('state', 'active')
      ->count()
      ->execute();
  }

  private function getCancelledLast30DaysCount(): int {
    $thirtyDaysAgo = $this->time->getRequestTime() - (30 * 86400);

    return (int) $this->entityTypeManager->getStorage('commerce_subscription')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('billing_schedule', self::BILLING_SCHEDULE)
      ->condition('state', 'canceled')
      ->condition('changed', $thirtyDaysAgo, '>=')
      ->count()
      ->execute();
  }

  /**
   * Computes MRR by summing unit_price of all active Pro subscriptions.
   */
  private function computeMrr(): float {
    $storage = $this->entityTypeManager->getStorage('commerce_subscription');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('billing_schedule', self::BILLING_SCHEDULE)
      ->condition('state', 'active')
      ->execute();

    if (empty($ids)) {
      return 0.0;
    }

    $mrr = 0.0;
    foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
      /** @var \Drupal\commerce_recurring\Entity\SubscriptionInterface[] $subscriptions */
      $subscriptions = $storage->loadMultiple($chunk);

      foreach ($subscriptions as $subscription) {
        if (!$subscription instanceof SubscriptionInterface) {
          continue;
        }
        $price = $subscription->getUnitPrice();
        if ($price) {
          $mrr += (float) $price->getNumber();
        }
      }

      $storage->resetCache($chunk);
    }

    return round($mrr, 2);
  }

  /**
   * Builds detailed per-vendor subscription rows.
   */
  private function buildVendorRows(): array {
    $storage = $this->entityTypeManager->getStorage('commerce_subscription');
    $userStorage = $this->entityTypeManager->getStorage('user');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('billing_schedule', self::BILLING_SCHEDULE)
      ->sort('created', 'DESC')
      ->execute();

    if (empty($ids)) {
      return [];
    }

    $rows = [];

    foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
      /** @var \Drupal\commerce_recurring\Entity\SubscriptionInterface[] $subscriptions */
      $subscriptions = $storage->loadMultiple($chunk);

      $uids = [];
      foreach ($subscriptions as $subscription) {
        if ($subscription instanceof SubscriptionInterface) {
          $uids[] = (int) $subscription->getCustomerId();
        }
      }

      /** @var \Drupal\user\UserInterface[] $users */
      $users = !empty($uids) ? $userStorage->loadMultiple(array_unique($uids)) : [];

      foreach ($subscriptions as $subscription) {
        if (!$subscription instanceof SubscriptionInterface) {
          continue;
        }

        $uid = (int) $subscription->getCustomerId();
        $user = $users[$uid] ?? NULL;

        $username = '';
        $email = '';
        $isManaged = FALSE;
        $hasProRole = FALSE;

        if ($user instanceof UserInterface) {
          $username = $user->getAccountName() ?? $user->getDisplayName();
          $email = $user->getEmail() ?? '';
          $hasProRole = in_array(self::PRO_ROLE, $user->getRoles(), TRUE);
          if ($user->hasField(self::MANAGED_FIELD)) {
            $isManaged = (bool) $user->get(self::MANAGED_FIELD)->value;
          }
        }

        $state = $subscription->getState();
        $stateLabel = ucfirst($state->getLabel() ?? $state->getId());

        $nextRenewal = NULL;
        if (method_exists($subscription, 'getNextRenewalTime')) {
          $timestamp = $subscription->getNextRenewalTime();
          if ($timestamp) {
            $nextRenewal = date('Y-m-d', (int) $timestamp);
          }
        }

        $rows[] = [
          'uid' => $uid,
          'username' => $username,
          'email' => $email,
          'subscription_id' => (int) $subscription->id(),
          'subscription_state' => $stateLabel,
          'subscription_state_id' => $state->getId(),
          'next_renewal' => $nextRenewal,
          'created' => date('Y-m-d', (int) $subscription->getCreatedTime()),
          'is_managed' => $isManaged,
          'has_pro_role' => $hasProRole,
        ];
      }

      $storage->resetCache($chunk);
      $userStorage->resetCache(array_unique($uids));
    }

    return $rows;
  }

}
