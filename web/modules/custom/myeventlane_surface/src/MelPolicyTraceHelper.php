<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Maps operational policy interpretation into governed policy traces.
 */
final class MelPolicyTraceHelper {

  use StringTranslationTrait;

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param array<string, mixed> $policy
   *
   * @return list<array<string, mixed>>
   */
  public function buildTraces(
    MelObservabilityVisibilityLevel $tier,
    MelSurfaceId $surface,
    array $policy,
  ): array {
    if ($tier->value < MelObservabilityVisibilityLevel::Operational->value) {
      return [];
    }

    $rows = [];
    $explain = $policy['explainability'] ?? [];
    $lines = is_array($explain) ? ($explain['lines'] ?? []) : [];
    if (is_array($lines)) {
      $i = 0;
      foreach ($lines as $line) {
        if (!is_string($line) || $line === '') {
          continue;
        }
        $i++;
        $rows[] = $this->row(
          'mel.obs.policy.suppression',
          'explainability_line',
          $line,
          (string) $i,
        );
      }
    }

    $automation = $policy['automation'] ?? [];
    if (is_array($automation) && $automation !== []) {
      $rows[] = $this->row(
        'mel.obs.policy.automation_boundary',
        'automation_present',
        (string) $this->t('Automation boundary interpretation is active for this screen (tone and deferral only).'),
        '',
      );
    }

    $hints = $policy['communication_hints'] ?? [];
    if (is_array($hints) && $hints !== []) {
      $rows[] = $this->row(
        'mel.obs.policy.communication',
        'communication_hints',
        (string) $this->t('Communication tone hints are active (@count).', ['@count' => (string) count($hints)]),
        '',
      );
    }

    if ($tier === MelObservabilityVisibilityLevel::Diagnostic && $surface === MelSurfaceId::Staff) {
      $trust = $policy['trust_thresholds'] ?? [];
      if (is_array($trust) && $trust !== []) {
        $rows[] = $this->row(
          'mel.obs.policy.trust_threshold',
          'trust_thresholds_present',
          (string) $this->t('Trust threshold interpretation rows are present for staff review (no customer payload).'),
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
