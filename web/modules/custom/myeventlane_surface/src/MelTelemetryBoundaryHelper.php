<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Static governance copy for telemetry boundaries (no data collection).
 */
final class MelTelemetryBoundaryHelper {

  use StringTranslationTrait;

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function buildTraces(MelObservabilityVisibilityLevel $tier, MelSurfaceId $surface): array {
    if ($tier !== MelObservabilityVisibilityLevel::Diagnostic || $surface !== MelSurfaceId::Staff) {
      return [];
    }

    $rows = [
      $this->row(
        'mel.obs.telemetry.drupal_log_boundary',
        'logger_ownership',
        (string) $this->t('Drupal watchdog channels own enforcement logs; MELObservabilitySystem never replaces them.'),
      ),
      $this->row(
        'mel.obs.telemetry.drupal_settings_boundary',
        'settings_shape',
        (string) $this->t('drupalSettings may include only non-sensitive MEL shell hints already approved for the active surface.'),
      ),
      $this->row(
        'mel.obs.telemetry.twig_boundary',
        'twig_shape',
        (string) $this->t('Twig renders structured variables from preprocess; do not invent ad-hoc diagnostics in templates.'),
      ),
    ];

    return array_values($rows);
  }

  /**
   * @return array<string, string>
   */
  public function boundaryReference(): array {
    return [
      'drupal_log' => 'Owned by Drupal\Core\Logger; MEL adds explainability rows only.',
      'drupalSettings' => 'Opaque shell hints and continuity ids approved per surface; no PII.',
      'twig' => 'Consume mel_* variables; no raw orchestration dumps on public/customer shells.',
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function row(string $observability_id, string $code, string $message): array {
    return [
      'observability_id' => $observability_id,
      'code' => $code,
      'message' => $message,
      'deterministic_key' => MelObservabilityDeterministicKey::format($observability_id, $code),
    ];
  }

}
