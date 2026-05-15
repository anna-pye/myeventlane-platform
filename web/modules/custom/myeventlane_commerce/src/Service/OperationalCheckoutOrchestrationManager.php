<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;

/**
 * Read-only checkout/cart orchestration over purchase composition (no Commerce mutation).
 *
 * Delegates line grouping and customer-safe payloads to
 * {@see OperationalPurchaseCompositionManager} and only shapes the
 * `mel_operational_checkout` presentation contract.
 */
final class OperationalCheckoutOrchestrationManager {

  use StringTranslationTrait;

  public const CONTRACT_FLAG = 'mel_operational_checkout';

  /**
   * @var list<string>
   */
  public const FORBIDDEN_CHECKOUT_KEYS = [
    'qr_payload',
    'replay_token',
    'scanner_action',
    'inventory_quantity',
    'stock_count',
    'warehouse_ids',
    'shipment_provider',
    'device_fingerprint',
  ];

  public function __construct(
    private readonly OperationalPurchaseCompositionManager $purchaseCompositionManager,
    private readonly OperationalCheckoutGovernanceManager $checkoutGovernanceManager,
    private readonly OperationalCustomerGuidanceBuilder $customerGuidanceBuilder,
    private readonly OperationalCartProjectionBuilder $cartProjectionBuilder,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @return array<string, mixed>|null
   *   Full checkout contract, or NULL when no operational composition exists.
   */
  public function buildCheckoutContractForOrder(OrderInterface $order): ?array {
    $composition = $this->purchaseCompositionManager->composeForOrder($order);
    return $this->finalizeContractFromComposition($composition, (int) $order->id(), NULL);
  }

  /**
   * @return array<string, mixed>|null
   */
  public function buildCheckoutContractForEvent(NodeInterface $event): ?array {
    $composition = $this->purchaseCompositionManager->composePreviewFromEvent($event);
    return $this->finalizeContractFromComposition($composition, NULL, (int) $event->id());
  }

  /**
   * @return array<string, mixed>|null
   */
  public function buildOrderRenderArray(OrderInterface $order): ?array {
    $contract = $this->buildCheckoutContractForOrder($order);
    if ($contract === NULL) {
      return NULL;
    }
    return [
      '#theme' => 'mel_operational_checkout',
      '#checkout' => $contract,
      '#attached' => [
        'library' => ['myeventlane_commerce/mel_operational_checkout'],
      ],
      '#cache' => [
        'tags' => $order->getCacheTags(),
        'contexts' => ['languages:language_interface'],
      ],
    ];
  }

  /**
   * @return array<string, mixed>|null
   */
  public function buildEventRenderArray(NodeInterface $event): ?array {
    $contract = $this->buildCheckoutContractForEvent($event);
    if ($contract === NULL) {
      return NULL;
    }
    return [
      '#theme' => 'mel_operational_checkout',
      '#checkout' => $contract,
      '#attached' => [
        'library' => ['myeventlane_commerce/mel_operational_checkout'],
      ],
      '#cache' => [
        'tags' => $event->getCacheTags(),
        'contexts' => ['languages:language_interface'],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $composition
   *   Output of {@see OperationalPurchaseCompositionManager::composeForOrder()}
   *   or {@see OperationalPurchaseCompositionManager::composePreviewFromEvent()}.
   *
   * @return array<string, mixed>|null
   */
  private function finalizeContractFromComposition(array $composition, ?int $order_id, ?int $event_id): ?array {
    $groups = is_array($composition['groups'] ?? NULL) ? $composition['groups'] : [];
    $empty = TRUE;
    foreach (['merchandise', 'hospitality', 'timed_collection', 'bundles', 'parking'] as $key) {
      if (isset($groups[$key]) && is_array($groups[$key]) && $groups[$key] !== []) {
        $empty = FALSE;
        break;
      }
    }
    if ($empty) {
      return NULL;
    }

    $checkout_groups = $this->normalizeCheckoutGroups($groups);
    $fulfillment_groups = $this->mergeCompositionSlices($groups, ['merchandise', 'bundles']);
    $pickup_groups = $this->filterLinesByPickupSemantics($groups);
    $hospitality_groups = is_array($groups['hospitality'] ?? NULL) ? $groups['hospitality'] : [];
    $timed_collection_groups = is_array($groups['timed_collection'] ?? NULL) ? $groups['timed_collection'] : [];
    $continuity_groups = $this->normalizeContinuityGroups($composition, $groups);

    $readiness_rollup = $this->rollupReadiness($groups);
    $contract = [
      'schema_version' => 1,
      self::CONTRACT_FLAG => TRUE,
      'order_id' => $order_id,
      'event_id' => $event_id,
      'purchase_composition_schema' => $composition['schema_version'] ?? 1,
      'checkout_groups' => $checkout_groups,
      'fulfillment_groups' => $fulfillment_groups,
      'pickup_groups' => $pickup_groups,
      'hospitality_groups' => $hospitality_groups,
      'timed_collection_groups' => $timed_collection_groups,
      'continuity_groups' => $continuity_groups,
      'readiness_rollup' => $readiness_rollup,
      'operational_banners' => [],
      'governance' => [],
      'customer_guidance' => [],
      'cart_projection' => [],
    ];

    $contract['governance'] = $this->checkoutGovernanceManager->projectCheckoutGovernance($contract);
    $contract['customer_guidance'] = $this->customerGuidanceBuilder->buildCustomerGuidance($contract);
    $contract['operational_banners'] = $this->customerGuidanceBuilder->buildOperationalBanners($contract);
    $contract['cart_projection'] = $this->cartProjectionBuilder->buildMobileSections($contract);

    return $this->stripForbiddenRecursive($contract);
  }

  /**
   * @param array<string, mixed> $groups
   *
   * @return list<array<string, mixed>>
   */
  private function normalizeCheckoutGroups(array $groups): array {
    $labels = [
      'merchandise' => (string) $this->t('Merchandise & pickup'),
      'bundles' => (string) $this->t('Operational bundles'),
      'hospitality' => (string) $this->t('Hospitality'),
      'timed_collection' => (string) $this->t('Timed collection'),
      'parking' => (string) $this->t('Parking & access add-ons'),
    ];
    $out = [];
    foreach (['merchandise', 'bundles', 'hospitality', 'timed_collection', 'parking'] as $key) {
      $lines = is_array($groups[$key] ?? NULL) ? $groups[$key] : [];
      if ($lines === []) {
        continue;
      }
      $out[] = [
        'group_key' => $key,
        'label' => $labels[$key] ?? $key,
        'lines' => $lines,
      ];
    }
    return $out;
  }

  /**
   * @param array<string, mixed> $groups
   * @param list<string>          $keys
   *
   * @return list<array<string, mixed>>
   */
  private function mergeCompositionSlices(array $groups, array $keys): array {
    $merged = [];
    foreach ($keys as $key) {
      $chunk = is_array($groups[$key] ?? NULL) ? $groups[$key] : [];
      foreach ($chunk as $line) {
        if (is_array($line)) {
          $merged[] = $line;
        }
      }
    }
    return $merged;
  }

  /**
   * @param array<string, mixed> $groups
   *
   * @return list<array<string, mixed>>
   */
  private function filterLinesByPickupSemantics(array $groups): array {
    $out = [];
    foreach (['merchandise', 'bundles', 'parking'] as $key) {
      $lines = is_array($groups[$key] ?? NULL) ? $groups[$key] : [];
      foreach ($lines as $line) {
        if (!is_array($line)) {
          continue;
        }
        $pres = is_array($line['presentation'] ?? NULL) ? $line['presentation'] : [];
        $pickup = (string) ($pres['pickup_mode'] ?? '');
        if ($pickup !== '' && $pickup !== 'none') {
          $out[] = $line;
        }
      }
    }
    return $out;
  }

  /**
   * @param array<string, mixed> $composition
   * @param array<string, mixed> $groups
   *
   * @return list<array<string, mixed>>
   */
  private function normalizeContinuityGroups(array $composition, array $groups): array {
    $rows = [];
    $hint = trim((string) ($composition['continuity_hint'] ?? ''));
    if ($hint !== '') {
      $rows[] = [
        'kind' => 'composition_hint',
        'summary' => $hint,
      ];
    }
    foreach (['merchandise', 'bundles', 'hospitality', 'timed_collection'] as $key) {
      $lines = is_array($groups[$key] ?? NULL) ? $groups[$key] : [];
      foreach ($lines as $line) {
        if (!is_array($line)) {
          continue;
        }
        $pres = is_array($line['presentation'] ?? NULL) ? $line['presentation'] : [];
        $summary = trim((string) ($pres['operational_summary'] ?? ''));
        if ($summary === '') {
          continue;
        }
        $rows[] = [
          'kind' => 'line_continuity',
          'group_key' => $key,
          'product_id' => (int) ($line['product_id'] ?? 0),
          'summary' => $summary,
        ];
      }
    }
    return $rows;
  }

  /**
   * @param array<string, mixed> $groups
   *
   * @return array<string, int>
   */
  private function rollupReadiness(array $groups): array {
    $rollup = [
      'authoring' => 0,
      'blocked' => 0,
      'degraded' => 0,
      'nominal' => 0,
      'unknown' => 0,
    ];
    foreach (['merchandise', 'hospitality', 'timed_collection', 'bundles', 'parking'] as $key) {
      $lines = is_array($groups[$key] ?? NULL) ? $groups[$key] : [];
      foreach ($lines as $line) {
        if (!is_array($line)) {
          continue;
        }
        $pres = is_array($line['presentation'] ?? NULL) ? $line['presentation'] : [];
        $mode = strtolower(trim((string) ($pres['readiness_mode'] ?? 'unknown')));
        if (!isset($rollup[$mode])) {
          $rollup['unknown']++;
        }
        else {
          $rollup[$mode]++;
        }
      }
    }
    return $rollup;
  }

  /**
   * @param array<int|string, mixed> $data
   *
   * @return array<int|string, mixed>
   */
  private function stripForbiddenRecursive(array $data): array {
    if (array_is_list($data)) {
      $out = [];
      foreach ($data as $item) {
        if (is_array($item)) {
          $out[] = $this->stripForbiddenRecursive($item);
        }
        elseif (is_scalar($item) || $item === NULL) {
          $out[] = $item;
        }
      }
      return $out;
    }
    $out = [];
    foreach ($data as $key => $value) {
      if (!is_string($key) || in_array($key, self::FORBIDDEN_CHECKOUT_KEYS, TRUE)) {
        continue;
      }
      if (is_array($value)) {
        $out[$key] = $this->stripForbiddenRecursive($value);
      }
      elseif (is_scalar($value) || $value === NULL) {
        $out[$key] = $value;
      }
    }
    return $out;
  }

}
