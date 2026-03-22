<?php

declare(strict_types=1);

namespace Drupal\myeventlane_growth\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Persists growth funnel events for reporting and cooldown logic.
 */
final class GrowthTrackingService {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Whether tracking writes are enabled.
   */
  public function isTrackingEnabled(): bool {
    $config = $this->configFactory->get('myeventlane_growth.settings');
    if (!(bool) ($config->get('enabled') ?? TRUE)) {
      return FALSE;
    }
    return (bool) ($config->get('tracking.enabled') ?? TRUE);
  }

  public function recordImpression(string $insight_key, int $uid, ?int $event_id, string $surface, array $payload = []): void {
    if ($uid <= 0 || !$this->isTrackingEnabled()) {
      return;
    }
    if ($this->shouldDedupeImpression($insight_key, $uid, $event_id, $surface)) {
      return;
    }
    $now = $this->time->getRequestTime();
    try {
      $this->database->insert('myeventlane_growth_events')
        ->fields([
          'uid' => $uid,
          'event_id' => $event_id,
          'insight_key' => $insight_key,
          'insight_type' => $this->extractTypeFromKey($insight_key),
          'surface' => $surface,
          'shown_at' => $now,
          'payload' => $payload ? Json::encode($payload) : NULL,
          'created' => $now,
        ])
        ->execute();
    }
    catch (\Throwable $e) {
      $this->logger->error('Growth impression failed for @key uid @uid: @message', [
        '@key' => $insight_key,
        '@uid' => (string) $uid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  public function recordClick(string $insight_key, int $uid, ?int $event_id, string $surface): void {
    if ($uid <= 0 || !$this->isTrackingEnabled()) {
      return;
    }
    try {
      $cutoff = $this->time->getRequestTime() - ($this->getAttributionWindowDays() * 86400);
      $q = $this->database->select('myeventlane_growth_events', 'g')
        ->fields('g', ['id'])
        ->condition('uid', $uid)
        ->condition('insight_key', $insight_key)
        ->condition('surface', $surface)
        ->condition('shown_at', $cutoff, '>=')
        ->orderBy('shown_at', 'DESC')
        ->range(0, 1);
      if ($event_id !== NULL && $event_id > 0) {
        $q->condition('event_id', $event_id);
      }
      else {
        $q->isNull('event_id');
      }
      $id = $q->execute()->fetchField();
      if (!$id) {
        return;
      }
      $this->database->update('myeventlane_growth_events')
        ->fields(['clicked_at' => $this->time->getRequestTime()])
        ->condition('id', $id)
        ->execute();
    }
    catch (\Throwable $e) {
      $this->logger->error('Growth click failed for @key: @message', [
        '@key' => $insight_key,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  public function recordDismiss(string $insight_key, int $uid, ?int $event_id, string $surface): void {
    if ($uid <= 0 || !$this->isTrackingEnabled()) {
      return;
    }
    try {
      $now = $this->time->getRequestTime();
      $cutoff = $now - ($this->getAttributionWindowDays() * 86400);
      $q = $this->database->select('myeventlane_growth_events', 'g')
        ->fields('g', ['id'])
        ->condition('uid', $uid)
        ->condition('insight_key', $insight_key)
        ->condition('surface', $surface)
        ->condition('shown_at', $cutoff, '>=')
        ->orderBy('shown_at', 'DESC')
        ->range(0, 1);
      if ($event_id !== NULL && $event_id > 0) {
        $q->condition('event_id', $event_id);
      }
      else {
        $q->isNull('event_id');
      }
      $id = $q->execute()->fetchField();
      if (!$id) {
        $this->database->insert('myeventlane_growth_events')
          ->fields([
            'uid' => $uid,
            'event_id' => $event_id,
            'insight_key' => $insight_key,
            'insight_type' => $this->extractTypeFromKey($insight_key),
            'surface' => $surface,
            'shown_at' => $now,
            'dismissed_at' => $now,
            'created' => $now,
          ])
          ->execute();
        return;
      }
      $this->database->update('myeventlane_growth_events')
        ->fields(['dismissed_at' => $now])
        ->condition('id', $id)
        ->execute();
    }
    catch (\Throwable $e) {
      $this->logger->error('Growth dismiss failed for @key: @message', [
        '@key' => $insight_key,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  public function recordConversion(string $insight_key, int $uid, ?int $event_id, string $conversion_type, array $payload = []): void {
    if ($uid <= 0 || !$this->isTrackingEnabled()) {
      return;
    }
    $this->markRecentImpressionConverted($insight_key, $uid, $event_id, $conversion_type, $payload);
  }

  /**
   * Attributes a conversion to the most recent matching impression by key prefix.
   *
   * @param array<string> $insight_key_prefixes
   *   Ordered prefixes; first match wins.
   */
  public function recordConversionForPrefixes(int $uid, ?int $event_id, string $conversion_type, array $insight_key_prefixes, array $payload = []): void {
    if ($uid <= 0 || !$this->isTrackingEnabled() || $insight_key_prefixes === []) {
      return;
    }
    $cutoff = $this->time->getRequestTime() - ($this->getAttributionWindowDays() * 86400);
    try {
      foreach ($insight_key_prefixes as $prefix) {
        $q = $this->database->select('myeventlane_growth_events', 'g')
          ->fields('g', ['id', 'insight_key'])
          ->condition('uid', $uid)
          ->condition('shown_at', $cutoff, '>=')
          ->isNull('converted_at')
          ->orderBy('shown_at', 'DESC')
          ->range(0, 5);
        if ($event_id !== NULL && $event_id > 0) {
          $q->condition('event_id', $event_id);
        }
        else {
          $q->isNull('event_id');
        }
        $candidates = $q->execute()->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($candidates as $row) {
          $key = (string) ($row['insight_key'] ?? '');
          $rowId = (int) ($row['id'] ?? 0);
          if ($rowId > 0 && $key !== '' && str_starts_with($key, $prefix)) {
            $this->database->update('myeventlane_growth_events')
              ->fields([
                'converted_at' => $this->time->getRequestTime(),
                'conversion_type' => $conversion_type,
                'payload' => $payload ? Json::encode($payload) : NULL,
              ])
              ->condition('id', $rowId)
              ->execute();
            return;
          }
        }
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Growth conversion attribution failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Whether to suppress showing an insight based on recent activity.
   *
   * @param int $type_cooldown_days
   *   Days to wait before repeating the same insight after it was shown.
   * @param int $dismiss_cooldown_days
   *   When dismissible, extra suppression after a dismissal (days from dismiss).
   */
  public function shouldSuppressInsight(int $uid, string $insight_key, ?int $event_id, int $type_cooldown_days, bool $dismissible, int $dismiss_cooldown_days): bool {
    if ($uid <= 0) {
      return TRUE;
    }
    $row = $this->getLatestRow($uid, $insight_key, $event_id);
    if (!$row) {
      return FALSE;
    }
    if (!empty($row['converted_at'])) {
      return TRUE;
    }
    $now = $this->time->getRequestTime();
    if ($dismissible && !empty($row['dismissed_at'])) {
      $dismissedAt = (int) $row['dismissed_at'];
      if ($dismiss_cooldown_days > 0 && ($now - $dismissedAt) < ($dismiss_cooldown_days * 86400)) {
        return TRUE;
      }
    }
    if ($type_cooldown_days > 0) {
      $shownAt = (int) ($row['shown_at'] ?? 0);
      if ($shownAt > 0 && ($now - $shownAt) < ($type_cooldown_days * 86400)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @return array<string, mixed>|null
   */
  private function getLatestRow(int $uid, string $insight_key, ?int $event_id): ?array {
    try {
      $q = $this->database->select('myeventlane_growth_events', 'g')
        ->fields('g', ['shown_at', 'dismissed_at', 'converted_at'])
        ->condition('uid', $uid)
        ->condition('insight_key', $insight_key)
        ->orderBy('shown_at', 'DESC')
        ->range(0, 1);
      if ($event_id !== NULL && $event_id > 0) {
        $q->condition('event_id', $event_id);
      }
      else {
        $q->isNull('event_id');
      }
      $row = $q->execute()->fetchAssoc();
      return $row ?: NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Aggregates basic metrics for the admin report.
   *
   * @return array<string, array<string, int|float>>
   */
  public function getAggregates(): array {
    $out = [
      'impressions' => [],
      'clicks' => [],
      'dismissals' => [],
      'conversions' => [],
    ];
    try {
      $impressions = $this->database->query(
        'SELECT insight_key, COUNT(*) AS cnt FROM {myeventlane_growth_events} GROUP BY insight_key'
      )->fetchAllKeyed(0, 1);
      $clicks = $this->database->query(
        'SELECT insight_key, COUNT(*) AS cnt FROM {myeventlane_growth_events} WHERE clicked_at IS NOT NULL GROUP BY insight_key'
      )->fetchAllKeyed(0, 1);
      $dismissals = $this->database->query(
        'SELECT insight_key, COUNT(*) AS cnt FROM {myeventlane_growth_events} WHERE dismissed_at IS NOT NULL GROUP BY insight_key'
      )->fetchAllKeyed(0, 1);
      $conversions = $this->database->query(
        'SELECT insight_key, COUNT(*) AS cnt FROM {myeventlane_growth_events} WHERE converted_at IS NOT NULL GROUP BY insight_key'
      )->fetchAllKeyed(0, 1);
      $out['impressions'] = $impressions ?: [];
      $out['clicks'] = $clicks ?: [];
      $out['dismissals'] = $dismissals ?: [];
      $out['conversions'] = $conversions ?: [];
    }
    catch (\Throwable $e) {
      $this->logger->error('Growth aggregates query failed: @message', ['@message' => $e->getMessage()]);
    }
    return $out;
  }

  private function markRecentImpressionConverted(string $insight_key, int $uid, ?int $event_id, string $conversion_type, array $payload): void {
    $cutoff = $this->time->getRequestTime() - ($this->getAttributionWindowDays() * 86400);
    try {
      $q = $this->database->select('myeventlane_growth_events', 'g')
        ->fields('g', ['id'])
        ->condition('uid', $uid)
        ->condition('insight_key', $insight_key)
        ->condition('shown_at', $cutoff, '>=')
        ->isNull('converted_at')
        ->orderBy('shown_at', 'DESC')
        ->range(0, 1);
      if ($event_id !== NULL && $event_id > 0) {
        $q->condition('event_id', $event_id);
      }
      else {
        $q->isNull('event_id');
      }
      $id = $q->execute()->fetchField();
      if (!$id) {
        return;
      }
      $this->database->update('myeventlane_growth_events')
        ->fields([
          'converted_at' => $this->time->getRequestTime(),
          'conversion_type' => $conversion_type,
          'payload' => $payload ? Json::encode($payload) : NULL,
        ])
        ->condition('id', $id)
        ->execute();
    }
    catch (\Throwable $e) {
      $this->logger->error('Growth conversion failed: @message', ['@message' => $e->getMessage()]);
    }
  }

  private function shouldDedupeImpression(string $insight_key, int $uid, ?int $event_id, string $surface): bool {
    $hours = (int) ($this->configFactory->get('myeventlane_growth.settings')->get('tracking.impression_dedupe_hours') ?? 24);
    if ($hours <= 0) {
      return FALSE;
    }
    $since = $this->time->getRequestTime() - ($hours * 3600);
    try {
      $q = $this->database->select('myeventlane_growth_events', 'g')
        ->condition('uid', $uid)
        ->condition('insight_key', $insight_key)
        ->condition('surface', $surface)
        ->condition('shown_at', $since, '>=')
        ->range(0, 1);
      if ($event_id !== NULL && $event_id > 0) {
        $q->condition('event_id', $event_id);
      }
      else {
        $q->isNull('event_id');
      }
      return (bool) $q->countQuery()->execute()->fetchField();
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  private function getAttributionWindowDays(): int {
    return max(1, (int) ($this->configFactory->get('myeventlane_growth.settings')->get('tracking.attribution_window_days') ?? 14));
  }

  private function extractTypeFromKey(string $insight_key): string {
    if (str_starts_with($insight_key, 'pro_upgrade')) {
      return 'upgrade';
    }
    if (str_starts_with($insight_key, 'boost_')) {
      return 'boost';
    }
    if ($insight_key === 'pro_billing_recovered') {
      return 'celebration';
    }
    if (str_starts_with($insight_key, 'pro_grace') || str_starts_with($insight_key, 'pro_billing') || str_starts_with($insight_key, 'pro_cancel')) {
      return 'recovery';
    }
    if (str_starts_with($insight_key, 'retention_')) {
      return 'retention';
    }
    if (str_starts_with($insight_key, 'engagement_')) {
      return 'engagement';
    }
    return 'engagement';
  }

}
