<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\node\NodeInterface;

/**
 * Tier-level sales metrics from completed Commerce order items.
 */
final class TicketTierAnalyticsService {

  public function __construct(
    private readonly TicketVariationSoldService $variationSold,
    private readonly TimeInterface $time,
  ) {}

  /**
   * @return array{
   *   sold: int,
   *   capacity: int|null,
   *   remaining: int|null,
   *   revenue: array{number: string, currency_code: string}|null,
   *   sell_through_percent: float|null,
   *   conversion_note: string|null
   * }
   */
  public function buildTierMetrics(TicketTypeInterface $tier): array {
    $capacity = $tier->get('capacity')->isEmpty() ? NULL : (int) $tier->get('capacity')->value;
    $sold = 0;
    $revenue = NULL;
    if (!$tier->get('commerce_variation')->isEmpty() && !$tier->get('event')->isEmpty()) {
      $vid = (int) $tier->get('commerce_variation')->target_id;
      $eid = (int) $tier->get('event')->target_id;
      if ($vid > 0 && $eid > 0) {
        $sold = $this->variationSold->countCompletedSoldForVariation($eid, $vid);
        $revenue = $this->variationSold->sumCompletedRevenueForVariation($eid, $vid);
      }
    }

    $remaining = ($capacity !== NULL && $capacity > 0) ? max(0, $capacity - $sold) : NULL;
    $sellThrough = ($capacity !== NULL && $capacity > 0)
      ? round(100.0 * ($sold / $capacity), 1)
      : NULL;

    return [
      'sold' => $sold,
      'capacity' => $capacity,
      'remaining' => $remaining,
      'revenue' => $revenue,
      'sell_through_percent' => $sellThrough,
      'conversion_note' => 'View-to-cart conversion is not stored in a queryable way in this codebase; only completed-order sell-through is reported here.',
    ];
  }

  /**
   * Event-level rollups composed only from per-tier metrics (no extra order queries).
   *
   * @param iterable<TicketTypeInterface> $tickets
   *
   * @return array{
   *   total_sold: int,
   *   total_remaining: int|null,
   *   gross_revenue: array{number: string, currency_code: string}|null,
   *   active_tier_count: int,
   *   sold_out_tier_count: int,
   *   restricted_tier_count: int,
   *   conversion_note: string|null
   * }
   */
  public function buildEventTierRollup(NodeInterface $event, iterable $tickets): array {
    $totalSold = 0;
    $totalRemaining = 0;
    $sumRemaining = FALSE;
    $hasUnlimitedTier = FALSE;
    $activeTierCount = 0;
    $soldOutTierCount = 0;
    $restrictedTierCount = 0;
    $grossNumber = '0';
    $grossCurrency = '';
    $revenueCurrencyMismatch = FALSE;

    foreach ($tickets as $tier) {
      if (!$tier instanceof TicketTypeInterface) {
        continue;
      }
      if (!$tier->isPublished()) {
        continue;
      }

      $activeTierCount++;

      $mode = $this->normaliseVisibilityMode($tier);
      if (in_array($mode, ['hidden', 'access_code', 'group_only'], TRUE)) {
        $restrictedTierCount++;
      }

      $status = TicketStatusEvaluator::evaluate($tier, $this->variationSold, $this->time, $event);
      if ($status === TicketStatusEvaluator::STATUS_SOLD_OUT) {
        $soldOutTierCount++;
      }

      $metrics = $this->buildTierMetrics($tier);
      $totalSold += (int) $metrics['sold'];
      if ($metrics['capacity'] === NULL || (int) $metrics['capacity'] < 1) {
        $hasUnlimitedTier = TRUE;
      }
      elseif ($metrics['remaining'] !== NULL) {
        $sumRemaining = TRUE;
        $totalRemaining += (int) $metrics['remaining'];
      }

      $rev = $metrics['revenue'];
      if (is_array($rev) && isset($rev['number'], $rev['currency_code'])) {
        $code = strtoupper((string) $rev['currency_code']);
        if ($grossCurrency === '') {
          $grossCurrency = $code;
        }
        elseif ($grossCurrency !== $code) {
          $revenueCurrencyMismatch = TRUE;
        }
        if (!$revenueCurrencyMismatch) {
          if (function_exists('bcadd')) {
            $grossNumber = bcadd($grossNumber, (string) $rev['number'], 6);
          }
          else {
            $grossNumber = (string) ((float) $grossNumber + (float) (string) $rev['number']);
          }
        }
      }
    }

    $grossRevenue = NULL;
    if (!$revenueCurrencyMismatch && $grossCurrency !== '') {
      $grossRevenue = [
        'number' => $this->trimRevenueNumber($grossNumber),
        'currency_code' => $grossCurrency,
      ];
    }

    return [
      'total_sold' => $totalSold,
      'total_remaining' => !$hasUnlimitedTier && $sumRemaining ? $totalRemaining : NULL,
      'gross_revenue' => $grossRevenue,
      'active_tier_count' => $activeTierCount,
      'sold_out_tier_count' => $soldOutTierCount,
      'restricted_tier_count' => $restrictedTierCount,
      'conversion_note' => $revenueCurrencyMismatch
        ? 'Gross revenue total is omitted because paid tiers use mixed currencies on this event.'
        : 'Totals are from completed orders only (same basis as per-tier cards).',
    ];
  }

  private function normaliseVisibilityMode(TicketTypeInterface $ticket): string {
    if (!$ticket->hasField('visibility_mode') || $ticket->get('visibility_mode')->isEmpty()) {
      return 'public';
    }
    $mode = (string) $ticket->get('visibility_mode')->value;
    return in_array($mode, ['public', 'hidden', 'access_code', 'group_only'], TRUE) ? $mode : 'public';
  }

  private function trimRevenueNumber(string $number): string {
    if (!is_numeric($number)) {
      return $number;
    }
    if (function_exists('bccomp') && bccomp($number, '0', 6) === 0) {
      return '0';
    }
    return $number;
  }

}
