<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Psr\Log\LoggerInterface;

/**
 * Tracks daily AI token and request usage per user and per vendor.
 *
 * Uses KeyValueExpirable for auto-expiry (retention_days). Keys:
 * - myeventlane_ai.usage.user.{uid}.{YYYYMMDD} = {tokens, requests}
 * - myeventlane_ai.usage.vendor.{vendor_id}.{YYYYMMDD} = {tokens, requests}
 */
final class AiUsageTracker {

  public function __construct(
    private readonly KeyValueStoreExpirableInterface $usageStore,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LockBackendInterface $lock,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Gets today's date string (YYYYMMDD).
   */
  private function today(): string {
    return date('Ymd', time());
  }

  /**
   * Gets TTL in seconds for usage keys (retention_days from now).
   */
  private function expiryTtl(): int {
    $config = $this->configFactory->get('myeventlane_ai.settings');
    $days = (int) $config->get('usage_retention_days') ?: 7;
    return $days * 86400;
  }

  /**
   * Gets current usage for a user for today.
   *
   * @return array{tokens: int, requests: int}
   */
  public function getUserUsage(int $uid): array {
    $key = 'user.' . $uid . '.' . $this->today();
    $data = $this->usageStore->get($key);
    if (!is_array($data)) {
      return ['tokens' => 0, 'requests' => 0];
    }
    return [
      'tokens' => (int) ($data['tokens'] ?? 0),
      'requests' => (int) ($data['requests'] ?? 0),
    ];
  }

  /**
   * Gets current usage for a vendor for today.
   *
   * @return array{tokens: int, requests: int}
   */
  public function getVendorUsage(int $vendor_id): array {
    $key = 'vendor.' . $vendor_id . '.' . $this->today();
    $data = $this->usageStore->get($key);
    if (!is_array($data)) {
      return ['tokens' => 0, 'requests' => 0];
    }
    return [
      'tokens' => (int) ($data['tokens'] ?? 0),
      'requests' => (int) ($data['requests'] ?? 0),
    ];
  }

  /**
   * Records usage for a user and optionally a vendor.
   *
   * @param int $uid
   *   User ID.
   * @param int|null $vendor_id
   *   Vendor ID if vendor context applies, NULL otherwise.
   * @param int $tokens
   *   Tokens consumed.
   */
  public function recordUsage(int $uid, ?int $vendor_id, int $tokens): void {
    $lock_id = 'myeventlane_ai_usage_' . $uid . '_' . ($vendor_id ?? 'u');
    if (!$this->lock->acquire($lock_id, 5)) {
      $this->logger->warning('AI usage tracker: could not acquire lock');
      return;
    }

    try {
      $today = $this->today();
      $ttl = $this->expiryTtl();

      $user_key = 'user.' . $uid . '.' . $today;
      $user_data = $this->usageStore->get($user_key);
      if (!is_array($user_data)) {
        $user_data = ['tokens' => 0, 'requests' => 0];
      }
      $user_data['tokens'] = (int) ($user_data['tokens'] ?? 0) + $tokens;
      $user_data['requests'] = (int) ($user_data['requests'] ?? 0) + 1;
      $this->usageStore->setWithExpire($user_key, $user_data, $ttl);

      if ($vendor_id !== null) {
        $vendor_key = 'vendor.' . $vendor_id . '.' . $today;
        $vendor_data = $this->usageStore->get($vendor_key);
        if (!is_array($vendor_data)) {
          $vendor_data = ['tokens' => 0, 'requests' => 0];
        }
        $vendor_data['tokens'] = (int) ($vendor_data['tokens'] ?? 0) + $tokens;
        $vendor_data['requests'] = (int) ($vendor_data['requests'] ?? 0) + 1;
        $this->usageStore->setWithExpire($vendor_key, $vendor_data, $ttl);
      }
    }
    finally {
      $this->lock->release($lock_id);
    }
  }

  /**
   * Checks if user would exceed daily token limit.
   */
  public function wouldExceedUserLimit(int $uid, int $reservation_tokens): bool {
    $config = $this->configFactory->get('myeventlane_ai.settings');
    $limit = (int) $config->get('daily_user_token_limit') ?: 50000;
    $usage = $this->getUserUsage($uid);
    return ($usage['tokens'] + $reservation_tokens) > $limit;
  }

  /**
   * Checks if vendor would exceed daily token limit.
   */
  public function wouldExceedVendorLimit(int $vendor_id, int $reservation_tokens): bool {
    $config = $this->configFactory->get('myeventlane_ai.settings');
    $limit = (int) $config->get('daily_vendor_token_limit') ?: 200000;
    $usage = $this->getVendorUsage($vendor_id);
    return ($usage['tokens'] + $reservation_tokens) > $limit;
  }

}
