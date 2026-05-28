<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product as CommerceProduct;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_commerce\Service\OperationalExtraVisualPresenter;
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
  /**
   * Vendor-facing extra type keys (UI) mapped internally to productisation types.
   *
   * @var array<string, string>
   */
  public const VENDOR_EXTRA_TYPE_MAP = [
    'merchandise' => VendorProductisationStudioManager::TYPE_MERCHANDISE,
    'parking' => VendorProductisationStudioManager::TYPE_PARKING_ADDON,
    'meal_package' => VendorProductisationStudioManager::TYPE_HOSPITALITY_PACKAGE,
    'camping' => VendorProductisationStudioManager::TYPE_OPERATIONAL_BUNDLE,
    'vip_extra' => VendorProductisationStudioManager::TYPE_HOSPITALITY_PACKAGE,
    'shuttle' => VendorProductisationStudioManager::TYPE_TIMED_COLLECTION,
    'other' => VendorProductisationStudioManager::TYPE_OPERATIONAL_BUNDLE,
    // Legacy keys (bookmarks / older Studio URLs).
    'food_drink' => VendorProductisationStudioManager::TYPE_TIMED_COLLECTION,
    'vip_hospitality' => VendorProductisationStudioManager::TYPE_HOSPITALITY_PACKAGE,
    'pickup_item' => VendorProductisationStudioManager::TYPE_TIMED_COLLECTION,
    'bundle' => VendorProductisationStudioManager::TYPE_OPERATIONAL_BUNDLE,
  ];

  /**
   * Vendor-facing merchandise extra type keys.
   *
   * @var list<string>
   */
  public const VENDOR_MERCH_EXTRA_TYPES = [
    'merchandise',
  ];

  /**
   * Vendor-facing add-on extra type keys.
   *
   * @var list<string>
   */
  public const VENDOR_ADDON_EXTRA_TYPES = [
    'parking',
    'meal_package',
    'camping',
    'vip_extra',
    'shuttle',
    'other',
  ];

  /**
   * @var list<string>
   */
  public const VENDOR_EXTRA_TYPE_KEYS = [
    ...self::VENDOR_MERCH_EXTRA_TYPES,
    ...self::VENDOR_ADDON_EXTRA_TYPES,
    'food_drink',
    'vip_hospitality',
    'pickup_item',
    'bundle',
  ];

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
  /**
   * Creates or updates an event extra Commerce product for a vendor (explicit save only).
   *
   * @param array<string, mixed> $input
   *   Keys: extra_type, title, customer_summary, pickup_note, price_amount, currency_code,
   *   sizes (list), show_on_booking (bool), product_id (optional), image_media_ids (list<int>).
   *
   * @throws \InvalidArgumentException
   */
  public function saveEventExtraForVendor(AccountInterface $account, NodeInterface $event, array $input): ProductInterface {
    $input = $this->stripForbiddenFromPayload($input);
    $product_id = (int) ($input['product_id'] ?? 0);
    if ($product_id > 0) {
      return $this->updateEventExtraForVendor($account, $event, $product_id, $input);
    }
    $payload = $this->buildEventExtraCreationPayload($event, $input);
    $created = $this->createOperationalProductForEvent($account, $event, $payload);
    $product = $this->entityTypeManager->getStorage('commerce_product')->load((int) $created['product_id']);
    if (!$product instanceof ProductInterface) {
      throw new \RuntimeException('Event extra product could not be loaded after create.');
    }
    $this->applyProductStatusFromInput($product, $input);
    $metadata = $this->operationalMerchandiseManager->normalizeProductFieldFromEntity($product);
    $metadata = $this->mergeCapacityNoteIntoMetadata($metadata, (string) ($input['capacity_note'] ?? ''));
    $metadata = $this->mergeVendorStudioStatusIntoMetadata($metadata, (string) ($input['product_status'] ?? 'draft'));
    if ($product->hasField('field_mel_operational_product')) {
      $product->set('field_mel_operational_product', json_encode(
        $this->operationalMerchandiseManager->normalizeProductFieldValue($metadata),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
      ));
    }
    $this->applyEventExtraFieldUpdates($product, $input);
    $product->save();
    return $product;
  }

  /**
   * @param array<string, mixed> $input
   *
   * @return array<string, mixed>
   */
  public function buildEventExtraCreationPayload(NodeInterface $event, array $input): array {
    $extra_type = $this->normalizeVendorExtraType((string) ($input['extra_type'] ?? ''));
    if ($extra_type === '') {
      throw new \InvalidArgumentException('A valid extra type is required.');
    }
    $productisation_type = self::VENDOR_EXTRA_TYPE_MAP[$extra_type];
    $store_id = $this->commerceLinkManager->resolveStoreIdForOperationalProductCreation($event);
    $currency = 'AUD';
    if ($store_id !== NULL && $store_id > 0) {
      $store = $this->entityTypeManager->getStorage('commerce_store')->load($store_id);
      if (is_object($store) && method_exists($store, 'getDefaultCurrencyCode')) {
        $currency = (string) $store->getDefaultCurrencyCode();
      }
    }
    $currency_in = $this->normalizeCurrencyCode((string) ($input['currency_code'] ?? $currency));
    if ($currency_in === '') {
      $currency_in = $currency;
    }
    $show = $this->resolveShowOnBooking($input);
    $pickup_mode = match ($extra_type) {
      'pickup_item', 'parking' => 'collect',
      'food_drink', 'meal_package' => 'counter',
      'camping', 'other' => 'collect',
      default => 'counter',
    };
    $fulfillment = match ($productisation_type) {
      VendorProductisationStudioManager::TYPE_HOSPITALITY_PACKAGE => 'redeem',
      VendorProductisationStudioManager::TYPE_TIMED_COLLECTION => 'collect',
      VendorProductisationStudioManager::TYPE_PARKING_ADDON => 'collect',
      default => 'collect',
    };
    $parking_guidance = $extra_type === 'parking'
      ? (string) ($input['pickup_note'] ?? $input['customer_summary'] ?? '')
      : '';
    $payload = [
      'productisation_type' => $productisation_type,
      'event_id' => (int) $event->id(),
      'title' => (string) ($input['title'] ?? ''),
      'customer_summary' => (string) ($input['customer_summary'] ?? ''),
      'price_amount' => $input['price_amount'] ?? NULL,
      'currency_code' => $currency_in,
      'customer_visibility' => $show ? 'visible' : 'hidden',
      'fulfillment_mode' => $fulfillment,
      'reservation_mode' => 'operational_projection',
      'pickup_mode' => $pickup_mode,
      'pickup_note' => (string) ($input['pickup_note'] ?? ''),
      'sizes' => $this->normalizeSizeKeys($input['sizes'] ?? []),
      'sku' => trim((string) ($input['sku'] ?? '')),
      'capacity_note' => trim((string) ($input['capacity_note'] ?? '')),
      'timed_collection_window_copy' => in_array($extra_type, ['pickup_item', 'shuttle'], TRUE)
        ? (string) ($input['pickup_note'] ?? '') : '',
      'hospitality_benefits_summary' => $productisation_type === VendorProductisationStudioManager::TYPE_HOSPITALITY_PACKAGE
        ? (string) ($input['customer_summary'] ?? '') : '',
      'parking_guidance' => $parking_guidance,
      'product_status' => strtolower(trim((string) ($input['product_status'] ?? 'draft'))),
    ];
    return $this->stripForbiddenFromPayload($payload);
  }

  /**
   * Whether a vendor extra type key is merchandise (vs add-on).
   */
  public function isMerchandiseExtraType(string $extra_type): bool {
    return in_array($this->normalizeVendorExtraType($extra_type), self::VENDOR_MERCH_EXTRA_TYPES, TRUE);
  }

  /**
   * Whether a vendor extra type key is an add-on.
   */
  public function isAddonExtraType(string $extra_type): bool {
    $key = $this->normalizeVendorExtraType($extra_type);
    return in_array($key, self::VENDOR_ADDON_EXTRA_TYPES, TRUE)
      || in_array($key, ['food_drink', 'vip_hospitality', 'pickup_item', 'bundle'], TRUE);
  }

  /**
   * @param array<string, mixed> $input
   *
   * @return list<string>
   */
  public function validateEventExtraInput(AccountInterface $account, NodeInterface $event, array $input): array {
    $input = $this->stripForbiddenFromPayload($input);
    $errors = [];
    if ($account->isAnonymous()) {
      return ['Authentication is required.'];
    }
    if (!$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $account)
      && !$account->hasPermission('administer nodes')) {
      return ['You do not have permission to manage extras for this event.'];
    }
    $product_id = (int) ($input['product_id'] ?? 0);
    if ($product_id > 0) {
      try {
        $this->assertVendorCanManageProduct($account, $event, $product_id);
      }
      catch (\InvalidArgumentException $e) {
        return [$e->getMessage()];
      }
    }
    else {
      $extra_type = $this->normalizeVendorExtraType((string) ($input['extra_type'] ?? ''));
      if ($extra_type === '') {
        $errors[] = 'Choose what kind of extra you are offering.';
      }
    }
    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '') {
      $errors[] = 'Extra name is required.';
    }
    $summary = trim((string) ($input['customer_summary'] ?? ''));
    if ($summary === '') {
      $errors[] = 'Short customer description is required.';
    }
    $amount = $this->normalizePriceAmount($input['price_amount'] ?? NULL);
    if ($amount === NULL) {
      $errors[] = 'A valid price is required.';
    }
    return $errors;
  }

  /**
   * @throws \InvalidArgumentException
   */
  public function assertVendorCanManageProduct(AccountInterface $account, NodeInterface $event, int $product_id): ProductInterface {
    if ($product_id < 1) {
      throw new \InvalidArgumentException('Invalid extra reference.');
    }
    if ($account->isAnonymous()) {
      throw new \InvalidArgumentException('Authentication is required.');
    }
    if (!$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $account)
      && !$account->hasPermission('administer nodes')) {
      throw new \InvalidArgumentException('You do not have permission to manage extras for this event.');
    }
    $product = $this->entityTypeManager->getStorage('commerce_product')->load($product_id);
    if (!$product instanceof ProductInterface) {
      throw new \InvalidArgumentException('Extra was not found.');
    }
    if (!in_array($product->bundle(), OperationalMerchandiseManager::OPERATIONAL_PRODUCT_BUNDLES, TRUE)) {
      throw new \InvalidArgumentException('This item is not an event extra.');
    }
    if ($product->bundle() === 'ticket') {
      throw new \InvalidArgumentException('Ticket products cannot be edited here.');
    }
    if (!$product->hasField('field_event') || $product->get('field_event')->isEmpty()) {
      throw new \InvalidArgumentException('Extra is not linked to an event.');
    }
    if ((int) $product->get('field_event')->target_id !== (int) $event->id()) {
      throw new \InvalidArgumentException('Extra does not belong to this event.');
    }
    $store_id = $this->commerceLinkManager->resolveStoreIdForOperationalProductCreation($event);
    if ($store_id === NULL || !$this->productInStore($product, $store_id)) {
      throw new \InvalidArgumentException('Extra is not available in the store for this event.');
    }
    if (!$account->hasPermission('administer nodes')) {
      $vendor_store = $this->commerceLinkManager->resolveVendorStoreIdForEvent($event);
      if ($vendor_store !== NULL && $vendor_store !== $store_id) {
        throw new \InvalidArgumentException('Extra store does not match your vendor store.');
      }
    }
    return $product;
  }

  public function normalizeVendorExtraType(string $raw): string {
    $key = strtolower(trim($raw));
    return in_array($key, self::VENDOR_EXTRA_TYPE_KEYS, TRUE) ? $key : '';
  }

  /**
   * @param array<string, mixed> $input
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
    $metadata = $this->mergeVendorStudioStatusIntoMetadata($metadata, (string) ($payload['product_status'] ?? 'draft'));
    $encoded = json_encode($this->operationalMerchandiseManager->normalizeProductFieldValue($metadata), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    $pickup_note = $this->sanitizePlainText((string) ($payload['pickup_note'] ?? ''), 400);
    $visibility = $this->normalizeVisibility((string) ($payload['customer_visibility'] ?? 'visible'));
    $published = $visibility === 'visible';

    $product = CommerceProduct::create([
      'type' => $bundles['product_type'],
      'title' => $title,
      'status' => $published ? 1 : 0,
      'stores' => [$store_id],
      'uid' => (int) $account->id(),
      'field_event' => ['target_id' => (int) $event->id()],
      'field_mel_operational_product' => $encoded,
    ]);
    if ($product->hasField('field_mel_extra_short_desc')) {
      $product->set('field_mel_extra_short_desc', $summary);
    }
    if ($product->hasField('field_mel_extra_pickup_note') && $pickup_note !== '') {
      $product->set('field_mel_extra_pickup_note', $pickup_note);
    }
    $product->save();

    $price = new Price($amount, strtoupper($currency));
    $variation_ids = $this->createVariationsForProduct($product, $bundles, $payload, $title, $sku, $price);
    foreach ($variation_ids as $vid) {
      $loaded = $this->entityTypeManager->getStorage('commerce_product_variation')->load($vid);
      if ($loaded instanceof ProductVariation) {
        $product->addVariation($loaded);
      }
    }
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
      $row = $this->flattenProductisationWizardFormRow(is_array($wizardRowsByType[$type] ?? NULL) ? $wizardRowsByType[$type] : []);
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
    $row = $this->flattenProductisationWizardFormRow($row);
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
      'pickup_note' => (string) ($row['commerce_create_pickup_note'] ?? $row['pickup_note'] ?? ''),
      'sizes' => $this->normalizeSizeKeys($row['commerce_create_sizes'] ?? $row['sizes'] ?? []),
    ];
    return $this->stripForbiddenFromPayload($payload);
  }

  /**
   * @return list<int>
   */
  private function createVariationsForProduct(
    ProductInterface $product,
    array $bundles,
    array $payload,
    string $title,
    string $sku_base,
    Price $price,
  ): array {
    $size_keys = $this->normalizeSizeKeys($payload['sizes'] ?? []);
    $variation_type = $bundles['variation_type'];
    $ids = [];

    if ($size_keys !== [] && $variation_type === 'operational_merchandise_var') {
      foreach ($size_keys as $size_key) {
        $label = OperationalExtraVisualPresenter::SIZE_LABELS[$size_key] ?? strtoupper($size_key);
        $variation = ProductVariation::create([
          'type' => $variation_type,
          'sku' => $this->sanitizePlainText($sku_base . '-' . $size_key, 128),
          'title' => $title . ' — ' . $label,
          'status' => 1,
          'price' => $price,
        ]);
        if ($variation->hasField('field_mel_size')) {
          $variation->set('field_mel_size', $size_key);
        }
        $variation->save();
        $ids[] = (int) $variation->id();
      }
      return $ids;
    }

    $variation = ProductVariation::create([
      'type' => $variation_type,
      'sku' => $sku_base,
      'title' => $title,
      'status' => 1,
      'price' => $price,
    ]);
    $variation->save();
    return [(int) $variation->id()];
  }

  /**
   * @param mixed $raw
   *
   * @return list<string>
   */
  private function normalizeSizeKeys(mixed $raw): array {
    if (!is_array($raw)) {
      return [];
    }
    $out = [];
    foreach ($raw as $key => $value) {
      $candidate = is_string($key) && !is_numeric($key) ? $key : $value;
      if (!is_string($candidate) && !is_int($candidate)) {
        continue;
      }
      $size = strtolower(trim((string) $candidate));
      if ($size === '' || !isset(OperationalExtraVisualPresenter::SIZE_LABELS[$size])) {
        continue;
      }
      $out[$size] = $size;
    }
    return array_values($out);
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
    $row = $this->flattenProductisationWizardFormRow($row);
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
   * Merges nested wizard_link / wizard_create containers into one row for validation and create.
   *
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>
   */
  public function flattenProductisationWizardFormRow(array $row): array {
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
    $capacity_note = $this->sanitizePlainText((string) ($payload['capacity_note'] ?? ''), 200);
    if ($capacity_note !== '') {
      $chips[] = ['label' => (string) $this->translation->translate('Capacity note: @note', ['@note' => $capacity_note]), 'tone' => 'muted'];
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

  /**
   * @param array<string, mixed> $input
   */
  private function updateEventExtraForVendor(AccountInterface $account, NodeInterface $event, int $product_id, array $input): ProductInterface {
    $product = $this->assertVendorCanManageProduct($account, $event, $product_id);
    $errors = $this->validateEventExtraInput($account, $event, $input);
    if ($errors !== []) {
      throw new \InvalidArgumentException(implode(' ', $errors));
    }

    $title = $this->sanitizePlainText((string) ($input['title'] ?? ''), 255);
    $summary = $this->sanitizePlainText((string) ($input['customer_summary'] ?? ''), 600);
    $pickup_note = $this->sanitizePlainText((string) ($input['pickup_note'] ?? ''), 400);
    $show = $this->resolveShowOnBooking($input);
    $currency = $this->normalizeCurrencyCode((string) ($input['currency_code'] ?? ''));
    if ($currency === '') {
      $store_id = $this->commerceLinkManager->resolveStoreIdForOperationalProductCreation($event);
      if ($store_id !== NULL && $store_id > 0) {
        $store = $this->entityTypeManager->getStorage('commerce_store')->load($store_id);
        if (is_object($store) && method_exists($store, 'getDefaultCurrencyCode')) {
          $currency = (string) $store->getDefaultCurrencyCode();
        }
      }
      if ($currency === '') {
        $currency = 'AUD';
      }
    }
    $amount = (string) $this->normalizePriceAmount($input['price_amount'] ?? NULL);
    $price = new Price($amount, $currency);

    $product->setTitle($title);
    $this->applyProductStatusFromInput($product, $input);
    $show = $product->isPublished();
    if ($product->hasField('field_mel_extra_short_desc')) {
      $product->set('field_mel_extra_short_desc', $summary);
    }
    if ($product->hasField('field_mel_extra_pickup_note')) {
      $product->set('field_mel_extra_pickup_note', $pickup_note);
    }

    $metadata = $this->operationalMerchandiseManager->normalizeProductFieldFromEntity($product);
    $metadata['operational_summary'] = $summary;
    $metadata['customer_visibility'] = $show ? 'visible' : 'hidden';
    $metadata = $this->mergeCapacityNoteIntoMetadata($metadata, (string) ($input['capacity_note'] ?? ''));
    $metadata = $this->mergeVendorStudioStatusIntoMetadata($metadata, (string) ($input['product_status'] ?? ''));
    $encoded = json_encode($this->operationalMerchandiseManager->normalizeProductFieldValue($metadata), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    if ($product->hasField('field_mel_operational_product')) {
      $product->set('field_mel_operational_product', $encoded);
    }

    $this->applyEventExtraFieldUpdates($product, $input);
    $this->syncVariationsForEventExtra($product, $input, $title, $price);
    $product->save();

    $this->logger->notice('Vendor event extra @pid updated for event @eid.', [
      '@pid' => (string) $product->id(),
      '@eid' => (string) $event->id(),
    ]);

    return $product;
  }

  /**
   * @param array<string, mixed> $input
   */
  private function applyEventExtraFieldUpdates(ProductInterface $product, array $input): void {
    if (!$product->hasField('field_mel_extra_images')) {
      return;
    }
    $media_ids = $input['image_media_ids'] ?? $input['images'] ?? [];
    if (!is_array($media_ids)) {
      return;
    }
    $values = [];
    foreach ($media_ids as $mid) {
      $id = (int) $mid;
      if ($id > 0) {
        $values[] = ['target_id' => $id];
      }
    }
    $product->set('field_mel_extra_images', $values);
  }

  /**
   * @param array<string, mixed> $input
   */
  private function syncVariationsForEventExtra(ProductInterface $product, array $input, string $title, Price $price): void {
    $bundles = $this->mapProductBundleToVariationType($product->bundle());
    $variation_type = $bundles['variation_type'];
    $size_keys = $variation_type === 'operational_merchandise_var'
      ? $this->normalizeSizeKeys($input['sizes'] ?? [])
      : [];

    $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $existing = [];
    foreach ($product->getVariations() as $variation) {
      if (!$variation instanceof ProductVariationInterface) {
        continue;
      }
      $size = '';
      if ($variation->hasField('field_mel_size') && !$variation->get('field_mel_size')->isEmpty()) {
        $size = strtolower((string) $variation->get('field_mel_size')->value);
      }
      $key = $size !== '' ? $size : '_default';
      $existing[$key] = $variation;
    }

    $desired_keys = $size_keys !== [] ? $size_keys : ['_default'];
    $custom_sku = trim((string) ($input['sku'] ?? ''));
    $sku_base = $custom_sku !== ''
      ? $this->sanitizePlainText($custom_sku, 128)
      : 'mel-extra-' . (int) $product->id();

    foreach ($desired_keys as $size_key) {
      $lookup = $size_key === '_default' ? '_default' : $size_key;
      $label = $size_key === '_default'
        ? $title
        : $title . ' — ' . (OperationalExtraVisualPresenter::SIZE_LABELS[$size_key] ?? strtoupper($size_key));
      $sku = $size_key === '_default'
        ? $this->sanitizePlainText($sku_base, 128)
        : $this->sanitizePlainText($sku_base . '-' . $size_key, 128);

      if (isset($existing[$lookup])) {
        $variation = $existing[$lookup];
        $variation->setTitle($label);
        $variation->setPrice($price);
        $variation->setPublished(TRUE);
        if ($size_key !== '_default' && $variation->hasField('field_mel_size')) {
          $variation->set('field_mel_size', $size_key);
        }
        $variation->save();
        unset($existing[$lookup]);
        continue;
      }

      $variation = ProductVariation::create([
        'type' => $variation_type,
        'sku' => $sku,
        'title' => $label,
        'status' => 1,
        'price' => $price,
      ]);
      if ($size_key !== '_default' && $variation->hasField('field_mel_size')) {
        $variation->set('field_mel_size', $size_key);
      }
      $variation->save();
      $product->addVariation($variation);
    }

    foreach ($existing as $orphan) {
      if ($orphan instanceof ProductVariationInterface && $orphan->isPublished()) {
        $orphan->setPublished(FALSE);
        $orphan->save();
        $this->logger->notice('Unpublished event extra variation @vid (size removed or consolidated).', [
          '@vid' => (string) $orphan->id(),
        ]);
      }
    }
  }

  /**
   * @return array{product_type: string, variation_type: string}
   */
  private function mapProductBundleToVariationType(string $bundle): array {
    return match ($bundle) {
      'hospitality_package' => [
        'product_type' => 'hospitality_package',
        'variation_type' => 'hospitality_package_var',
      ],
      'timed_collection_product' => [
        'product_type' => 'timed_collection_product',
        'variation_type' => 'timed_collection_var',
      ],
      'operational_bundle' => [
        'product_type' => 'operational_bundle',
        'variation_type' => 'operational_bundle_var',
      ],
      default => [
        'product_type' => 'operational_merchandise',
        'variation_type' => 'operational_merchandise_var',
      ],
    };
  }

  /**
   * @return array<string, string>
   */
  public function productStatusOptions(): array {
    return [
      'active' => (string) $this->translation->translate('Active'),
      'hidden' => (string) $this->translation->translate('Hidden'),
      'draft' => (string) $this->translation->translate('Draft'),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function emptyEditorDefaults(string $extra_type): array {
    $extra_type = $this->normalizeVendorExtraType($extra_type) ?: 'merchandise';
    return [
      'extra_type' => $extra_type,
      'title' => '',
      'customer_summary' => '',
      'pickup_note' => '',
      'price_amount' => '',
      'sku' => '',
      'capacity_note' => '',
      'product_status' => 'draft',
      'show_on_booking' => 0,
      'sizes' => [],
      'stock_quantity' => NULL,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function extractEditorDefaultsFromProduct(ProductInterface $product): array {
    $sizes = [];
    foreach ($product->getVariations() as $variation) {
      if ($variation->hasField('field_mel_size') && !$variation->get('field_mel_size')->isEmpty() && $variation->isPublished()) {
        $key = strtolower((string) $variation->get('field_mel_size')->value);
        $sizes[$key] = $key;
      }
    }
    $price = '';
    $sku = '';
    foreach ($product->getVariations() as $variation) {
      if ($variation->isPublished() && $variation->getPrice() !== NULL) {
        $price = $variation->getPrice()->getNumber();
        $sku = $variation->getSku() ?? '';
        break;
      }
    }
    if ($sku !== '' && str_contains($sku, '-')) {
      $parts = explode('-', $sku);
      if (count($parts) > 3) {
        array_pop($parts);
        $sku = implode('-', $parts);
      }
    }
    $metadata = $this->operationalMerchandiseManager->normalizeProductFieldFromEntity($product);
    $status = $this->resolveProductStatusFromEntity($product, $metadata);
    return [
      'extra_type' => $this->inferExtraTypeFromProduct($product, $metadata),
      'title' => $product->label(),
      'customer_summary' => $product->hasField('field_mel_extra_short_desc') && !$product->get('field_mel_extra_short_desc')->isEmpty()
        ? (string) $product->get('field_mel_extra_short_desc')->value : '',
      'pickup_note' => $product->hasField('field_mel_extra_pickup_note') && !$product->get('field_mel_extra_pickup_note')->isEmpty()
        ? (string) $product->get('field_mel_extra_pickup_note')->value : '',
      'price_amount' => $price,
      'sku' => $sku,
      'capacity_note' => $this->extractCapacityNoteFromMetadata($metadata),
      'product_status' => $status,
      'show_on_booking' => $product->isPublished() ? 1 : 0,
      'sizes' => $sizes,
      'stock_quantity' => NULL,
    ];
  }

  public function resolveCommerceProductBundleForExtraType(string $extra_type): string {
    $extra_type = $this->normalizeVendorExtraType($extra_type) ?: 'merchandise';
    $productisation = self::VENDOR_EXTRA_TYPE_MAP[$extra_type];
    return $this->mapProductisationTypeToCommerceBundles($productisation)['product_type'];
  }

  public function createStubProductForStudioForm(NodeInterface $event, string $extra_type): ProductInterface {
    $bundle = $this->resolveCommerceProductBundleForExtraType($extra_type);
    return CommerceProduct::create([
      'type' => $bundle,
      'title' => '',
      'field_event' => ['target_id' => (int) $event->id()],
    ]);
  }

  /**
   * @param array<string, mixed> $input
   *
   * @return list<array<string, mixed>>
   */
  public function buildVariantPreviewRows(NodeInterface $event, array $input, ?ProductInterface $product = NULL): array {
    $sizes = $this->normalizeSizeKeys($input['sizes'] ?? []);
    $sku_base = trim((string) ($input['sku'] ?? ''));
    if ($sku_base === '' && $product instanceof ProductInterface) {
      $sku_base = $this->extractBaseSkuFromProduct($product);
    }
    if ($sku_base === '') {
      $extra_type = (string) ($input['extra_type'] ?? 'merchandise');
      $sku_base = $this->generateSku($event, $extra_type);
    }
    $price = $this->normalizePriceAmount($input['price_amount'] ?? NULL) ?? '0.00';
    $capacity = trim((string) ($input['capacity_note'] ?? ''));
    $keys = $sizes !== [] ? $sizes : ['_default'];
    $rows = [];
    foreach ($keys as $key) {
      $label = $key === '_default'
        ? (string) $this->translation->translate('One size')
        : (OperationalExtraVisualPresenter::SIZE_LABELS[$key] ?? strtoupper($key));
      $sku = $key === '_default'
        ? $this->sanitizePlainText($sku_base, 128)
        : $this->sanitizePlainText($sku_base . '-' . $key, 128);
      $rows[] = [
        'size_label' => $label,
        'sku' => $sku,
        'price' => $price,
        'capacity_note' => $capacity,
        'status_label' => (string) $this->translation->translate('Created on save'),
      ];
    }
    return $rows;
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function buildVariantPreviewRowsFromProduct(ProductInterface $product): array {
    $metadata = $this->operationalMerchandiseManager->normalizeProductFieldFromEntity($product);
    $capacity = $this->extractCapacityNoteFromMetadata($metadata);
    $rows = [];
    foreach ($product->getVariations() as $variation) {
      if (!$variation instanceof ProductVariationInterface || !$variation->isPublished()) {
        continue;
      }
      $size_label = (string) $this->translation->translate('One size');
      if ($variation->hasField('field_mel_size') && !$variation->get('field_mel_size')->isEmpty()) {
        $key = strtolower((string) $variation->get('field_mel_size')->value);
        $size_label = OperationalExtraVisualPresenter::SIZE_LABELS[$key] ?? strtoupper($key);
      }
      $price = $variation->getPrice();
      $rows[] = [
        'size_label' => $size_label,
        'sku' => (string) ($variation->getSku() ?? ''),
        'price' => $price !== NULL ? $price->getNumber() : '',
        'capacity_note' => $capacity,
        'status_label' => (string) $this->translation->translate('Active'),
      ];
    }
    if ($rows === []) {
      $rows[] = [
        'size_label' => (string) $this->translation->translate('One size'),
        'sku' => '',
        'price' => '',
        'capacity_note' => $capacity,
        'status_label' => (string) $this->translation->translate('No published variations'),
      ];
    }
    return $rows;
  }

  /**
   * @param array<string, mixed> $input
   */
  public function applyProductStatusFromInput(ProductInterface $product, array $input): void {
    $status = strtolower(trim((string) ($input['product_status'] ?? '')));
    if (!array_key_exists($status, $this->productStatusOptions())) {
      $status = $this->resolveShowOnBooking($input) ? 'active' : 'hidden';
    }
    $published = $status === 'active' && $this->resolveShowOnBooking($input);
    $product->setPublished($published);
  }

  /**
   * @param array<string, mixed> $input
   */
  public function resolveShowOnBooking(array $input): bool {
    $status = strtolower(trim((string) ($input['product_status'] ?? '')));
    if ($status !== 'active') {
      return FALSE;
    }
    return !array_key_exists('show_on_booking', $input) || !empty($input['show_on_booking']);
  }

  /**
   * @param array<string, mixed> $metadata
   */
  private function extractCapacityNoteFromMetadata(array $metadata): string {
    $chips = is_array($metadata['operational_chips'] ?? NULL) ? $metadata['operational_chips'] : [];
    foreach ($chips as $chip) {
      if (!is_array($chip)) {
        continue;
      }
      $label = (string) ($chip['label'] ?? '');
      if (str_starts_with($label, 'Capacity note:')) {
        return trim(substr($label, strlen('Capacity note:')));
      }
    }
    $rules = is_array($metadata['collection_rules'] ?? NULL) ? $metadata['collection_rules'] : [];
    return trim((string) ($rules['vendor_quantity_note'] ?? ''));
  }

  /**
   * @param array<string, mixed> $metadata
   *
   * @return array<string, mixed>
   */
  private function mergeCapacityNoteIntoMetadata(array $metadata, string $capacity_note): array {
    $chips = is_array($metadata['operational_chips'] ?? NULL) ? $metadata['operational_chips'] : [];
    $filtered = [];
    foreach ($chips as $chip) {
      if (!is_array($chip)) {
        continue;
      }
      $label = (string) ($chip['label'] ?? '');
      if (!str_starts_with($label, 'Capacity note:')) {
        $filtered[] = $chip;
      }
    }
    $capacity_note = $this->sanitizePlainText($capacity_note, 200);
    if ($capacity_note !== '') {
      $filtered[] = [
        'label' => (string) $this->translation->translate('Capacity note: @note', ['@note' => $capacity_note]),
        'tone' => 'muted',
      ];
    }
    $metadata['operational_chips'] = $filtered;
    $rules = is_array($metadata['collection_rules'] ?? NULL) ? $metadata['collection_rules'] : [];
    if ($capacity_note !== '') {
      $rules['vendor_quantity_note'] = $capacity_note;
    }
    else {
      unset($rules['vendor_quantity_note']);
    }
    $metadata['collection_rules'] = $rules;
    return $metadata;
  }

  /**
   * @param array<string, mixed> $metadata
   *
   * @return array<string, mixed>
   */
  private function mergeVendorStudioStatusIntoMetadata(array $metadata, string $status): array {
    $rules = is_array($metadata['collection_rules'] ?? NULL) ? $metadata['collection_rules'] : [];
    $status = strtolower(trim($status));
    if (in_array($status, ['active', 'hidden', 'draft'], TRUE)) {
      $rules['vendor_studio_status'] = $status;
    }
    $metadata['collection_rules'] = $rules;
    return $metadata;
  }

  /**
   * @param array<string, mixed> $metadata
   */
  private function resolveProductStatusFromEntity(ProductInterface $product, array $metadata): string {
    $rules = is_array($metadata['collection_rules'] ?? NULL) ? $metadata['collection_rules'] : [];
    $stored = strtolower(trim((string) ($rules['vendor_studio_status'] ?? '')));
    if (in_array($stored, ['active', 'hidden', 'draft'], TRUE)) {
      return $stored;
    }
    return $product->isPublished() ? 'active' : 'hidden';
  }

  /**
   * @param array<string, mixed> $metadata
   */
  private function inferExtraTypeFromProduct(ProductInterface $product, array $metadata): string {
    $cap = strtolower(trim((string) ($metadata['capability_reference'] ?? '')));
    foreach (self::VENDOR_EXTRA_TYPE_MAP as $key => $ptype) {
      if ($ptype === $cap) {
        return $key;
      }
    }
    return $product->bundle() === 'operational_merchandise' ? 'merchandise' : 'other';
  }

  private function extractBaseSkuFromProduct(ProductInterface $product): string {
    foreach ($product->getVariations() as $variation) {
      if (!$variation instanceof ProductVariationInterface) {
        continue;
      }
      $sku = trim((string) ($variation->getSku() ?? ''));
      if ($sku === '') {
        continue;
      }
      if ($variation->hasField('field_mel_size') && !$variation->get('field_mel_size')->isEmpty()) {
        $size = strtolower((string) $variation->get('field_mel_size')->value);
        $suffix = '-' . $size;
        if (str_ends_with($sku, $suffix)) {
          return substr($sku, 0, -strlen($suffix));
        }
      }
      return $sku;
    }
    return '';
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
