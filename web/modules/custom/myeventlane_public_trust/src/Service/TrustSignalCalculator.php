<?php

declare(strict_types=1);

namespace Drupal\myeventlane_public_trust\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_escalations_analytics\Service\EscalationAnalyticsQuery;
use Psr\Log\LoggerInterface;

/**
 * Computes aggregate trust signal metrics from platform data.
 *
 * All metrics are internal and aggregate. No individual vendor, staff, or
 * user-level data is included. Computation runs during cron; page renders
 * read from cache only — no live queries during page render.
 */
final class TrustSignalCalculator {

  /**
   * Cache key for stored metrics.
   */
  private const CACHE_ID = 'myeventlane_public_trust:metrics';

  /**
   * Minimum escalation count before the resolution signal is considered valid.
   */
  private const MIN_ESCALATIONS_FOR_RESOLUTION = 10;

  /**
   * Minimum response samples before the response signal is considered valid.
   */
  private const MIN_RESPONSES_FOR_CONFIDENCE = 5;

  public function __construct(
    private readonly EscalationAnalyticsQuery $analyticsQuery,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly CacheBackendInterface $cache,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns cached metrics, or NULL if the cache is empty.
   *
   * This is the ONLY method that should be called during page render.
   *
   * @return array|null
   *   Structured metrics array, or NULL if not yet computed.
   */
  public function getCachedMetrics(): ?array {
    $cached = $this->cache->get(self::CACHE_ID);
    return $cached ? $cached->data : NULL;
  }

  /**
   * Computes all trust signal metrics and stores them in cache.
   *
   * Intended to be called from cron only. Performs entity queries and
   * database lookups that should NOT run during page render.
   *
   * @return array
   *   Structured metrics array, or empty array on failure.
   */
  public function computeAndCache(): array {
    $config = $this->configFactory->get('myeventlane_public_trust.settings');
    $lookbackDays = (int) ($config->get('lookback_days') ?: 90);
    $cacheDays = (int) ($config->get('cache_days') ?: 30);

    try {
      $escalationMetrics = $this->computeEscalationMetrics($lookbackDays);
      $scaleMetrics = $this->computeScaleMetrics();
      $fairnessMetrics = $this->computeFairnessMetrics();
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to compute trust signal metrics: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }

    $metrics = [
      'resolution' => $escalationMetrics['resolution'],
      'response' => $escalationMetrics['response'],
      'scale' => $scaleMetrics,
      'fairness' => $fairnessMetrics,
      'computed_at' => $this->time->getRequestTime(),
    ];

    $expiry = $this->time->getRequestTime() + ($cacheDays * 86400);
    $this->cache->set(self::CACHE_ID, $metrics, $expiry);

    $this->logger->info('Trust signal metrics computed and cached.');

    return $metrics;
  }

  /**
   * Computes resolution ratio and median response time from escalation data.
   *
   * @param int $lookbackDays
   *   Number of days to look back from the current time.
   *
   * @return array
   *   Keyed array with 'resolution' and 'response' sub-arrays.
   */
  private function computeEscalationMetrics(int $lookbackDays): array {
    $from = $this->time->getRequestTime() - ($lookbackDays * 86400);
    $escalations = $this->analyticsQuery->query(from: $from);

    $total = count($escalations);

    if ($total === 0) {
      return [
        'resolution' => ['available' => FALSE],
        'response' => ['available' => FALSE],
      ];
    }

    // Count resolved escalations and collect created timestamps for response
    // time calculation.
    $resolvedCount = 0;
    $escalationCreated = [];

    foreach ($escalations as $escalation) {
      $status = (string) $escalation->get('status')->value;
      if (in_array($status, ['resolved', 'closed'], TRUE)) {
        $resolvedCount++;
      }
      $escalationCreated[(int) $escalation->id()] = (int) $escalation->get('created')->value;
    }

    // Resolution confidence: ratio of resolved to total.
    $resolution = [
      'available' => $total >= self::MIN_ESCALATIONS_FOR_RESOLUTION,
      'ratio' => $total > 0 ? $resolvedCount / $total : 0.0,
    ];

    // Response confidence: median first-response time (hours).
    $responseHours = $this->computeFirstResponseTimes($escalationCreated);
    $medianHours = $this->computeMedian($responseHours);

    $response = [
      'available' => $medianHours !== NULL && count($responseHours) >= self::MIN_RESPONSES_FOR_CONFIDENCE,
      'median_hours' => $medianHours,
    ];

    return [
      'resolution' => $resolution,
      'response' => $response,
    ];
  }

  /**
   * Computes platform scale metrics from event and order counts.
   *
   * Counts are bucketed to marketing-safe categories: "hundreds" or
   * "thousands". Counts below 100 result in the signal being unavailable.
   *
   * @return array
   *   Scale metrics with 'available', 'event_bucket', and 'order_bucket'.
   */
  private function computeScaleMetrics(): array {
    // Count published event nodes.
    $eventCount = (int) $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->count()
      ->execute();

    // Count completed commerce orders.
    $orderCount = (int) $this->entityTypeManager->getStorage('commerce_order')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', 'completed')
      ->count()
      ->execute();

    return [
      'available' => $eventCount >= 100 || $orderCount >= 100,
      'event_bucket' => $this->bucketCount($eventCount),
      'order_bucket' => $this->bucketCount($orderCount),
    ];
  }

  /**
   * Checks whether the platform has a functioning refund process.
   *
   * The signal is available if at least one completed refund exists.
   *
   * @return array
   *   Fairness metrics with 'available' key.
   */
  private function computeFairnessMetrics(): array {
    $refundCount = (int) $this->database->select('myeventlane_refund_log', 'r')
      ->condition('status', 'completed')
      ->countQuery()
      ->execute()
      ->fetchField();

    return [
      'available' => $refundCount > 0,
    ];
  }

  /**
   * Buckets a count into a marketing-safe category.
   *
   * @param int $count
   *   Raw count value.
   *
   * @return string|null
   *   'hundreds', 'thousands', or NULL if below threshold.
   */
  private function bucketCount(int $count): ?string {
    if ($count >= 1000) {
      return 'thousands';
    }
    if ($count >= 100) {
      return 'hundreds';
    }
    return NULL;
  }

  /**
   * Computes first response times from comment threads on escalations.
   *
   * First response = time from escalation creation to the first comment
   * on field_escalation_thread. Escalations with no comments are excluded.
   *
   * @param array<int, int> $escalationCreated
   *   Map of escalation_id => created UNIX timestamp.
   *
   * @return float[]
   *   Array of response times in hours.
   */
  private function computeFirstResponseTimes(array $escalationCreated): array {
    if (empty($escalationCreated)) {
      return [];
    }

    try {
      $commentStorage = $this->entityTypeManager->getStorage('comment');
    }
    catch (\Throwable) {
      return [];
    }

    $responseHours = [];

    // Process in chunks of 50 IDs to limit memory use.
    foreach (array_chunk(array_keys($escalationCreated), 50, TRUE) as $chunkIds) {
      $cids = $commentStorage->getQuery()
        ->condition('entity_type', 'escalation')
        ->condition('entity_id', $chunkIds, 'IN')
        ->condition('field_name', 'field_escalation_thread')
        ->sort('created', 'ASC')
        ->accessCheck(FALSE)
        ->execute();

      if (empty($cids)) {
        continue;
      }

      $comments = $commentStorage->loadMultiple($cids);

      // Find earliest comment per escalation.
      $firstByEscalation = [];
      foreach ($comments as $comment) {
        $eid = (int) $comment->get('entity_id')->target_id;
        $commentCreated = (int) $comment->getCreatedTime();

        if (!isset($firstByEscalation[$eid]) || $commentCreated < $firstByEscalation[$eid]) {
          $firstByEscalation[$eid] = $commentCreated;
        }
      }

      foreach ($firstByEscalation as $eid => $firstCommentTime) {
        $created = $escalationCreated[$eid] ?? 0;
        if ($created > 0 && $firstCommentTime > $created) {
          $responseHours[] = ($firstCommentTime - $created) / 3600;
        }
      }
    }

    return $responseHours;
  }

  /**
   * Computes the median of an array of numeric values.
   *
   * @param float[] $values
   *   Array of numeric values.
   *
   * @return float|null
   *   Median value, or NULL if array is empty.
   */
  private function computeMedian(array $values): ?float {
    if (empty($values)) {
      return NULL;
    }

    sort($values);
    $count = count($values);
    $middle = (int) floor($count / 2);

    if ($count % 2 === 0) {
      return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    return (float) $values[$middle];
  }

}
