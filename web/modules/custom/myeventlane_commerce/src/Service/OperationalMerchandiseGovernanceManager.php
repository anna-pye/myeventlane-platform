<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Read-only governance projections for operational merchandise visibility.
 */
final class OperationalMerchandiseGovernanceManager {

  use StringTranslationTrait;

  public function __construct(
    private readonly OperationalMerchandiseManager $operationalMerchandiseManager,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param list<array<string, mixed>> $presentations
   *   Customer-safe presentations from {@see OperationalMerchandiseManager::buildCustomerSafeProductPresentation()}.
   *
   * @return array<string, mixed>
   */
  public function projectGovernance(array $presentations): array {
    $severity = 'nominal';
    $pickup_degraded = 0;
    $hospitality_degraded = 0;
    $continuity_conflicts = 0;
    $bundle_conflicts = 0;

    foreach ($presentations as $row) {
      if (!is_array($row)) {
        continue;
      }
      $readiness = (string) ($row['readiness_mode'] ?? '');
      if ($readiness === 'blocked' || $readiness === 'degraded') {
        $severity = 'elevated';
        $pickup_degraded++;
      }
      $type = (string) ($row['operational_product_type'] ?? '');
      if (str_contains($type, 'hospitality') && (($row['customer_visibility'] ?? '') === 'hidden')) {
        $hospitality_degraded++;
      }
      $pickup = (string) ($row['pickup_mode'] ?? '');
      if ($pickup === 'conflict') {
        $continuity_conflicts++;
      }
    }

    if ($continuity_conflicts > 0) {
      $severity = 'elevated';
    }
    if ($pickup_degraded > 1) {
      $bundle_conflicts++;
    }

    return [
      'severity' => $severity,
      'pickup_degraded_rows' => $pickup_degraded,
      'hospitality_degraded_rows' => $hospitality_degraded,
      'continuity_conflict_rows' => $continuity_conflicts,
      'bundle_conflict_rows' => $bundle_conflicts,
      'severity_label' => $severity === 'elevated'
        ? (string) $this->t('Watch')
        : (string) $this->t('Nominal'),
    ];
  }

  /**
   * @param array<string, mixed> $authoring
   *   Output of {@see OperationalMerchandiseManager::normalizeEventMerchandiseAuthoring()}.
   *
   * @return array<string, mixed>
   */
  public function projectAuthoringGovernance(array $authoring): array {
    $links = is_array($authoring['linked_products'] ?? NULL) ? $authoring['linked_products'] : [];
    $presentations = [];
    foreach ($links as $link) {
      if (!is_array($link)) {
        continue;
      }
      $payload = is_array($link['product_payload'] ?? NULL) ? $link['product_payload'] : [];
      $presentations[] = $this->operationalMerchandiseManager->buildCustomerSafeProductPresentation($payload);
    }
    return $this->projectGovernance($presentations);
  }

}
