<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Privacy-safe, deterministic payload shaping for QA / snapshots (no runtime governance).
 *
 * Used by tests and staff tooling; does not interpret policy or alter orchestration.
 */
final class MelGovernancePayloadInspector {

  /**
   * Normalises operational policy interpretation for stable regression snapshots.
   *
   * Strips cache metadata, translated prose, and registry snapshots.
   *
   * @param array<string, mixed> $interpretation
   *
   * @return array<string, mixed>
   */
  public function normalizeOperationalPolicyInterpretation(array $interpretation): array {
    $out = [];
    $policy_profile = (string) ($interpretation['surface_policy_profile'] ?? '');
    if ($policy_profile !== '') {
      $out['surface_policy_profile'] = $policy_profile;
    }
    $active = $interpretation['active_policy_ids'] ?? [];
    if (is_array($active)) {
      $active = array_values(array_unique(array_map('strval', $active)));
      sort($active, SORT_STRING);
      $out['active_policy_ids'] = $active;
    }
    $suppression = $interpretation['suppression'] ?? [];
    if (is_array($suppression)) {
      $out['suppression'] = $this->normalizeBooleanMap($suppression);
    }
    $intel = $interpretation['intelligence_governance'] ?? [];
    if (is_array($intel)) {
      $ids = $intel['suppress_definition_ids'] ?? [];
      if (is_array($ids)) {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        sort($ids, SORT_STRING);
        $out['intelligence_governance'] = [
          'suppress_definition_ids' => $ids,
          'orchestration_cap_reduction' => (int) ($intel['orchestration_cap_reduction'] ?? 0),
        ];
      }
    }
    $exp = $interpretation['experience_governance'] ?? [];
    if (is_array($exp)) {
      $out['experience_governance'] = [
        'elevate_competing_cta_suppression' => !empty($exp['elevate_competing_cta_suppression']),
      ];
    }
    $privacy = $interpretation['privacy'] ?? [];
    if (is_array($privacy)) {
      $out['privacy'] = [
        'vendor_isolation' => !empty($privacy['vendor_isolation']),
        'no_moderation_internals' => !empty($privacy['no_moderation_internals']),
        'no_cross_account_signals' => !empty($privacy['no_cross_account_signals']),
        'staff_registry_visible' => !empty($privacy['staff_registry_visible']),
      ];
    }
    return $out;
  }

  /**
   * Normalises observability payload shape without traces (categories only).
   *
   * @param array<string, mixed> $payload
   *
   * @return array<string, mixed>
   */
  public function normalizeObservabilityShape(array $payload): array {
    return [
      'visibility_tier_value' => (string) ($payload['visibility_tier_value'] ?? ''),
      'surface' => (string) ($payload['surface'] ?? ''),
      'registry_contract_ids' => $this->sortedStringList($payload['registry_contract_ids'] ?? []),
      'privacy_diagnostics_permission_required' => !empty($payload['privacy']['diagnostics_permission_required']),
    ];
  }

  /**
   * Builds minimal staff debug settings (boolean/id only, no prose, no scores).
   *
   * @param array<string, mixed> $policy
   * @param array<string, mixed> $intelligence
   * @param array<string, mixed> $experience
   *
   * @return array<string, mixed>
   */
  public function buildStaffDebugSummary(
    string $route_name,
    MelSurfaceId $surface,
    array $policy,
    array $intelligence,
    array $experience,
  ): array {
    $suppression = $policy['suppression'] ?? [];
    $suppression_active = is_array($suppression) ? $this->activeSuppressionKeys($suppression) : [];
    $intel_governance = $policy['intelligence_governance'] ?? [];
    $intel_ids = [];
    if (is_array($intel_governance)) {
      $raw_intel_ids = $intel_governance['suppress_definition_ids'] ?? [];
      $intel_ids = is_array($raw_intel_ids) ? $this->sortedStringList($raw_intel_ids) : [];
    }
    $experience_governance = $policy['experience_governance'] ?? [];
    $items = $intelligence['items'] ?? [];
    $item_ids = [];
    if (is_array($items)) {
      foreach ($items as $item) {
        if (is_array($item) && isset($item['id'])) {
          $item_ids[] = (string) $item['id'];
        }
      }
      $item_ids = $this->sortedStringList($item_ids);
    }
    return [
      'enabled' => TRUE,
      'route' => $route_name,
      'surface' => $surface->value,
      'policyProfile' => (string) ($policy['surface_policy_profile'] ?? ''),
      'activePolicyIds' => $this->sortedStringList($policy['active_policy_ids'] ?? []),
      'suppressionActive' => $suppression_active,
      'intelligenceSuppressIds' => $intel_ids,
      'intelligenceItemIds' => $item_ids,
      'experiencePrimaryId' => is_string($experience['primary_experience_id'] ?? NULL)
        ? $experience['primary_experience_id']
        : '',
      'experienceElevateCompetingSuppression' => is_array($experience_governance)
        && !empty($experience_governance['elevate_competing_cta_suppression']),
    ];
  }

  /**
   * @param array<string, mixed> $map
   *
   * @return array<string, bool>
   */
  private function normalizeBooleanMap(array $map): array {
    $out = [];
    foreach ($map as $key => $value) {
      if (!is_string($key)) {
        continue;
      }
      $out[$key] = (bool) $value;
    }
    ksort($out, SORT_STRING);
    return $out;
  }

  /**
   * @param array<string, mixed> $map
   *
   * @return list<string>
   */
  private function activeSuppressionKeys(array $map): array {
    $active = [];
    foreach ($map as $key => $value) {
      if (!is_string($key)) {
        continue;
      }
      if (!empty($value)) {
        $active[] = $key;
      }
    }
    sort($active, SORT_STRING);
    return $active;
  }

  /**
   * @param mixed $list
   *
   * @return list<string>
   */
  private function sortedStringList(mixed $list): array {
    if (!is_array($list)) {
      return [];
    }
    $out = array_values(array_unique(array_map('strval', $list)));
    sort($out, SORT_STRING);
    return $out;
  }

}
