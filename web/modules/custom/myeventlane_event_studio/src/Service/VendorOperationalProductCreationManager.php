<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product as CommerceProduct;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates operational Commerce products/variations for Event Studio (explicit save only).
 *
 * Commerce remains authoritative for catalog, carts, checkout, and orders.
 * No stock, warehouse, shipping, scanners, QR, entitlements, or checkout mutation.
 */
final class VendorOperationalProductCreationManager {

  /**
   * @var list<string>
   */
  public const FORBIDDEN_CREATION_KEYS = [
    'inventory_quantity',
    'stock_count',
    'stock_level',
    'warehouse_ids',
    'warehouse_slot',
    'shipment_provider',
    'shipment_state',
    'qr_payload',
    'replay_token',
    'scanner_action',
    'scanner_tokens',
    'scanner_secret',
    'entitlement_id',
    'ticket_id',
    'device_fingerprint',
    'operational_fingerprint',
    'staff_integrity',
    'metadata_json',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OperationalMerchandiseManager $operationalMerchandiseManager,
    private readonly OperationalCapabilityCommerceLinkManager $commerceLinkManager,
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
    private readonly TranslationInterface $translation,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * @param array<string, mixed> $payload
   *   Normalized creation payload (see stripForbiddenFromPayload).
   *
   * @return list<string>
   */
  public function validateCreationPayload(AccountInterface $account, NodeInterface $event, array $payload): array {
    $errors = [];
    if ($account->isAnonymous()) {
      return ['Authentication is required to create products.'];
    }
    if (!$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $account)
      && !$account->hasPermission('administer nodes')) {
      return ['You do not have permission to create products for this event.'];
    }
    $store_id = $this->commerceLinkManager->resolveStoreIdForOperationalProductCreation($event);
    if ($store_id === NULL || $store_id < 1) {
      $errors[] = 'No Commerce store could be resolved for this event.';
    }
    $type = $this->normalizeProductisationType((string) ($payload['productisation_type'] ?? ''));
    if ($type === '') {
      $errors[] = 'A valid productisation type is required.';
    }
    $title = trim((string) ($payload['title'] ?? ''));
    if ($title === '') {
      $errors[] = 'Title is required.';
    }
    $summary = trim((string) ($payload['customer_summary'] ?? ''));
    if ($summary === '') {
      $errors[] = 'Customer summary is required.';
    }
    $currency = $this->normalizeCurrencyCode((string) ($payload['currency_code'] ?? ''));
    if (strlen($currency) !== 3) {
      $errors[] = 'A valid currency code is required.';
    }
    $amount = $this->normalizePriceAmount($payload['price_amount'] ?? NULL);
    if ($amount === NULL) {
      $errors[] = 'A valid price amount is required.';
    }
    if ((int) ($payload['event_id'] ?? 0) !== (int) $event->id()) {
      $errors[] = 'Event context mismatch.';
    }
    return $errors;
  }

