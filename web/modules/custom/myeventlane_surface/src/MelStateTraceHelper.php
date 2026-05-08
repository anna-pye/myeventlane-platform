<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Governs state interpretation explainability (no enforcement, no queues).
 */
final class MelStateTraceHelper {

  use StringTranslationTrait;

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param array<string, mixed> $state
   *   Output of MelStateManager::buildPageInterpretation() without #cache.
   *
   * @return list<array<string, mixed>>
   */
  public function buildTraces(
    MelObservabilityVisibilityLevel $tier,
    MelSurfaceId $surface,
    array $state,
  ): array {
    if ($tier === MelObservabilityVisibilityLevel::None) {
      return [];
    }

    $rows = [];
    $tone = (string) ($state['tone'] ?? '');
    if ($tone !== '' && $tier->value >= MelObservabilityVisibilityLevel::Minimal->value) {
      $rows[] = $this->row(
        'mel.obs.state.lifecycle_evaluation',
        'surface_tone',
        (string) $this->t('State tone for this shell: @tone.', ['@tone' => $tone]),
      );
    }

    if ($tier->value >= MelObservabilityVisibilityLevel::Operational->value) {
      $primary = $state['primary_lifecycle_id'] ?? NULL;
      if (is_string($primary) && $primary !== '') {
        $rows[] = $this->row(
          'mel.obs.state.lifecycle_evaluation',
          'primary_lifecycle',
          (string) $this->t('Primary lifecycle interpretation: @id.', ['@id' => $primary]),
        );
      }

      $readiness = $state['readiness_summaries'] ?? [];
      if (is_array($readiness) && $readiness !== []) {
        $keys = array_keys($readiness);
        sort($keys);
        foreach ($keys as $key) {
          if (!is_string($key)) {
            continue;
          }
          $rows[] = $this->row(
            'mel.obs.state.readiness_evaluation',
            'readiness_' . $key,
            (string) $this->t('Readiness summary present for contract @id.', ['@id' => $key]),
          );
        }
      }

      $features = $state['feature_eligibility_ids'] ?? [];
      if (is_array($features) && $features !== []) {
        $ids = array_values(array_filter($features, 'is_string'));
        sort($ids);
        if ($surface === MelSurfaceId::Customer) {
          $rows[] = $this->row(
            'mel.obs.state.eligibility',
            'eligibility_count',
            (string) $this->t('Eligibility signals are active for @count areas (details omitted in customer shell).', [
              '@count' => (string) count($ids),
            ]),
          );
        }
        else {
          foreach ($ids as $fid) {
            $rows[] = $this->row(
              'mel.obs.state.eligibility',
              'feature_' . $fid,
              (string) $this->t('Feature eligibility contract active: @id.', ['@id' => $fid]),
            );
          }
        }
      }

      $cta = $state['cta_governance'] ?? [];
      if (is_array($cta)) {
        foreach (['defer_workflow_primary', 'elevate_stripe_cta', 'hide_publish_cta', 'hide_boost_cta', 'hide_export_cta'] as $flag) {
          if (!empty($cta[$flag])) {
            $rows[] = $this->row(
              'mel.obs.workflow.cta_suppression',
              (string) $flag,
              (string) $this->t('CTA governance flag @flag is active (interpretation only).', ['@flag' => $flag]),
            );
          }
        }
      }
    }

    if ($tier === MelObservabilityVisibilityLevel::Diagnostic && $surface === MelSurfaceId::Staff) {
      $trust = $state['trust_indicator_ids'] ?? [];
      if (is_array($trust) && $trust !== []) {
        $tids = array_values(array_filter($trust, 'is_string'));
        sort($tids);
        foreach ($tids as $tid) {
          $rows[] = $this->row(
            'mel.obs.state.trust_interpretation',
            'trust_' . $tid,
            (string) $this->t('Trust indicator contract (staff diagnostic): @id.', ['@id' => $tid]),
          );
        }
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
