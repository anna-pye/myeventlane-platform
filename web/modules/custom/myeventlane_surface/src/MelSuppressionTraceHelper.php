<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Governs suppression visibility from intelligence + operational policy (categorical only).
 */
final class MelSuppressionTraceHelper {

  use StringTranslationTrait;

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param array<string, mixed> $intelligence
   * @param array<string, mixed> $policy
   *
   * @return list<array<string, mixed>>
   */
  public function buildTraces(
    MelObservabilityVisibilityLevel $tier,
    MelSurfaceId $surface,
    array $intelligence,
    array $policy,
  ): array {
    if ($tier->value < MelObservabilityVisibilityLevel::Operational->value) {
      return [];
    }

    $rows = [];
    $orch = $intelligence['orchestration'] ?? [];
    $suppressed = is_array($orch['suppressed_ids'] ?? NULL) ? $orch['suppressed_ids'] : [];
    $visible = is_array($orch['visible_ids'] ?? NULL) ? $orch['visible_ids'] : [];
    $density = (string) ($orch['density_policy'] ?? '');
    $cap = (int) ($orch['visible_cap'] ?? 0);

    if ($density !== '') {
      $rows[] = $this->row(
        'mel.obs.intelligence.suppression',
        'density_policy',
        (string) $this->t('Engagement density policy: @policy (visible cap @cap).', [
          '@policy' => $density,
          '@cap' => (string) $cap,
        ]),
        $density . ':' . (string) $cap,
      );
    }

    foreach ($suppressed as $id) {
      if (!is_string($id) || $id === '') {
        continue;
      }
      $rows[] = $this->row(
        'mel.obs.intelligence.suppression',
        'orchestration_suppressed',
        (string) $this->t('Recommendation @id is not shown in the primary guidance set due to sequencing or density limits.', [
          '@id' => $id,
        ]),
        $id,
      );
    }

    $gov = $policy['intelligence_governance'] ?? [];
    $hidden = is_array($gov['suppress_definition_ids'] ?? NULL) ? $gov['suppress_definition_ids'] : [];
    foreach ($hidden as $id) {
      if (!is_string($id) || $id === '') {
        continue;
      }
      if (!in_array($id, $visible, TRUE)) {
        continue;
      }
      $rows[] = $this->row(
        'mel.obs.policy.suppression',
        'policy_definition_suppress',
        (string) $this->t('Operational policy removed @id from the visible orchestration set for this request.', [
          '@id' => $id,
        ]),
        $id,
      );
    }

    $cap_reduction = (int) ($gov['orchestration_cap_reduction'] ?? 0);
    if ($cap_reduction > 0) {
      $rows[] = $this->row(
        'mel.obs.policy.suppression',
        'orchestration_cap_reduction',
        (string) $this->t('Operational policy reduced the visible guidance cap by @n.', ['@n' => (string) $cap_reduction]),
        (string) $cap_reduction,
      );
    }

    if ($surface === MelSurfaceId::Customer && count($rows) > 4) {
      $rows = array_slice($rows, 0, 4);
      $rows[] = $this->row(
        'mel.obs.intelligence.suppression',
        'customer_truncation',
        (string) $this->t('Additional suppression detail is available to staff diagnostics only.'),
        '',
      );
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
  /**
   * @param string $disambiguator
   *   Locale-invariant token when the same {@code $code} can repeat (e.g. suppressed id).
   *   Use '' when {@code $code} is already unique for this observability_id.
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
