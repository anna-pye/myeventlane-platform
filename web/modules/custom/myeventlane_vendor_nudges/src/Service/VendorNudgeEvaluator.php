<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_nudges\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\myeventlane_escalations_analytics\Service\EscalationAnalyticsQuery;
use Drupal\myeventlane_escalations_analytics\Service\EscalationMetricsCalculator;
use Drupal\myeventlane_escalations_analytics\Service\VendorHealthEvaluator;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor_nudges\Nudge\VendorNudgeInterface;
use Psr\Log\LoggerInterface;

/**
 * Evaluates which nudges apply to a given vendor.
 *
 * Checks opt-in status, builds aggregate context from analytics services,
 * and returns the applicable nudges (capped at a configurable maximum).
 *
 * HARD RULES:
 * - NEVER returns nudges if vendor has not opted in.
 * - NEVER exposes metrics, counts, or scores to nudge output.
 * - Context is aggregate only — no individual escalation data is leaked.
 * - NEVER compares vendors or triggers automation.
 */
final class VendorNudgeEvaluator {

  /**
   * Default lookback window in days for escalation analytics.
   */
  private const LOOKBACK_DAYS = 90;

  /**
   * Constructs a VendorNudgeEvaluator.
   */
  public function __construct(
    private readonly VendorNudgeRegistry $registry,
    private readonly EscalationAnalyticsQuery $analyticsQuery,
    private readonly EscalationMetricsCalculator $metricsCalculator,
    private readonly VendorHealthEvaluator $healthEvaluator,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Evaluates and returns applicable nudges for the given vendor.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The vendor entity to evaluate.
   *
   * @return \Drupal\myeventlane_vendor_nudges\Nudge\VendorNudgeInterface[]
   *   An array of applicable nudge instances, or empty if vendor has not
   *   opted in or no nudges apply.
   */
  public function evaluate(Vendor $vendor): array {
    // HARD RULE: Never show nudges unless vendor opted in.
    if (!$this->isOptedIn($vendor)) {
      return [];
    }

    try {
      $context = $this->buildContext($vendor);
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to build nudge context for vendor @id: @error', [
        '@id' => $vendor->id(),
        '@error' => $e->getMessage(),
      ]);
      return [];
    }

    $maxNudges = (int) ($this->configFactory
      ->get('myeventlane_vendor_nudges.settings')
      ->get('max_nudges_displayed') ?? 2);

    $applicable = [];
    foreach ($this->registry->getNudges() as $nudge) {
      if (count($applicable) >= $maxNudges) {
        break;
      }

      try {
        if ($nudge->shouldApply($vendor, $context)) {
          $applicable[] = $nudge;
        }
      }
      catch (\Throwable $e) {
        $this->logger->warning('Nudge @nudge evaluation failed for vendor @vendor: @error', [
          '@nudge' => $nudge->id(),
          '@vendor' => $vendor->id(),
          '@error' => $e->getMessage(),
        ]);
      }
    }

    return $applicable;
  }

  /**
   * Checks whether the vendor has opted in to nudges.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The vendor entity.
   *
   * @return bool
   *   TRUE if the vendor has explicitly opted in.
   */
  private function isOptedIn(Vendor $vendor): bool {
    if (!$vendor->hasField('nudges_enabled')) {
      return FALSE;
    }

    return (bool) $vendor->get('nudges_enabled')->value;
  }

  /**
   * Builds an aggregate context array for nudge evaluation.
   *
   * Each analytics service call is individually wrapped so that a
   * signature mismatch or runtime error in any single service fails
   * closed (safe defaults) without breaking the vendor dashboard.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The vendor entity.
   *
   * @return array
   *   The context array with keys:
   *   - escalation_count: (int) Number of escalations in the window.
   *   - health_bucket: (string) 'healthy', 'attention', or 'risk'.
   *   - avg_first_response_hours: (float|null) Average first response time.
   *   - has_refund_correlations: (bool) Whether any escalations are
   *     order-linked.
   */
  private function buildContext(Vendor $vendor): array {
    $vendorId = (int) $vendor->id();
    $from = $this->time->getRequestTime() - (self::LOOKBACK_DAYS * 86400);

    $context = [
      'escalation_count' => 0,
      'health_bucket' => 'healthy',
      'avg_first_response_hours' => NULL,
      'has_refund_correlations' => FALSE,
    ];

    // --- Query escalations (fail closed: no escalations → no nudges). ---
    try {
      $escalations = $this->analyticsQuery->query($vendorId, $from);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Nudge context: EscalationAnalyticsQuery::query() failed for vendor @id — returning safe defaults. Error: @error', [
        '@id' => $vendorId,
        '@error' => $e->getMessage(),
      ]);
      return $context;
    }

    if (!is_countable($escalations)) {
      $this->logger->warning('Nudge context: EscalationAnalyticsQuery::query() returned non-countable for vendor @id — returning safe defaults.', [
        '@id' => $vendorId,
      ]);
      return $context;
    }

    $context['escalation_count'] = count($escalations);

    if ($context['escalation_count'] === 0) {
      return $context;
    }

    // --- Calculate aggregate metrics (fail closed: defaults stand). ---
    $metrics = [];
    try {
      $metrics = $this->metricsCalculator->calculate($escalations);
      if (!is_array($metrics)) {
        $metrics = [];
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Nudge context: EscalationMetricsCalculator::calculate() failed for vendor @id — metrics unavailable. Error: @error', [
        '@id' => $vendorId,
        '@error' => $e->getMessage(),
      ]);
    }

    $context['avg_first_response_hours'] = $metrics['avg_first_response_hours'] ?? NULL;

    // --- Evaluate health bucket (fail closed: stays 'healthy'). ---
    if (!empty($metrics)) {
      try {
        $health = $this->healthEvaluator->evaluate($metrics);
        if (is_array($health) && isset($health['bucket'])) {
          $context['health_bucket'] = $health['bucket'];
        }
      }
      catch (\Throwable $e) {
        $this->logger->warning('Nudge context: VendorHealthEvaluator::evaluate() failed for vendor @id — health bucket defaults to healthy. Error: @error', [
          '@id' => $vendorId,
          '@error' => $e->getMessage(),
        ]);
      }
    }

    // --- Check for refund correlations (order-linked escalations). ---
    try {
      foreach ($escalations as $escalation) {
        if (is_object($escalation)
            && method_exists($escalation, 'hasField')
            && $escalation->hasField('order_id')
            && !$escalation->get('order_id')->isEmpty()) {
          $context['has_refund_correlations'] = TRUE;
          break;
        }
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Nudge context: refund correlation check failed for vendor @id — defaulting to FALSE. Error: @error', [
        '@id' => $vendorId,
        '@error' => $e->getMessage(),
      ]);
    }

    return $context;
  }

}
