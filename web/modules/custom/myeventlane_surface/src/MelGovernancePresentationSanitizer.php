<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Tier-aware intelligence payload projection for HTML, Twig, and Event Studio JSON.
 *
 * Strips explainability internals, workflow/signal identifiers, and operational
 * suppression envelopes before any theme or browser attachment. Staff
 * diagnostics continue to use the raw {@see MelIntelligenceManager} payload
 * inside MELObservabilitySystem payloads and snapshot tooling unaltered.
 */
final class MelGovernancePresentationSanitizer {

  /**
   * @var list<string>
   */
  private const PUBLIC_ITEM_KEYS = [
    'id',
    'category',
    'presentation_lane',
    'headline',
    'recommended_action',
    'dismissible',
  ];

  /**
   * Removes presentation-leaky keys from the portable intelligence payload.
   *
   * @param array<string, mixed> $payload
   *
   * @return array<string, mixed>
   */
  public function sanitizeIntelligencePayloadForPresentation(array $payload, MelSurfaceId $surface): array {
    $cache = $payload['#cache'] ?? [];
    $out = $payload;
    unset($out['#cache']);

    $items = $payload['items'] ?? [];
    if (!is_array($items)) {
      $items = [];
    }
    $out['items'] = array_values(array_map(
      fn (mixed $row): array => is_array($row) ? $this->sanitizeItem($row, $surface) : [],
      $items,
    ));
    $out['items'] = array_values(array_filter($out['items'], static fn (array $r): bool => $r !== []));

    $insights = $payload['insights'] ?? [];
    if (!is_array($insights)) {
      $insights = [];
    }
    $out['insights'] = array_values(array_map(
      fn (mixed $row): array => is_array($row) ? $this->sanitizeInsight($row, $surface) : [],
      $insights,
    ));
    $out['insights'] = array_values(array_filter($out['insights'], static fn (array $r): bool => $r !== []));

    if ($surface === MelSurfaceId::Public) {
      $out['insights'] = [];
    }

    $trace = $payload['prioritisation_trace'] ?? [];
    $out['prioritisation_trace'] = ($surface === MelSurfaceId::Staff && is_array($trace)) ? $trace : [];

    $adaptive = $payload['adaptive_profiles'] ?? [];
    $out['adaptive_profiles'] = ($surface === MelSurfaceId::Staff && is_array($adaptive)) ? $adaptive : [];

    $orch = $payload['orchestration'] ?? [];
    $out['orchestration'] = $this->sanitizeOrchestration(is_array($orch) ? $orch : [], $surface);

    $explain = $payload['explainability'] ?? [];
    $out['explainability'] = $this->sanitizeExplainability(is_array($explain) ? $explain : [], $surface);

    $policy = $payload['operational_policy'] ?? [];
    $out['operational_policy'] = ($surface === MelSurfaceId::Staff && is_array($policy)) ? $policy : [];
    if ($surface === MelSurfaceId::Staff && !is_array($policy)) {
      $out['operational_policy'] = [];
    }

    $out['#cache'] = is_array($cache) ? $cache : [];
    return $out;
  }

  /**
   * @param array<string, mixed> $js
   *
   * @return array<string, mixed>
   */
  public function sanitizeEventStudioGovernanceJs(array $js, MelSurfaceId $surface): array {
    if ($surface === MelSurfaceId::Staff) {
      return $js;
    }
    $out = $js;
    $out['suppressionActiveIds'] = [];
    if (isset($out['experience']) && is_array($out['experience'])) {
      $out['experience']['escalation_active_ids'] = [];
    }
    return $out;
  }

  /**
   * @param array<string, mixed> $item
   *
   * @return array<string, mixed>
   */
  public function sanitizeItem(array $item, MelSurfaceId $surface): array {
    if ($surface === MelSurfaceId::Staff) {
      return $item;
    }
    $allowed = self::PUBLIC_ITEM_KEYS;
    $out = [];
    foreach ($allowed as $key) {
      if (array_key_exists($key, $item)) {
        $out[$key] = $item[$key];
      }
    }
    return $out;
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>
   */
  public function sanitizeInsight(array $row, MelSurfaceId $surface): array {
    if ($surface === MelSurfaceId::Staff) {
      return $row;
    }
    return [
      'id' => (string) ($row['id'] ?? ''),
      'headline' => $row['headline'] ?? '',
    ];
  }

  /**
   * @param array<string, mixed> $orchestration
   *
   * @return array<string, mixed>
   */
  private function sanitizeOrchestration(array $orchestration, MelSurfaceId $surface): array {
    return match ($surface) {
      MelSurfaceId::Staff => $orchestration,
      MelSurfaceId::Vendor => [
        'visible_cap' => (int) ($orchestration['visible_cap'] ?? 0),
        'visible_ids' => $this->sortedStringIds($orchestration['visible_ids'] ?? []),
        'density_policy' => (string) ($orchestration['density_policy'] ?? ''),
        'messaging_hints' => is_array($orchestration['messaging_hints'] ?? NULL)
          ? $orchestration['messaging_hints']
          : [],
        'adaptive_profiles' => is_array($orchestration['adaptive_profiles'] ?? NULL)
          ? $orchestration['adaptive_profiles']
          : [],
      ],
      default => [],
    };
  }

  /**
   * @param array<string, mixed> $explainability
   *
   * @return array<string, mixed>
   */
  private function sanitizeExplainability(array $explainability, MelSurfaceId $surface): array {
    if ($surface === MelSurfaceId::Staff) {
      return $explainability;
    }
    return [
      'no_hidden_scores' => !empty($explainability['no_hidden_scores']),
      'dismiss_supported' => !empty($explainability['dismiss_supported']),
    ];
  }

  /**
   * @param mixed $ids
   *
   * @return list<string>
   */
  private function sortedStringIds(mixed $ids): array {
    if (!is_array($ids)) {
      return [];
    }
    $out = array_values(array_unique(array_map(static fn ($v): string => (string) $v, $ids)));
    sort($out, SORT_STRING);
    return $out;
  }

}
