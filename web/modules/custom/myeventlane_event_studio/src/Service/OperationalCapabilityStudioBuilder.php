<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
/**
 * Builds Event Studio capability card render data (presentation inputs only).
 */
final class OperationalCapabilityStudioBuilder {

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
  public function buildWorkspaceCards(array $document): array {
    $ui = $this->studioManager->getCapabilityUiMetadata();
    $capabilities = is_array($document['capabilities'] ?? NULL) ? $document['capabilities'] : [];
    $readiness = $this->studioManager->projectOperationalReadiness($document);

    $groups = [
      'entry' => [
        'id' => 'entry',
        'title' => (string) $this->t('Entry'),
        'cards' => [],
      ],
      'access' => [
        'id' => 'access',
        'title' => (string) $this->t('Access'),
        'cards' => [],
      ],
      'fulfilment' => [
        'id' => 'fulfilment',
        'title' => (string) $this->t('Fulfilment'),
        'cards' => [],
      ],
    ];

    foreach ($this->studioManager->allAuthoringCapabilityTypes() as $type) {
      $meta = $ui[$type] ?? ['label' => $type, 'group' => 'fulfilment'];
      $row = is_array($capabilities[$type] ?? NULL) ? $capabilities[$type] : [];
      $enabled = !empty($row['enabled']);
      $semantics = $this->studioManager->projectPolicySemantics($type, $row);
      $group_key = (string) ($meta['group'] ?? 'fulfilment');
      if (!isset($groups[$group_key])) {
        $group_key = 'fulfilment';
      }

      $groups[$group_key]['cards'][] = [
        'capability_type' => $type,
        'label' => (string) ($meta['label'] ?? $type),
        'enabled' => $enabled,
        'status_chip' => $this->buildStatusChip($row),
        'fulfillment_badge' => $this->buildFulfillmentBadge($semantics),
        'reservation_badge' => $this->buildReservationBadge($semantics),
        'readiness_chip' => $this->buildReadinessChip((string) ($row['readiness_state'] ?? '')),
        'timed_required' => !empty($row['timed_entry']),
        'customer_visibility' => (string) ($row['customer_visibility'] ?? 'after_purchase'),
        'preview_summary' => (string) ($row['preview_summary'] ?? ''),
        'form_defaults' => $row,
      ];
    }

    return [
      'groups' => array_values(array_filter($groups, static fn(array $g): bool => $g['cards'] !== [])),
      'readiness' => $readiness,
      'schema_version' => OperationalCapabilityStudioManager::SCHEMA_VERSION,
    ];
  }

  /**
   * @param array<string, mixed> $row
   */
  private function buildStatusChip(array $row): array {
    $enabled = !empty($row['enabled']);
    return [
      'label' => $enabled ? (string) $this->t('Active') : (string) $this->t('Inactive'),
      'tone' => $enabled ? 'active' : 'muted',
    ];
  }

  /**
   * @param array<string, mixed> $semantics
   */
  private function buildFulfillmentBadge(array $semantics): array {
    $mode = (string) ($semantics['fulfillment_mode'] ?? 'none');
    return [
      'label' => (string) $this->t('Fulfillment: @mode', ['@mode' => $mode]),
      'tone' => $mode === 'none' ? 'muted' : 'info',
    ];
  }

  /**
   * @param array<string, mixed> $semantics
   */
  private function buildReservationBadge(array $semantics): array {
    $mode = (string) ($semantics['reservation_mode'] ?? '');
    return [
      'label' => (string) $this->t('Reservation: @mode', ['@mode' => $mode !== '' ? $mode : 'n/a']),
      'tone' => 'neutral',
    ];
  }

  private function buildReadinessChip(string $readiness_state): array {
    return match ($readiness_state) {
      OperationalCapabilityStudioManager::READINESS_CONFIGURED => [
        'label' => (string) $this->t('Ready to publish'),
        'tone' => 'success',
      ],
      OperationalCapabilityStudioManager::READINESS_NEEDS_REVIEW => [
        'label' => (string) $this->t('Needs review'),
        'tone' => 'warning',
      ],
      OperationalCapabilityStudioManager::READINESS_DRAFT => [
        'label' => (string) $this->t('Draft'),
        'tone' => 'info',
      ],
      default => [
        'label' => (string) $this->t('Not configured'),
        'tone' => 'muted',
      ],
    };
  }

}
