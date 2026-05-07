<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Canonical suppression interpretation for intelligence and experience hints.
 */
final class MelSuppressionPolicyHelper {

  public function __construct(
    private readonly MelOperationalPolicyRegistry $registry,
  ) {}

  /**
   * @param list<string> $activePolicyIds
   *
   * @return array<string, bool>
   */
  public function suppressionMap(array $activePolicyIds): array {
    $keys = [
      'suppress_recommendations',
      'suppress_marketing_guidance',
      'suppress_boost_prompts',
      'suppress_nonessential_notifications',
      'suppress_cross_sell_guidance',
    ];
    $map = [];
    foreach ($keys as $key) {
      $map[$key] = in_array($key, $activePolicyIds, TRUE);
    }
    $definitions = $this->registry->all();
    foreach ($activePolicyIds as $id) {
      $def = $definitions[$id] ?? NULL;
      if (!$def instanceof OperationalPolicyDefinition) {
        continue;
      }
      foreach ($def->suppressionImplications as $impl) {
        if (isset($map[$impl])) {
          $map[$impl] = TRUE;
        }
      }
    }
    return $map;
  }

  /**
   * Intelligence definition ids to omit from visible orchestration when suppressed.
   *
   * @param array<string, bool> $suppressionMap
   *
   * @return list<string>
   */
  public function intelligenceDefinitionIdsToSuppress(
    array $suppressionMap,
    MelSurfaceId $surface,
  ): array {
    $hidden = [];
    if (!empty($suppressionMap['suppress_recommendations'])) {
      array_push($hidden, ...[
        'recommended_events',
        'revisit_recent_event',
        'public_discovery_events_hint',
      ]);
    }
    if (!empty($suppressionMap['suppress_boost_prompts'])) {
      $hidden[] = 'boost_event_prompt';
    }
    if (!empty($suppressionMap['suppress_marketing_guidance'])) {
      array_push($hidden, ...[
        'trust_building_prompt',
        'share_event_prompt',
        'low_ticket_sales_prompt',
      ]);
    }
    if (!empty($suppressionMap['suppress_cross_sell_guidance'])) {
      array_push($hidden, ...[
        're_engagement_prompt',
        'return_to_platform_prompt',
      ]);
    }
    if ($surface === MelSurfaceId::Staff) {
      $hidden = array_values(array_filter(
        $hidden,
        static fn (string $id): bool => !str_starts_with($id, 'staff_'),
      ));
    }
    return array_values(array_unique($hidden));
  }

}
