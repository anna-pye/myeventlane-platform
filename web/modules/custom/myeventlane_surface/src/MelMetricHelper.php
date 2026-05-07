<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Normalises metric presentation structures (values come from upstream builders).
 */
final class MelMetricHelper {

  /**
   * @return array<string, mixed>
   *   Variables safe for mel_metric_card (no queries).
   */
  public function normalizeMetricPresentation(array $raw): array {
    $trend = $raw['trend'] ?? NULL;
    if (!is_string($trend) || !in_array($trend, ['up', 'down', 'flat'], TRUE)) {
      $trend = NULL;
    }
    return [
      'label' => (string) ($raw['label'] ?? ''),
      'value' => (string) ($raw['value'] ?? ''),
      'delta' => isset($raw['delta']) ? (string) $raw['delta'] : NULL,
      'trend' => $trend,
      'status_variant' => isset($raw['status_variant']) ? (string) $raw['status_variant'] : NULL,
    ];
  }

  /**
   * Visual variant for surface (not security — access remains upstream).
   */
  public function getMetricCardModifier(MelSurfaceId $surface): string {
    return match ($surface) {
      MelSurfaceId::Vendor => 'mel-metric-card--dense',
      MelSurfaceId::Staff => 'mel-metric-card--plain',
      MelSurfaceId::Customer => 'mel-metric-card--friendly',
      MelSurfaceId::Public => 'mel-metric-card--minimal',
      MelSurfaceId::Auth => 'mel-metric-card--friendly',
    };
  }

}
