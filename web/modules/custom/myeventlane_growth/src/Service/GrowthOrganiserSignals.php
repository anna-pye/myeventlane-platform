<?php

declare(strict_types=1);

namespace Drupal\myeventlane_growth\Service;

use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Short-lived organiser signals (Pro gate attempts, cancel flow, billing recovery).
 *
 * Values use the private tempstore expiry from core (%tempstore.expire%, typically 7 days).
 */
final class GrowthOrganiserSignals {

  private const COLLECTION = 'myeventlane_growth';

  public function __construct(
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  public function recordProFeatureDenied(int $uid, string $route_name): void {
    if ($uid <= 0 || $route_name === '') {
      return;
    }
    $store = $this->tempStoreFactory->get(self::COLLECTION);
    $store->set('pro_gate.' . $uid, [
      'route' => $route_name,
      'at' => time(),
    ]);
  }

  /**
   * @return array{route: string, at: int}|null
   */
  public function getProGateSignal(int $uid): ?array {
    if ($uid <= 0) {
      return NULL;
    }
    $store = $this->tempStoreFactory->get(self::COLLECTION);
    $data = $store->get('pro_gate.' . $uid);
    if (!is_array($data) || empty($data['at'])) {
      return NULL;
    }
    return [
      'route' => (string) ($data['route'] ?? ''),
      'at' => (int) $data['at'],
    ];
  }

  public function clearProGateSignal(int $uid): void {
    if ($uid <= 0) {
      return;
    }
    $this->tempStoreFactory->get(self::COLLECTION)->delete('pro_gate.' . $uid);
  }

  public function recordCancelFlowVisit(int $uid): void {
    if ($uid <= 0) {
      return;
    }
    $this->tempStoreFactory->get(self::COLLECTION)->set('cancel.' . $uid, ['at' => time()]);
  }

  public function hasRecentCancelIntent(int $uid): bool {
    if ($uid <= 0) {
      return FALSE;
    }
    $data = $this->tempStoreFactory->get(self::COLLECTION)->get('cancel.' . $uid);
    return is_array($data) && !empty($data['at']);
  }

  public function flagBillingRecoveredCelebration(int $uid): void {
    if ($uid <= 0) {
      return;
    }
    $this->tempStoreFactory->get(self::COLLECTION)->set('billing_ok.' . $uid, ['at' => time()]);
  }

  /**
   * Returns TRUE once per flag set (consumes the flag).
   */
  public function consumeBillingRecoveredCelebration(int $uid): bool {
    if ($uid <= 0) {
      return FALSE;
    }
    $store = $this->tempStoreFactory->get(self::COLLECTION);
    $key = 'billing_ok.' . $uid;
    if ($store->get($key) === NULL) {
      return FALSE;
    }
    $store->delete($key);
    return TRUE;
  }

}
