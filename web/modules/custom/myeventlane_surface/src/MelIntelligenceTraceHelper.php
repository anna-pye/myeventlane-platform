<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Wraps existing intelligence explainability without recomputing orchestration.
 */
final class MelIntelligenceTraceHelper {

  use StringTranslationTrait;

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param array<string, mixed> $intelligence
   *
   * @return list<array<string, mixed>>
   */
  public function buildTraces(
    MelObservabilityVisibilityLevel $tier,
    MelSurfaceId $surface,
    array $intelligence,
  ): array {
    if ($tier->value < MelObservabilityVisibilityLevel::Operational->value) {
      return [];
    }

    $rows = [];
    $trace = $intelligence['prioritisation_trace'] ?? [];
    if (is_array($trace)) {
      foreach ($trace as $row) {
        if (!is_array($row)) {
          continue;
        }
        $id = (string) ($row['id'] ?? '');
        $pos = (string) ($row['position'] ?? '');
        if ($id === '') {
          continue;
        }
        if ($surface === MelSurfaceId::Customer) {
          $rows[] = $this->row(
            'mel.obs.intelligence.prioritisation',
            'rank_customer',
            (string) $this->t('Guidance is ordered calmly so the most important next step comes first.'),
            '',
          );
          break;
        }
        $rows[] = $this->row(
          'mel.obs.intelligence.recommendation_ordering',
          'position_' . $pos . '_' . $id,
          (string) $this->t('Recommendation @id is position @pos using deterministic tier, weight, and id ordering.', [
            '@id' => $id,
            '@pos' => $pos,
          ]),
          '',
        );
      }
    }

    $orch = $intelligence['orchestration'] ?? [];
    if (is_array($orch)) {
      $rows[] = $this->row(
        'mel.obs.intelligence.engagement_orchestration',
        'visible_cap',
        (string) $this->t('Engagement orchestration visible cap: @cap.', [
          '@cap' => (string) ($orch['visible_cap'] ?? 0),
        ]),
        '',
      );
      if (!empty($orch['operational_policy_applied'])) {
        $rows[] = $this->row(
          'mel.obs.intelligence.engagement_orchestration',
          'operational_policy_applied',
          (string) $this->t('Operational policy adjustments were applied to visible orchestration for this request.'),
          '',
        );
      }
    }

    return $this->sortRows($rows);
  }

  /**
   * @param list<array<string, mixed>> $rows
   *
   * @return list<array<string, mixed>>
   */
  private function sortRows(array $rows): array {
    usort($rows, static function (array $a, array $b): int {
      return strcmp((string) ($a['deterministic_key'] ?? ''), (string) ($b['deterministic_key'] ?? ''));
    });
    return array_values($rows);
  }

  /**
   * @return array<string, mixed>
   */
  private function row(string $observability_id, string $code, string $message, string $disambiguator = ''): array {
    return [
      'observability_id' => $observability_id,
      'code' => $code,
      'message' => $message,
      'deterministic_key' => MelObservabilityDeterministicKey::format($observability_id, $code, $disambiguator),
    ];
  }

}
