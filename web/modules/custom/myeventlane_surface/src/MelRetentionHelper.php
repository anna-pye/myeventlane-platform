<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Retention continuity coordination — tiered, respectful sequencing hints only.
 */
final class MelRetentionHelper {

  /**
   * @param list<\Drupal\myeventlane_surface\ExperienceDefinition> $applicable
   *
   * @return array<string, mixed>
   */
  public function buildRetentionProfile(array $applicable): array {
    $tier = MelRetentionTier::None;
    foreach ($applicable as $definition) {
      if ($definition->retentionTier === MelRetentionTier::Medium) {
        $tier = MelRetentionTier::Medium;
      }
    }
    if ($tier !== MelRetentionTier::Medium) {
      foreach ($applicable as $definition) {
        if ($definition->retentionTier === MelRetentionTier::Low) {
          $tier = MelRetentionTier::Low;
          break;
        }
      }
    }

    $contributors = [];
    foreach ($applicable as $definition) {
      if ($definition->retentionTier !== MelRetentionTier::None) {
        $contributors[] = $definition->id;
      }
    }

    return [
      'tier' => $tier->value,
      'contributor_experience_ids' => array_values(array_unique($contributors)),
      'implications' => $this->summariseImplications($applicable),
    ];
  }

  /**
   * @param list<\Drupal\myeventlane_surface\ExperienceDefinition> $applicable
   *
   * @return list<string>
   */
  private function summariseImplications(array $applicable): array {
    $lines = [];
    foreach ($applicable as $definition) {
      if ($definition->retentionTier === MelRetentionTier::None) {
        continue;
      }
      if ($definition->retentionImplications !== '') {
        $lines[] = $definition->id . ': ' . $definition->retentionImplications;
      }
    }
    return $lines;
  }

}
