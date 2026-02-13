<?php

declare(strict_types=1);

namespace Drupal\myeventlane_public_trust\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Converts internal trust metrics into marketing-safe display strings.
 *
 * Wording rules:
 * - No numbers, percentages, or exact values.
 * - Use hedging language: "most", "typically", "generally".
 * - Never make promises, guarantees, or comparisons.
 * - Australian English, neutral tone.
 */
final class TrustSignalFormatter {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Formats computed metrics into render-ready signal arrays.
   *
   * @param array $metrics
   *   Metrics from TrustSignalCalculator::getCachedMetrics().
   *
   * @return array
   *   Indexed array of signal arrays. Each signal contains:
   *   - 'type': Signal type key (resolution, response, fairness, scale).
   *   - 'label': Short heading for the signal.
   *   - 'copy': Marketing-safe description string.
   *   Only includes signals that are enabled in config and have data.
   */
  public function format(array $metrics): array {
    $config = $this->configFactory->get('myeventlane_public_trust.settings');
    $enabled = $config->get('enable_signals') ?? [];
    $signals = [];

    // Resolution confidence.
    if (!empty($enabled['resolution_confidence'])) {
      $signal = $this->formatResolution($metrics['resolution'] ?? []);
      if ($signal !== NULL) {
        $signals[] = $signal;
      }
    }

    // Response confidence.
    if (!empty($enabled['response_confidence'])) {
      $signal = $this->formatResponse($metrics['response'] ?? []);
      if ($signal !== NULL) {
        $signals[] = $signal;
      }
    }

    // Fairness statement.
    if (!empty($enabled['fairness_statement'])) {
      $signal = $this->formatFairness($metrics['fairness'] ?? []);
      if ($signal !== NULL) {
        $signals[] = $signal;
      }
    }

    // Scale statement.
    if (!empty($enabled['scale_statement'])) {
      $signal = $this->formatScale($metrics['scale'] ?? []);
      if ($signal !== NULL) {
        $signals[] = $signal;
      }
    }

    return $signals;
  }

  /**
   * Formats the resolution confidence signal.
   *
   * @return array|null
   *   Signal array or NULL if data is insufficient.
   */
  private function formatResolution(array $data): ?array {
    if (empty($data['available'])) {
      return NULL;
    }

    $ratio = (float) ($data['ratio'] ?? 0);

    if ($ratio >= 0.7) {
      $copy = 'Most enquiries raised on the platform are resolved.';
    }
    elseif ($ratio >= 0.5) {
      $copy = 'Enquiries raised on the platform are generally resolved.';
    }
    else {
      // Ratio too low to make a meaningful positive statement.
      return NULL;
    }

    return [
      'type' => 'resolution',
      'label' => 'Issue resolution',
      'copy' => $copy,
    ];
  }

  /**
   * Formats the response confidence signal.
   *
   * Uses internal median response time to determine wording. The actual
   * value is never exposed.
   *
   * @return array|null
   *   Signal array or NULL if data is insufficient.
   */
  private function formatResponse(array $data): ?array {
    if (empty($data['available'])) {
      return NULL;
    }

    $medianHours = $data['median_hours'] ?? NULL;
    if ($medianHours === NULL) {
      return NULL;
    }

    if ($medianHours <= 24.0) {
      $copy = 'Issues are typically responded to promptly.';
    }
    elseif ($medianHours <= 72.0) {
      $copy = 'Issues raised on the platform are generally responded to.';
    }
    else {
      // Response time too slow to make a meaningful positive statement.
      return NULL;
    }

    return [
      'type' => 'response',
      'label' => 'Response times',
      'copy' => $copy,
    ];
  }

  /**
   * Formats the fairness statement signal.
   *
   * @return array|null
   *   Signal array or NULL if no refund process data exists.
   */
  private function formatFairness(array $data): ?array {
    if (empty($data['available'])) {
      return NULL;
    }

    return [
      'type' => 'fairness',
      'label' => 'Refund process',
      'copy' => 'Refund requests are reviewed individually.',
    ];
  }

  /**
   * Formats the scale statement signal.
   *
   * @return array|null
   *   Signal array or NULL if platform scale is below threshold.
   */
  private function formatScale(array $data): ?array {
    if (empty($data['available'])) {
      return NULL;
    }

    // Use the larger bucket between events and orders.
    $eventBucket = $data['event_bucket'] ?? NULL;
    $orderBucket = $data['order_bucket'] ?? NULL;

    // Prefer the event bucket; fall back to order bucket.
    $bucket = $eventBucket ?? $orderBucket;
    if ($bucket === NULL) {
      return NULL;
    }

    $copy = match ($bucket) {
      'thousands' => 'The platform supports thousands of events.',
      'hundreds' => 'The platform supports hundreds of events.',
      default => NULL,
    };

    if ($copy === NULL) {
      return NULL;
    }

    return [
      'type' => 'scale',
      'label' => 'Platform scale',
      'copy' => $copy,
    ];
  }

}
