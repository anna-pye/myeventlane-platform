<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\commerce_recurring\Entity\SubscriptionInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Provides platform-level Pro subscription health metrics.
 */
final class ProSubscriptionHealthService {

  private const BILLING_SCHEDULE = 'mel_pro_monthly';
  private const PRO_PRICE_FALLBACK = 49.0;
  private const BATCH_SIZE = 200;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly Connection $database,
    private readonly ProSubscriptionStateResolver $stateResolver,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns the number of active Pro subscriptions.
   */
  public function getActiveProCount(): int {
    $count = 0;
    foreach ($this->loadProSubscriptions() as $subscription) {
      if ($this->stateResolver->isActive($subscription)) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * Calculates MRR from active Pro count and configured Pro price.
   */
  public function getMRR(): float {
    $price = (float) ($this->configFactory->get('myeventlane_pro.settings')->get('pro_price') ?? self::PRO_PRICE_FALLBACK);
    return round($this->getActiveProCount() * $price, 2);
  }

  /**
   * Calculates churn rate over a configurable day window.
   */
  public function getChurnRate(int $days = 30): float {
    $periodDays = max(1, $days);
    $now = $this->time->getRequestTime();
    $periodStart = $now - ($periodDays * 86400);

    $activeAtStart = 0;
    $cancelledInPeriod = 0;

    foreach ($this->loadProSubscriptions() as $subscription) {
      $created = (int) $subscription->getCreatedTime();
      if ($created > $periodStart) {
        continue;
      }

      if ($this->stateResolver->isActive($subscription)) {
        $activeAtStart++;
        continue;
      }

      if ($this->stateResolver->isCancelled($subscription) && (int) $subscription->getChangedTime() >= $periodStart) {
        $cancelledInPeriod++;
      }
    }

    if ($activeAtStart <= 0) {
      return 0.0;
    }

    return round(($cancelledInPeriod / $activeAtStart) * 100, 2);
  }

  /**
   * Counts failed renewals over a configurable day window.
   */
  public function getFailedRenewals(int $days = 30): int {
    $periodDays = max(1, $days);
    $periodStart = $this->time->getRequestTime() - ($periodDays * 86400);
    $failed = 0;

    foreach ($this->loadProSubscriptions() as $subscription) {
      if ((int) $subscription->getChangedTime() < $periodStart) {
        continue;
      }
      if ($this->stateResolver->isPaymentFailure($subscription)) {
        $failed++;
      }
    }

    return $failed;
  }

  /**
   * Counts users currently inside grace period.
   */
  public function getGraceUsers(): int {
    $now = $this->time->getRequestTime();

    try {
      return (int) $this->database->select('user__field_pro_grace_expires', 'g')
        ->condition('g.field_pro_grace_expires_value', $now, '>')
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    catch (\Throwable $exception) {
      $this->logger->error('Failed to count grace users: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Loads Pro subscriptions in memory-safe batches.
   *
   * @return \Generator<int, \Drupal\commerce_recurring\Entity\SubscriptionInterface>
   *   Subscription iterator.
   */
  private function loadProSubscriptions(): \Generator {
    $storage = $this->entityTypeManager->getStorage('commerce_subscription');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('billing_schedule', self::BILLING_SCHEDULE)
      ->sort('subscription_id', 'ASC')
      ->execute();

    foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
      /** @var \Drupal\commerce_recurring\Entity\SubscriptionInterface[] $subscriptions */
      $subscriptions = $storage->loadMultiple($chunk);
      foreach ($subscriptions as $subscription) {
        if ($subscription instanceof SubscriptionInterface) {
          yield $subscription;
        }
      }
      $storage->resetCache($chunk);
    }
  }

}
