<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_policy\Service;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;

/**
 * Stores per-vendor risk streak data using keyvalue.
 *
 * Collection: myeventlane_escalations_policy
 * Key: vendor:{vendor_id}
 *
 * Stored structure per vendor:
 *   - risk_streak: int (consecutive at-risk weeks)
 *   - last_bucket: string (last evaluated health bucket)
 *   - last_run_week: string (YYYY-WW of last evaluation)
 *   - last_triggered_week: string (YYYY-WW of last policy trigger, or '')
 *   - last_triggered_timestamp: int (UNIX timestamp of last trigger, or 0)
 */
final class VendorRiskStreakStore {

  private const COLLECTION = 'myeventlane_escalations_policy';

  public function __construct(
    private readonly KeyValueFactoryInterface $keyValueFactory,
  ) {}

  /**
   * Gets the streak record for a vendor.
   *
   * @param int $vendor_id
   *   The vendor entity ID.
   *
   * @return array
   *   Streak record with keys: risk_streak, last_bucket, last_run_week,
   *   last_triggered_week, last_triggered_timestamp.
   */
  public function get(int $vendor_id): array {
    $store = $this->keyValueFactory->get(self::COLLECTION);
    $key = 'vendor:' . $vendor_id;
    $data = $store->get($key, []);

    return $data + [
      'risk_streak' => 0,
      'last_bucket' => '',
      'last_run_week' => '',
      'last_triggered_week' => '',
      'last_triggered_timestamp' => 0,
    ];
  }

  /**
   * Saves the streak record for a vendor.
   *
   * @param int $vendor_id
   *   The vendor entity ID.
   * @param array $data
   *   Streak record array.
   */
  public function set(int $vendor_id, array $data): void {
    $store = $this->keyValueFactory->get(self::COLLECTION);
    $key = 'vendor:' . $vendor_id;
    $store->set($key, $data);
  }

  /**
   * Returns all stored vendor streak records.
   *
   * @return array<int, array>
   *   Map of vendor_id => streak record.
   */
  public function getAll(): array {
    $store = $this->keyValueFactory->get(self::COLLECTION);
    $all = $store->getAll();
    $result = [];

    foreach ($all as $key => $data) {
      if (!str_starts_with($key, 'vendor:')) {
        continue;
      }

      $vendor_id = (int) substr($key, 7);
      $result[$vendor_id] = $data + [
        'risk_streak' => 0,
        'last_bucket' => '',
        'last_run_week' => '',
        'last_triggered_week' => '',
        'last_triggered_timestamp' => 0,
      ];
    }

    return $result;
  }

}
