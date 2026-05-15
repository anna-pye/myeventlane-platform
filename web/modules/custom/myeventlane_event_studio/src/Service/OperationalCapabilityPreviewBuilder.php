<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Customer-safe operational capability preview payloads for Event Studio.
 */
final class OperationalCapabilityPreviewBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly OperationalCapabilityStudioManager $studioManager,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @return array<string, mixed>
   */
  public function buildCustomerPreview(array $document): array {
    $capabilities = is_array($document['capabilities'] ?? NULL) ? $document['capabilities'] : [];
    $items = [];
    foreach ($this->studioManager->allAuthoringCapabilityTypes() as $type) {
      $row = is_array($capabilities[$type] ?? NULL) ? $capabilities[$type] : [];
      if (empty($row['enabled'])) {
        continue;
      }
      $visibility = (string) ($row['customer_visibility'] ?? 'after_purchase');
      if ($visibility === 'hidden') {
        continue;
      }
      $summary = trim((string) ($row['preview_summary'] ?? ''));
      if ($summary === '') {
        $summary = $this->studioManager->buildCustomerSafePreviewSummary($type, $row);
      }
      if ($summary === '') {
        continue;
      }
      $semantics = $this->studioManager->projectPolicySemantics($type, $row);
      $items[] = [
        'capability_type' => $type,
        'summary' => $summary,
        'fulfillment_style' => (string) ($semantics['fulfillment_mode'] ?? 'none'),
        'reservation_style' => (string) ($semantics['reservation_mode'] ?? ''),
        'timed_required' => !empty($row['timed_entry']),
        'visibility' => $visibility,
      ];
    }

    return [
      'items' => $items,
      'empty' => $items === [],
      'disclaimer' => (string) $this->t('Preview shows what guests may expect after purchase. Operational execution happens at the venue through your existing scanners and fulfilment tools.'),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function buildOperationalReadinessPreview(array $document): array {
    $projection = $this->studioManager->projectOperationalReadiness($document);
    $chips = [];
    $descriptor = (string) ($projection['descriptor'] ?? 'not_started');
    $chips[] = [
      'label' => match ($descriptor) {
        'configured' => (string) $this->t('Capabilities configured'),
        'needs_review' => (string) $this->t('Review capability settings'),
        'in_progress' => (string) $this->t('Capability setup in progress'),
        default => (string) $this->t('No operational capabilities enabled'),
      },
      'tone' => match ($descriptor) {
        'configured' => 'success',
        'needs_review' => 'warning',
        'in_progress' => 'info',
        default => 'muted',
      },
    ];

    $enabled = (int) ($projection['enabled_count'] ?? 0);
    if ($enabled > 0) {
      $chips[] = [
        'label' => (string) $this->t('@count enabled', ['@count' => (string) $enabled]),
        'tone' => 'neutral',
      ];
    }

    return [
      'chips' => $chips,
      'projection' => $projection,
    ];
  }

}
