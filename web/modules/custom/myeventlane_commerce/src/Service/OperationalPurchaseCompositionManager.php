<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;

/**
 * Groups Commerce order lines for operational merchandise messaging (read-only).
 *
 * Does not mutate carts, order items, checkout transitions, or entitlements.
 */
final class OperationalPurchaseCompositionManager {

  use StringTranslationTrait;

  public const CONTRACT_FLAG = 'mel_operational_purchase_composition';

  /**
   * Capability reference written for Event Studio parking add-ons on Commerce products.
   *
   * Must match {@see \Drupal\myeventlane_event_studio\Service\VendorProductisationStudioManager::TYPE_PARKING_ADDON}
   * and {@see \Drupal\myeventlane_event_studio\Service\VendorOperationalProductCreationManager::buildOperationalMetadata()}.
   */
  private const PARKING_ADDON_CAPABILITY_REFERENCE = 'parking_addon';

  public function __construct(
    private readonly OperationalMerchandiseManager $operationalMerchandiseManager,
    private readonly OperationalMerchandiseGovernanceManager $operationalMerchandiseGovernanceManager,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @return array<string, mixed>
   */
  public function composeForOrder(OrderInterface $order): array {
    $groups = [
      'merchandise' => [],
      'hospitality' => [],
      'timed_collection' => [],
      'bundles' => [],
      'parking' => [],
    ];
    foreach ($order->getItems() as $item) {
      $purchased = $item->getPurchasedEntity();
      if (!$purchased instanceof ProductVariationInterface) {
        continue;
      }
      $product = $purchased->getProduct();
      if ($product === NULL) {
        continue;
      }
      $bundle = $product->bundle();
      if (!in_array($bundle, OperationalMerchandiseManager::OPERATIONAL_PRODUCT_BUNDLES, TRUE)) {
        continue;
      }
      $payload = $this->operationalMerchandiseManager->normalizeProductFieldFromEntity($product);
      $presentation = $this->operationalMerchandiseManager->buildCustomerSafeProductPresentation($payload);
      $line = [
        'order_item_id' => (int) $item->id(),
        'product_id' => (int) $product->id(),
        'variation_id' => (int) $purchased->id(),
        'quantity' => (string) $item->getQuantity(),
        'bundle_group' => $bundle,
        'presentation' => $presentation,
      ];
      $capability_reference = (string) ($payload['capability_reference'] ?? '');
      $is_parking_addon_merch_bundle = $bundle === 'operational_merchandise'
        && $capability_reference === self::PARKING_ADDON_CAPABILITY_REFERENCE;
      match (TRUE) {
        $bundle === 'hospitality_package' => $groups['hospitality'][] = $line,
        $bundle === 'timed_collection_product' => $groups['timed_collection'][] = $line,
        $bundle === 'operational_bundle' => $groups['bundles'][] = $line,
        $is_parking_addon_merch_bundle => $groups['parking'][] = $line,
        default => $groups['merchandise'][] = $line,
      };
    }

    $flat_presentations = [];
    foreach (['merchandise', 'hospitality', 'timed_collection', 'bundles', 'parking'] as $key) {
      foreach ($groups[$key] as $line) {
        if (is_array($line['presentation'] ?? NULL)) {
          $flat_presentations[] = $line['presentation'];
        }
      }
    }

    return $this->finalizeCompositionDocument($groups, (int) $order->id(), NULL, $flat_presentations);
  }

  /**
   * @return array<string, mixed>|null
   */
  public function buildOrderRenderArray(OrderInterface $order): ?array {
    $document = $this->composeForOrder($order);
    $empty = TRUE;
    foreach ($document['groups'] as $items) {
      if (is_array($items) && $items !== []) {
        $empty = FALSE;
        break;
      }
    }
    if ($empty) {
      return NULL;
    }
    return [
      '#theme' => 'mel_operational_purchase_composition',
      '#document' => $document,
      '#attached' => [
        'library' => ['myeventlane_commerce/mel_operational_merchandise_composition'],
      ],
      '#cache' => [
        'tags' => $order->getCacheTags(),
        'contexts' => ['languages:language_interface'],
      ],
    ];
  }

  /**
   * Builds a composition document from persisted Event Studio operational_merchandise authoring.
   *
   * @return array<string, mixed>
   */
  public function composePreviewFromEvent(NodeInterface $event): array {
    $groups = [
      'merchandise' => [],
      'hospitality' => [],
      'timed_collection' => [],
      'bundles' => [],
      'parking' => [],
    ];
    if (!$event->hasField('field_mel_op_capabilities') || $event->get('field_mel_op_capabilities')->isEmpty()) {
      return $this->finalizeCompositionDocument($groups, NULL, (int) $event->id(), []);
    }
    try {
      $decoded = json_decode(trim((string) $event->get('field_mel_op_capabilities')->value), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return $this->finalizeCompositionDocument($groups, NULL, (int) $event->id(), []);
    }
    if (!is_array($decoded)) {
      return $this->finalizeCompositionDocument($groups, NULL, (int) $event->id(), []);
    }
    $merch = is_array($decoded['operational_merchandise'] ?? NULL) ? $decoded['operational_merchandise'] : [];
    $links = is_array($merch['linked_products'] ?? NULL) ? $merch['linked_products'] : [];
    $presentations = [];
    foreach ($links as $link) {
      if (!is_array($link)) {
        continue;
      }
      $payload = is_array($link['product_payload'] ?? NULL) ? $link['product_payload'] : [];
      $presentation = $this->operationalMerchandiseManager->buildCustomerSafeProductPresentation($payload);
      $presentations[] = $presentation;
      $role = (string) ($link['role'] ?? 'merch_pickup');
      $line = [
        'order_item_id' => 0,
        'product_id' => (int) ($link['product_id'] ?? 0),
        'variation_id' => 0,
        'quantity' => '0',
        'bundle_group' => (string) ($link['bundle_group'] ?? ''),
        'presentation' => $presentation,
        'authoring_role' => $role,
      ];
      match ($role) {
        'hospitality' => $groups['hospitality'][] = $line,
        'timed_collection' => $groups['timed_collection'][] = $line,
        'operational_bundle' => $groups['bundles'][] = $line,
        'parking' => $groups['parking'][] = $line,
        default => $groups['merchandise'][] = $line,
      };
    }

    return $this->finalizeCompositionDocument($groups, NULL, (int) $event->id(), $presentations);
  }

  /**
   * @return array<string, mixed>|null
   */
  public function buildEventRenderArray(NodeInterface $event): ?array {
    $document = $this->composePreviewFromEvent($event);
    $empty = TRUE;
    foreach ($document['groups'] as $items) {
      if (is_array($items) && $items !== []) {
        $empty = FALSE;
        break;
      }
    }
    if ($empty) {
      return NULL;
    }
    return [
      '#theme' => 'mel_operational_purchase_composition',
      '#document' => $document,
      '#attached' => [
        'library' => ['myeventlane_commerce/mel_operational_merchandise_composition'],
      ],
      '#cache' => [
        'tags' => $event->getCacheTags(),
        'contexts' => ['languages:language_interface'],
      ],
    ];
  }

  /**
   * @param list<array<string, mixed>> $flat_presentations
   *
   * @return array<string, mixed>
   */
  private function finalizeCompositionDocument(array $groups, ?int $order_id, ?int $event_id, array $flat_presentations): array {
    $presentations = $flat_presentations;
    if ($presentations === []) {
      foreach (['merchandise', 'hospitality', 'timed_collection', 'bundles', 'parking'] as $key) {
        foreach ($groups[$key] as $line) {
          if (is_array($line['presentation'] ?? NULL)) {
            $presentations[] = $line['presentation'];
          }
        }
      }
    }
    $governance = $this->operationalMerchandiseGovernanceManager->projectGovernance($presentations);
    return [
      'schema_version' => 1,
      self::CONTRACT_FLAG => TRUE,
      'order_id' => $order_id,
      'event_id' => $event_id,
      'groups' => $groups,
      'governance' => $governance,
      'continuity_hint' => (string) $this->t('Collect merchandise and packages after admission when the organiser requires check-in first.'),
    ];
  }

}
