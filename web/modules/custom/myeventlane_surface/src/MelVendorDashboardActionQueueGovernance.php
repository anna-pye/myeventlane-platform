<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Logger\LoggerChannelInterface;
/**
 * Aligns vendor dashboard action queue ordering and suppression with governance systems.
 *
 * Presentation-only: does not reinterpret facts from the dashboard model; adjusts priority,
 * filters policy-suppressed nudges, and avoids duplicate CTAs when workflow primary is active.
 */
final class MelVendorDashboardActionQueueGovernance {

  /**
   * Fraction of workflow CTA priority subtracted from numeric queue priority when a matching
   * vendor workflow is incomplete (lower queue priority = surfaced earlier).
   */
  private const WORKFLOW_CTA_PRIORITY_WEIGHT = 0.12;

  public function __construct(
    private readonly MelWorkflowResolver $workflowResolver,
    private readonly MelWorkflowRegistry $workflowRegistry,
    private readonly MelOperationalPolicyManager $operationalPolicyManager,
    private readonly MelWorkflowManager $workflowManager,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * @param list<array<string, mixed>> $actions
   *
   * @return list<array<string, mixed>>
   */
  public function apply(array $actions): array {
    if ($actions === []) {
      return [];
    }

    try {
      $policy = $this->operationalPolicyManager->buildPageInterpretation();
      $suppression = $policy['suppression'] ?? [];
      if ($this->shouldSuppressAnalyticsNudge($suppression)) {
        $actions = array_values(array_filter(
          $actions,
          static fn (array $a): bool => (string) ($a['key'] ?? '') !== 'analytics_unavailable',
        ));
      }

      $primaryGoverned = FALSE;
      try {
        $primaryGoverned = $this->workflowManager->willRenderPrimaryWorkflowRegion();
      }
      catch (\Throwable $e) {
        $this->logger->warning('Vendor action queue workflow primary probe failed: @message', [
          '@message' => $e->getMessage(),
        ]);
      }

      $incompleteVendorCtas = $this->incompleteVendorWorkflowCtaWeights();
      foreach ($actions as &$action) {
        $key = (string) ($action['key'] ?? '');
        if ($primaryGoverned && ($key === 'profile_incomplete' || $key === 'stripe_payout_incomplete')) {
          $action['governance_duplicate_workflow_primary'] = TRUE;
          $action['priority'] = (int) ($action['priority'] ?? 500) + 140;
        }
        $workflowId = $this->workflowIdForActionKey($key);
        if ($workflowId !== ''
          && isset($incompleteVendorCtas[$workflowId])) {
          $weight = $incompleteVendorCtas[$workflowId];
          $delta = (int) round($weight * self::WORKFLOW_CTA_PRIORITY_WEIGHT * 10);
          $action['priority'] = max(1, (int) ($action['priority'] ?? 500) - $delta);
          $action['governance_workflow_id'] = $workflowId;
        }
      }
      unset($action);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Vendor action queue governance pass failed; returning unordered actions: @message', [
        '@message' => $e->getMessage(),
      ]);
      return $actions;
    }

    usort($actions, static function (array $a, array $b): int {
      $pa = (int) ($a['priority'] ?? 1000);
      $pb = (int) ($b['priority'] ?? 1000);
      if ($pa !== $pb) {
        return $pa <=> $pb;
      }
      $severityRank = static function (array $x): int {
        $map = ['error' => 0, 'warning' => 1, 'info' => 2, 'success' => 3];
        return $map[(string) ($x['severity'] ?? '')] ?? 99;
      };
      return $severityRank($a) <=> $severityRank($b);
    });

    return $actions;
  }

  /**
   * @param array<string, bool> $suppression
   */
  private function shouldSuppressAnalyticsNudge(array $suppression): bool {
    return !empty($suppression['suppress_marketing_guidance'])
      || !empty($suppression['suppress_nonessential_notifications'])
      || !empty($suppression['suppress_cross_sell_guidance']);
  }

  /**
   * @return array<string, int> workflow id => cta priority for incomplete vendor workflows only
   */
  private function incompleteVendorWorkflowCtaWeights(): array {
    $weights = [];
    try {
      $context = $this->workflowResolver->buildContext();
      $active = $this->workflowResolver->resolveActiveWorkflows($this->workflowRegistry, $context);
      foreach ($active as $definition) {
        if ($definition->domain !== 'vendor') {
          continue;
        }
        if ($this->workflowResolver->isCompletionMoment($definition, $context)) {
          continue;
        }
        $weights[$definition->id] = $definition->ctaPriority;
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Vendor workflow CTA weights failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
    return $weights;
  }

  private function workflowIdForActionKey(string $actionKey): string {
    static $literal = [
      'no_vendor_profile' => 'vendor_account_created',
      'profile_incomplete' => 'vendor_profile_completed',
      'stripe_payout_incomplete' => 'stripe_connected',
      'no_events_yet' => 'first_event_created',
      'analytics_unavailable' => '',
    ];

    if (isset($literal[$actionKey])) {
      return $literal[$actionKey];
    }

    if (str_starts_with($actionKey, 'draft_event_')) {
      return 'first_event_published';
    }
    if (str_starts_with($actionKey, 'missing_booking_')) {
      return 'first_ticket_sold';
    }

    return '';
  }

}
