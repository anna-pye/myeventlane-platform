<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Presentation-only commerce linkage chips and binding labels for capability cards.
 */
final class OperationalCapabilityCommercePreviewBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly OperationalCapabilityCommerceLinkManager $commerceLinkManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>
   */
  public function buildCardLinkagePresentation(array $row): array {
    $link = is_array($row['commerce_linkage'] ?? NULL) ? $row['commerce_linkage'] : [];
    $chips = [];
    $pid = (int) ($link['product_id'] ?? 0);
    if ($pid > 0) {
      $product = $this->entityTypeManager->getStorage('commerce_product')->load($pid);
      if ($product instanceof ProductInterface) {
        $chips[] = [
          'label' => $product->label(),
          'tone' => 'neutral',
        ];
      }
    }

    $binding = (string) ($link['capability_binding_state'] ?? OperationalCapabilityCommerceLinkManager::BINDING_UNBOUND);
    $binding_label = match ($binding) {
      OperationalCapabilityCommerceLinkManager::BINDING_BOUND => (string) $this->t('Tickets connected'),
      OperationalCapabilityCommerceLinkManager::BINDING_PARTIAL => (string) $this->t('Tickets need attention'),
      OperationalCapabilityCommerceLinkManager::BINDING_INVALID => (string) $this->t('Tickets need attention'),
      default => (string) $this->t('Tickets not set up'),
    };

    $readiness = is_array($link['readiness_projection'] ?? NULL) ? $link['readiness_projection'] : [];
    $preview = trim((string) ($link['operational_preview'] ?? ''));

    return [
      'commerce_chips' => $chips,
      'binding_state' => $binding,
      'binding_label' => $binding_label,
      'readiness_descriptor' => (string) ($readiness['descriptor'] ?? ''),
      'commerce_link_ready' => (bool) ($readiness['commerce_link_ready'] ?? FALSE),
      'operational_preview' => $preview,
    ];
  }

  /**
   * Customer-safe strings derived from normalized linkage metadata (no SKUs, stock, or scanners).
   *
   * @param array<string, mixed> $commerce_linkage
   *
   * @return list<string>
   */
  public function buildCustomerSafeLinkageLines(string $capability_type, array $commerce_linkage): array {
    $summary = trim($this->commerceLinkManager->buildOperationalPreviewSummary($capability_type, $commerce_linkage));
    return $summary !== '' ? [$summary] : [];
  }

}