  /**
   * Creates an operational Commerce product + default variation for an event.
   *
   * @param array<string, mixed> $payload
   *   Raw payload; forbidden keys are stripped recursively.
   *
   * @return array<string, mixed>
   *   Keys: product_id, variation_ids, store_id, customer_summary, product_bundle.
   *
   * @throws \InvalidArgumentException
   */
  public function createOperationalProductForEvent(AccountInterface $account, NodeInterface $event, array $payload): array {
    $payload = $this->stripForbiddenFromPayload($payload);
    $errors = $this->validateCreationPayload($account, $event, $payload);
    if ($errors !== []) {
      throw new \InvalidArgumentException(implode(' ', $errors));
    }

    $store_id = (int) $this->commerceLinkManager->resolveStoreIdForOperationalProductCreation($event);
    $store = $this->entityTypeManager->getStorage('commerce_store')->load($store_id);
    if (!$store instanceof StoreInterface) {
      throw new \InvalidArgumentException('Commerce store could not be loaded.');
    }

    if (!$account->hasPermission('administer nodes')) {
      $vendor_store = $this->commerceLinkManager->resolveVendorStoreIdForEvent($event);
      if ($vendor_store !== NULL && $vendor_store !== $store_id) {
        throw new \InvalidArgumentException('Resolved Commerce store does not match the vendor store for this event.');
      }
    }

    $existing = (int) ($payload['existing_product_id'] ?? 0);
    if ($existing > 0) {
      return $this->resolveExistingOperationalProduct($event, $existing, $store_id);
    }

    $type = $this->normalizeProductisationType((string) $payload['productisation_type']);
    $bundles = $this->mapProductisationTypeToCommerceBundles($type);
    $currency = $this->normalizeCurrencyCode((string) $payload['currency_code']);
    $default_currency = $store->getDefaultCurrencyCode();
    if (!$account->hasPermission('administer nodes') && strtoupper($currency) !== strtoupper($default_currency)) {
      throw new \InvalidArgumentException('Currency must match the event store default currency.');
    }

    $amount = (string) $this->normalizePriceAmount($payload['price_amount'] ?? NULL);
    $title = $this->sanitizePlainText((string) $payload['title'], 255);
    $summary = $this->sanitizePlainText((string) $payload['customer_summary'], 600);
    $sku_base = trim((string) ($payload['sku'] ?? ''));
    $sku = $sku_base !== '' ? $this->sanitizePlainText($sku_base, 128) : $this->generateSku($event, $type);

    $metadata = $this->buildOperationalMetadata($type, $payload, $summary);
    $encoded = json_encode($this->operationalMerchandiseManager->normalizeProductFieldValue($metadata), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    $product = CommerceProduct::create([
      'type' => $bundles['product_type'],
      'title' => $title,
      'status' => 1,
      'stores' => [$store_id],
      'uid' => (int) $account->id(),
      'field_event' => ['target_id' => (int) $event->id()],
      'field_mel_operational_product' => $encoded,
    ]);
    $product->save();

    $price = new Price($amount, strtoupper($currency));

    $variation = ProductVariation::create([
      'type' => $bundles['variation_type'],
      'sku' => $sku,
      'title' => $title,
      'status' => 1,
      'price' => $price,
    ]);
    $variation->save();

    $product->addVariation($variation);
    $product->save();

    $reloaded = $this->entityTypeManager->getStorage('commerce_product')->load($product->id());
    if (!$reloaded instanceof ProductInterface) {
      throw new \RuntimeException('Operational product could not be reloaded after save.');
    }

    $presentation = $this->operationalMerchandiseManager->buildCustomerSafeProductPresentation(
      $this->operationalMerchandiseManager->normalizeProductFieldFromEntity($reloaded),
    );

    $this->logger->notice('Vendor operational product @pid created for event @eid (bundle @bundle).', [
      '@pid' => (string) $reloaded->id(),
      '@eid' => (string) $event->id(),
      '@bundle' => $bundles['product_type'],
    ]);

    $vars = [];
    foreach ($reloaded->getVariations() as $v) {
      $vars[] = (int) $v->id();
    }

    return [
      'product_id' => (int) $reloaded->id(),
      'variation_ids' => $vars,
      'store_id' => $store_id,
      'customer_summary' => (string) ($presentation['operational_summary'] ?? $summary),
      'product_bundle' => $bundles['product_type'],
    ];
  }

  /**
   * Applies explicit-save operational Commerce creates from wizard rows (no autosave).
   *
   * @param list<array<string, mixed>> $items
   *   Productisation items in {@see VendorProductisationStudioManager::PRODUCTISATION_TYPES} order.
   * @param array<string, array<string, mixed>> $wizardRowsByType
   *   Raw form rows keyed by productisation type (includes commerce_mode and create fields).
   *
   * @return list<array<string, mixed>>
   */
  public function applyProductisationWizardCreates(AccountInterface $account, NodeInterface $event, array $items, array $wizardRowsByType): array {
    foreach (VendorProductisationStudioManager::PRODUCTISATION_TYPES as $idx => $type) {
      if (!isset($items[$idx]) || !is_array($items[$idx])) {
        continue;
      }
      $row = $this->flattenWizardFormRow(is_array($wizardRowsByType[$type] ?? NULL) ? $wizardRowsByType[$type] : []);
      if ((string) ($row['commerce_mode'] ?? 'link') !== 'create') {
        continue;
      }
      if ($this->extractWizardCommerceProductId($row) > 0) {
        continue;
      }
      $payload = $this->buildWizardCreationPayload($type, $row, $event);
      $created = $this->createOperationalProductForEvent($account, $event, $payload);
      $items[$idx]['commerce']['product_id'] = (int) $created['product_id'];
      $items[$idx]['commerce']['variation_ids'] = array_map('intval', is_array($created['variation_ids'] ?? NULL) ? $created['variation_ids'] : []);
      $items[$idx]['commerce']['linkage_mode'] = OperationalCapabilityCommerceLinkManager::LINKAGE_PRODUCT;
    }
    return $items;
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>
   */
  public function buildWizardCreationPayload(string $productisation_type, array $row, NodeInterface $event): array {
    $row = $this->flattenWizardFormRow($row);
    $type = $this->normalizeProductisationType($productisation_type);
    if ($type === '') {
      $type = VendorProductisationStudioManager::TYPE_MERCHANDISE;
    }
    [$title, $summary] = $this->wizardTitleAndSummary($type, $row);
    $title = trim((string) ($row['commerce_create_title'] ?? '')) !== '' ? trim(strip_tags((string) $row['commerce_create_title'])) : $title;
    $summary = trim((string) ($row['commerce_create_customer_summary'] ?? '')) !== '' ? trim(strip_tags((string) $row['commerce_create_customer_summary'])) : $summary;
    $fulfillment = match ($type) {
      VendorProductisationStudioManager::TYPE_HOSPITALITY_PACKAGE => (string) ($row['fulfillment_mode'] ?? 'redeem'),
      VendorProductisationStudioManager::TYPE_MERCHANDISE => (string) ($row['pickup_mode'] ?? 'counter'),
      default => (string) ($row['commerce_create_fulfillment_mode'] ?? 'collect'),
    };
    $reservation = (string) ($row['commerce_create_reservation_mode'] ?? 'operational_projection');
    $visibility = match ($type) {
      VendorProductisationStudioManager::TYPE_OPERATIONAL_BUNDLE => (string) ($row['visibility'] ?? 'visible'),
      default => (string) ($row['customer_visibility'] ?? 'visible'),
    };
    $bundle_caps = [];
    if ($type === VendorProductisationStudioManager::TYPE_OPERATIONAL_BUNDLE) {
      $g = is_array($row['grouped_capability_types'] ?? NULL) ? $row['grouped_capability_types'] : [];
      foreach ($g as $cap => $on) {
        if (!empty($on)) {
          $bundle_caps[] = (string) $cap;
        }
      }
    }
    $payload = [
      'productisation_type' => $type,
      'event_id' => (int) $event->id(),
      'title' => $title,
      'customer_summary' => $summary,
      'price_amount' => $row['commerce_create_price'] ?? NULL,
      'currency_code' => (string) ($row['commerce_create_currency'] ?? ''),
      'customer_visibility' => $visibility,
      'fulfillment_mode' => $fulfillment,
      'reservation_mode' => $reservation,
      'sku' => $row['commerce_create_sku'] ?? NULL,
      'pickup_mode' => (string) ($row['pickup_mode'] ?? ''),
      'timed_collection_window_copy' => (string) ($row['pickup_window_copy'] ?? ''),
      'hospitality_benefits_summary' => (string) ($row['benefits_summary'] ?? ''),
      'parking_guidance' => (string) ($row['parking_guidance'] ?? ''),
      'bundle_capability_types' => $bundle_caps,
    ];
    return $this->stripForbiddenFromPayload($payload);
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array{0: string, 1: string}
   */
  private function wizardTitleAndSummary(string $type, array $row): array {
    return match ($type) {
      VendorProductisationStudioManager::TYPE_HOSPITALITY_PACKAGE => [
        trim((string) ($row['package_name'] ?? '')),
        trim((string) ($row['benefits_summary'] ?? '')),
      ],
      VendorProductisationStudioManager::TYPE_TIMED_COLLECTION => [
        trim((string) ($row['collection_label'] ?? '')),
        trim((string) ($row['collection_guidance'] ?? '')),
      ],
      VendorProductisationStudioManager::TYPE_PARKING_ADDON => [
        trim((string) ($row['access_timing_copy'] ?? '')) !== ''
          ? trim((string) ($row['access_timing_copy'] ?? ''))
          : (string) $this->translation->translate('Parking add-on'),
        trim((string) ($row['parking_guidance'] ?? '')),
      ],
      VendorProductisationStudioManager::TYPE_OPERATIONAL_BUNDLE => [
        trim((string) ($row['bundle_label'] ?? '')),
        trim((string) ($row['customer_summary'] ?? '')),
      ],
      default => [
        trim((string) ($row['title'] ?? '')),
        trim((string) ($row['customer_summary'] ?? '')),
      ],
    };
  }

  /**
   * @param array<string, mixed> $row
   */
  private function extractWizardCommerceProductId(array $row): int {
    $row = $this->flattenWizardFormRow($row);
    $n = (int) ($row['commerce_product_id'] ?? 0);
    if ($n > 0) {
      return $n;
    }
    $ac = $row['commerce_product'] ?? '';
    if (is_array($ac) && isset($ac['target_id'])) {
      return (int) $ac['target_id'];
    }
    return 0;
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>
   */
  private function flattenWizardFormRow(array $row): array {
    $link = is_array($row['wizard_link'] ?? NULL) ? $row['wizard_link'] : [];
    $create = is_array($row['wizard_create'] ?? NULL) ? $row['wizard_create'] : [];
    return array_merge($row, $link, $create);
  }

  /**
   * @param array<string, mixed> $payload
   *
   * @return array<string, mixed>
   */
  public function stripForbiddenFromPayload(array $payload): array {
    return $this->stripForbiddenRecursive($payload);
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
      if (!is_string($key) || in_array($key, self::FORBIDDEN_CREATION_KEYS, TRUE)) {
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

  /**
   * @return array<string, mixed>
   */
  private function buildOperationalMetadata(string $type, array $payload, string $summary): array {
    $fulfillment = $this->sanitizePlainText((string) ($payload['fulfillment_mode'] ?? 'collect'), 64);
    $reservation = $this->sanitizePlainText((string) ($payload['reservation_mode'] ?? 'operational_projection'), 64);
    $visibility = $this->normalizeVisibility((string) ($payload['customer_visibility'] ?? 'visible'));
    $pickup = $this->sanitizePlainText((string) ($payload['pickup_mode'] ?? ''), 64);
    $timed_copy = $this->sanitizePlainText((string) ($payload['timed_collection_window_copy'] ?? ''), 400);
    $hospitality = $this->sanitizePlainText((string) ($payload['hospitality_benefits_summary'] ?? ''), 600);
    $parking = $this->sanitizePlainText((string) ($payload['parking_guidance'] ?? ''), 600);
    $bundle_types = is_array($payload['bundle_capability_types'] ?? NULL) ? $payload['bundle_capability_types'] : [];

    $product_type_token = match ($type) {
      VendorProductisationStudioManager::TYPE_HOSPITALITY_PACKAGE => 'hospitality_package',
      VendorProductisationStudioManager::TYPE_TIMED_COLLECTION => 'timed_collection_product',
      VendorProductisationStudioManager::TYPE_OPERATIONAL_BUNDLE => 'operational_bundle',
      VendorProductisationStudioManager::TYPE_PARKING_ADDON => 'merch_pickup',
      default => 'merch_pickup',
    };

    $chips = [];
    if ($timed_copy !== '') {
      $chips[] = ['label' => $timed_copy, 'tone' => 'info'];
    }
    if ($hospitality !== '') {
      $chips[] = ['label' => $hospitality, 'tone' => 'info'];
    }
    if ($parking !== '') {
      $chips[] = ['label' => $parking, 'tone' => 'info'];
    }
    if ($type === VendorProductisationStudioManager::TYPE_OPERATIONAL_BUNDLE && $bundle_types !== []) {
      $chips[] = ['label' => implode(', ', array_map('strval', $bundle_types)), 'tone' => 'muted'];
    }

    return [
      'operational_product_type' => $product_type_token,
      'fulfillment_mode' => $fulfillment !== '' ? $fulfillment : 'collect',
      'reservation_mode' => $reservation !== '' ? $reservation : 'operational_projection',
      'pickup_mode' => $pickup !== '' ? $pickup : 'counter',
      'readiness_mode' => 'authoring',
      'continuity_mode' => 'collect_after_entry',
      'capability_reference' => $type,
      'customer_visibility' => $visibility,
      'collection_rules' => [],
      'hospitality_rules' => [],
      'operational_summary' => $summary,
      'operational_chips' => $chips,
    ];
  }

  /**
   * @return array{product_type: string, variation_type: string}
   */
  private function mapProductisationTypeToCommerceBundles(string $type): array {
    return match ($type) {
      VendorProductisationStudioManager::TYPE_HOSPITALITY_PACKAGE => [
        'product_type' => 'hospitality_package',
        'variation_type' => 'hospitality_package_var',
      ],
      VendorProductisationStudioManager::TYPE_TIMED_COLLECTION => [
        'product_type' => 'timed_collection_product',
        'variation_type' => 'timed_collection_var',
      ],
      VendorProductisationStudioManager::TYPE_OPERATIONAL_BUNDLE => [
        'product_type' => 'operational_bundle',
        'variation_type' => 'operational_bundle_var',
      ],
      VendorProductisationStudioManager::TYPE_PARKING_ADDON => [
        'product_type' => 'operational_merchandise',
        'variation_type' => 'operational_merchandise_var',
      ],
      default => [
        'product_type' => 'operational_merchandise',
        'variation_type' => 'operational_merchandise_var',
      ],
    };
  }

  private function normalizeProductisationType(string $raw): string {
    $t = strtolower(trim($raw));
    return in_array($t, VendorProductisationStudioManager::PRODUCTISATION_TYPES, TRUE) ? $t : '';
  }

  private function normalizeCurrencyCode(string $code): string {
    return strtoupper(preg_replace('/[^A-Za-z]/', '', $code) ?? '');
  }

  private function normalizePriceAmount(mixed $raw): ?string {
    if ($raw === NULL || $raw === '') {
      return NULL;
    }
    if (is_int($raw) || is_float($raw)) {
      $raw = (string) $raw;
    }
    if (!is_string($raw)) {
      return NULL;
    }
    $raw = trim($raw);
    if ($raw === '' || !is_numeric($raw)) {
      return NULL;
    }
    if ((float) $raw < 0) {
      return NULL;
    }
    return number_format((float) $raw, 2, '.', '');
  }

  private function normalizeVisibility(string $raw): string {
    $v = strtolower(trim($raw));
    return in_array($v, ['visible', 'hidden', 'after_purchase'], TRUE) ? $v : 'visible';
  }

  private function sanitizePlainText(string $text, int $max): string {
    $text = trim(strip_tags($text));
    if (mb_strlen($text, 'UTF-8') > $max) {
      return mb_substr($text, 0, $max - 1, 'UTF-8') . '…';
    }
    return $text;
  }

  private function generateSku(NodeInterface $event, string $type): string {
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $type) ?: 'op';
    return 'mel-op-' . (int) $event->id() . '-' . $slug . '-' . substr(sha1((string) microtime(TRUE)), 0, 8);
  }

  /**
   * @return array<string, mixed>
   */
  private function resolveExistingOperationalProduct(NodeInterface $event, int $product_id, int $store_id): array {
    $product = $this->entityTypeManager->getStorage('commerce_product')->load($product_id);
    if (!$product instanceof ProductInterface) {
      throw new \InvalidArgumentException('Existing product was not found.');
    }
    if (!in_array($product->bundle(), OperationalMerchandiseManager::OPERATIONAL_PRODUCT_BUNDLES, TRUE)) {
      throw new \InvalidArgumentException('Existing product is not an operational Commerce bundle.');
    }
    $linked = $this->operationalMerchandiseManager->normalizeEventMerchandiseAuthoring([
      'linked_products' => [['product_id' => $product_id, 'role' => 'merch_pickup']],
    ], $event)['linked_products'] ?? [];
    if ($linked === []) {
      throw new \InvalidArgumentException('Existing product is not scoped to this event.');
    }
    if (!$this->productInStore($product, $store_id)) {
      throw new \InvalidArgumentException('Existing product is not available in the resolved store.');
    }
    $presentation = $this->operationalMerchandiseManager->buildCustomerSafeProductPresentation(
      $this->operationalMerchandiseManager->normalizeProductFieldFromEntity($product),
    );
    $vars = [];
    foreach ($product->getVariations() as $v) {
      $vars[] = (int) $v->id();
    }
    return [
      'product_id' => $product_id,
      'variation_ids' => $vars,
      'store_id' => $store_id,
      'customer_summary' => (string) ($presentation['operational_summary'] ?? ''),
      'product_bundle' => $product->bundle(),
    ];
  }

  private function productInStore(ProductInterface $product, int $store_id): bool {
    if (!$product->hasField('stores') || $product->get('stores')->isEmpty()) {
      return FALSE;
    }
    foreach ($product->get('stores')->getValue() as $item) {
      if ((int) ($item['target_id'] ?? 0) === $store_id) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
