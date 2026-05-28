<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;
/**
 * Read-only vendor summaries of operational add-on purchases for an event.
 *
 * Order discovery and state filtering align with
 * {@see \Drupal\myeventlane_vendor\Controller\VendorEventOrdersController}.
 * Line grouping uses {@see OperationalPurchaseCompositionManager::composeForOrder()}
 * without re-classifying bundles.
 *
 * Callers must enforce event management access before invoking
 * {@see self::buildForEvent()} or {@see self::buildForOrder()}.
 */
final class VendorOperationalAddonOrderBuilder {

  use StringTranslationTrait;

  public const CONTRACT_FLAG = 'mel_vendor_operational_addon_orders';

  /**
   * @var list<string>
   */
  public const FORBIDDEN_VENDOR_ORDER_KEYS = [
    'qr_payload',
    'replay_token',
    'scanner_action',
    'scanner_secret',
    'device_fingerprint',
    'payload_sha256',
    'inventory_quantity',
    'stock_count',
    'warehouse_ids',
    'shipment_provider',
    'internal_cost',
    'vendor_margin',
    'private_notes',
    'attendee_answers',
    'raw_email',
    'email',
    'mail',
    'billing_profile',
    'customer_email',
  ];

  /**
   * Conservative max-age when order entity tags are impractical to enumerate.
   */
  public const VENDOR_ADDON_ORDERS_MAX_AGE = 300;

  /**
   * Order states included in vendor order surfaces.
   *
   * @see \Drupal\myeventlane_vendor\Controller\VendorEventOrdersController::INCLUDED_STATES
   *
   * @var list<string>
   */
  private const INCLUDED_STATES = [
    'completed',
    'partially_refunded',
    'refunded',
    'placed',
    'fulfilled',
    'fulfillment',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OperationalPurchaseCompositionManager $purchaseCompositionManager,
    private readonly EventOperationalAddonBuilder $eventOperationalAddonBuilder,
    private readonly OperationalExtraVisualPresenter $visualPresenter,
    private readonly DateFormatterInterface $dateFormatter,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Whether the event workspace should surface the add-on orders tab/link.
   *
   * True when linked operational catalog exists or at least one qualifying
   * order contains operational add-on lines for this event.
   */
  public function shouldSurfaceVendorAddonsTab(NodeInterface $event): bool {
    if ($event->bundle() !== 'event') {
      return FALSE;
    }
    if ($this->eventHasConfiguredOperationalCatalog($event)) {
      return TRUE;
    }
    return $this->eventHasPurchasedOperationalAddons($event);
  }

  public function eventHasConfiguredOperationalCatalog(NodeInterface $event): bool {
    $built = $this->eventOperationalAddonBuilder->buildForEvent($event);
    return ($built['addons'] ?? []) !== [];
  }

  /**
   * Cache tags for vendor add-on order surfaces (event, catalog products, orders).
   *
   * Order tags are collected only for qualifying orders with operational lines.
   * When many orders exist, callers should also set
   * {@see self::VENDOR_ADDON_ORDERS_MAX_AGE} as a conservative bound.
   *
   * @return list<string>
   */
  public function collectCacheTagsForEvent(NodeInterface $event): array {
    $eventId = (int) $event->id();
    if ($event->bundle() !== 'event' || $eventId < 1) {
      return [];
    }

    $tags = $event->getCacheTags();
    $built = $this->eventOperationalAddonBuilder->buildForEvent($event);
    foreach ($built['product_ids'] ?? [] as $pid) {
      $tags[] = 'commerce_product:' . (int) $pid;
    }

    foreach ($this->loadOrdersForEvent($event) as $order) {
      if (!$this->orderHasOperationalAddons($order, $eventId)) {
        continue;
      }
      $tags = array_merge($tags, $order->getCacheTags());
    }

    return array_values(array_unique($tags));
  }

  /**
   * Lightweight signal: any placed order for this event includes operational
   * Commerce lines (by product bundle), without building full composition.
   */
  public function eventHasPurchasedOperationalAddons(NodeInterface $event): bool {
    $eventId = (int) $event->id();
    foreach ($this->loadOrdersForEvent($event) as $order) {
      if ($this->orderHasOperationalAddons($order, $eventId)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  public function orderHasOperationalAddons(OrderInterface $order, ?int $eventId = NULL): bool {
    $composition = $this->purchaseCompositionManager->composeForOrder($order);
    $groups = is_array($composition['groups'] ?? NULL) ? $composition['groups'] : [];
    foreach (['merchandise', 'hospitality', 'timed_collection', 'bundles', 'parking'] as $key) {
      $lines = is_array($groups[$key] ?? NULL) ? $groups[$key] : [];
      foreach ($lines as $line) {
        if (!is_array($line)) {
          continue;
        }
        if ($eventId !== NULL && !$this->compositionLineIsForEvent($line, $eventId, $order)) {
          continue;
        }
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @return array<string, mixed>
   *   Render-safe document for mel_vendor_operational_addon_orders.
   */
  public function buildForEvent(NodeInterface $event, AccountInterface $account): array {
    unset($account);
    $eventId = (int) $event->id();
    if ($event->bundle() !== 'event' || $eventId < 1) {
      return $this->emptyDocument(TRUE);
    }

    $orders = $this->loadOrdersForEvent($event);
    $rows = [];
    $groupTotals = [
      'merchandise' => 0,
      'hospitality' => 0,
      'timed_collection' => 0,
      'bundles' => 0,
      'parking' => 0,
    ];
    $itemTotal = 0;

    foreach ($orders as $order) {
      if (!$this->orderHasOperationalAddons($order, $eventId)) {
        continue;
      }
      $row = $this->buildOrderRow($order, $eventId);
      if ($row === NULL) {
        continue;
      }
      $rows[] = $row;
      foreach ($groupTotals as $gk => $_) {
        $groupTotals[$gk] += (int) ($row['group_counts'][$gk] ?? 0);
      }
      $itemTotal += (int) ($row['addon_item_count'] ?? 0);
    }

    $doc = [
      self::CONTRACT_FLAG => TRUE,
      'schema_version' => 1,
      'empty' => $rows === [],
      'page_title' => (string) $this->t('Add-on orders'),
      'page_intro' => (string) $this->t('Merch, hospitality, timed collection and other add-ons purchased for this event.'),
      'vendor_prepare_hint' => (string) $this->t('Prepare these add-ons before check-in. This list is read-only — collection and scanning tools are not available here yet.'),
      'empty_message' => (string) $this->t('No add-ons have been purchased for this event yet.'),
      'orders' => $rows,
      'totals' => [
        'order_count' => count($rows),
        'item_count' => $itemTotal,
        'group_count' => count(array_filter($groupTotals, static fn(int $c): bool => $c > 0)),
        'group_counts' => $groupTotals,
      ],
    ];

    return $this->stripForbiddenRecursive($doc);
  }

  /**
   * @return array<string, mixed>
   */
  public function buildForOrder(OrderInterface $order, AccountInterface $account, int $eventId): array {
    unset($account);
    if (!$this->orderHasOperationalAddons($order, $eventId)) {
      return $this->emptyDocument(TRUE);
    }
    $row = $this->buildOrderRow($order, $eventId);
    if ($row === NULL) {
      return $this->emptyDocument(TRUE);
    }
    $doc = [
      self::CONTRACT_FLAG => TRUE,
      'schema_version' => 1,
      'empty' => FALSE,
      'page_title' => (string) $this->t('Add-on orders'),
      'page_intro' => (string) $this->t('Operational add-ons on this order for your event.'),
      'vendor_prepare_hint' => (string) $this->t('Prepare these add-ons before check-in. This list is read-only — collection and scanning tools are not available here yet.'),
      'empty_message' => (string) $this->t('No add-ons on this order for this event.'),
      'orders' => [$row],
      'totals' => [
        'order_count' => 1,
        'item_count' => (int) ($row['addon_item_count'] ?? 0),
        'group_count' => count(array_filter($row['group_counts'] ?? [])),
        'group_counts' => $row['group_counts'] ?? [],
      ],
    ];
    return $this->stripForbiddenRecursive($doc);
  }

  /**
   * @return array<string, mixed>
   */
  private function emptyDocument(bool $empty): array {
    return $this->stripForbiddenRecursive([
      self::CONTRACT_FLAG => TRUE,
      'schema_version' => 1,
      'empty' => $empty,
      'page_title' => (string) $this->t('Add-on orders'),
      'page_intro' => (string) $this->t('Merch, hospitality, timed collection and other add-ons purchased for this event.'),
      'vendor_prepare_hint' => (string) $this->t('Prepare these add-ons before check-in. This list is read-only — collection and scanning tools are not available here yet.'),
      'empty_message' => (string) $this->t('No add-ons have been purchased for this event yet.'),
      'orders' => [],
      'totals' => [
        'order_count' => 0,
        'item_count' => 0,
        'group_count' => 0,
        'group_counts' => [
          'merchandise' => 0,
          'hospitality' => 0,
          'timed_collection' => 0,
          'bundles' => 0,
          'parking' => 0,
        ],
      ],
    ]);
  }

  /**
   * @return list<OrderInterface>
   */
  private function loadOrdersForEvent(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $orderStorage = $this->entityTypeManager->getStorage('commerce_order');

    $storeId = $this->getEventStoreId($event);
    if ($storeId === NULL) {
      $defaultStore = $this->entityTypeManager->getStorage('commerce_store')->loadDefault();
      $storeId = $defaultStore ? (int) $defaultStore->id() : NULL;
    }
    $allowedStoreIds = $storeId !== NULL ? [$storeId] : [];

    $orderItemIds = $orderItemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_target_event', $eventId)
      ->execute();

    $orderIds = [];
    $foundViaEventLink = FALSE;
    if (!empty($orderItemIds)) {
      $items = $orderItemStorage->loadMultiple($orderItemIds);
      foreach ($items as $item) {
        if ($item instanceof OrderItemInterface && $item->getOrderId()) {
          $orderIds[(int) $item->getOrderId()] = TRUE;
        }
      }
      $orderIds = array_keys($orderIds);
      $foundViaEventLink = $orderIds !== [];
    }

    if (empty($orderIds)) {
      $orderIds = $this->findOrderIdsByVariationEvent($orderItemStorage, $eventId);
      $foundViaEventLink = $orderIds !== [];
    }

    if (empty($orderIds) && $storeId !== NULL) {
      $orderIds = $this->findOrderIdsByStoreAndState($orderStorage, $storeId);
      $orderIds = $this->filterOrderIdsHavingEventItems($orderStorage, $orderIds, $eventId);
      if (empty($orderIds)) {
        $defaultStore = $this->entityTypeManager->getStorage('commerce_store')->loadDefault();
        if ($defaultStore && (int) $defaultStore->id() !== $storeId) {
          $orderIds = $this->findOrderIdsByStoreAndState($orderStorage, (int) $defaultStore->id());
          $orderIds = $this->filterOrderIdsHavingEventItems($orderStorage, $orderIds, $eventId);
          if (!empty($orderIds)) {
            $allowedStoreIds = [$storeId, (int) $defaultStore->id()];
          }
        }
      }
    }

    if (empty($orderIds)) {
      return [];
    }

    $orders = $orderStorage->loadMultiple($orderIds);
    $filtered = [];
    foreach ($orders as $order) {
      if (!$order instanceof OrderInterface) {
        continue;
      }
      if (!$foundViaEventLink && $allowedStoreIds !== [] && !in_array((int) $order->getStoreId(), $allowedStoreIds, TRUE)) {
        continue;
      }
      $state = $order->getState()->getId();
      if (!in_array($state, self::INCLUDED_STATES, TRUE)) {
        continue;
      }
      $hasEventItem = FALSE;
      foreach ($order->getItems() as $it) {
        if ($this->orderItemIsForEvent($it, $eventId)) {
          $hasEventItem = TRUE;
          break;
        }
      }
      if (!$hasEventItem) {
        continue;
      }
      $filtered[] = $order;
    }

    usort($filtered, static function (OrderInterface $a, OrderInterface $b): int {
      $ta = $a->getPlacedTime() ?? 0;
      $tb = $b->getPlacedTime() ?? 0;
      return $tb <=> $ta;
    });

    return $filtered;
  }

  /**
   * @return array<string, mixed>|null
   */
  private function buildOrderRow(OrderInterface $order, int $eventId): ?array {
    $composition = $this->purchaseCompositionManager->composeForOrder($order);
    $groups = is_array($composition['groups'] ?? NULL) ? $composition['groups'] : [];
    $outGroups = [];
    $groupCounts = [
      'merchandise' => 0,
      'hospitality' => 0,
      'timed_collection' => 0,
      'bundles' => 0,
      'parking' => 0,
    ];
    $addonItemCount = 0;

    foreach (['merchandise', 'hospitality', 'timed_collection', 'bundles', 'parking'] as $gkey) {
      $lines = is_array($groups[$gkey] ?? NULL) ? $groups[$gkey] : [];
      $vendorLines = [];
      foreach ($lines as $line) {
        if (!is_array($line) || !$this->compositionLineIsForEvent($line, $eventId, $order)) {
          continue;
        }
        $vendorLine = $this->buildVendorLine($line, $order);
        if ($vendorLine === NULL) {
          continue;
        }
        $vendorLines[] = $vendorLine;
        $qty = (int) ($vendorLine['quantity'] ?? 0);
        $addonItemCount += max(1, $qty);
        $groupCounts[$gkey]++;
      }
      if ($vendorLines !== []) {
        $qtySum = 0;
        foreach ($vendorLines as $vl) {
          $qtySum += (int) ($vl['quantity'] ?? 0);
        }
        $outGroups[$gkey] = [
          'group_key' => $gkey,
          'label' => $this->groupLabel($gkey),
          'lines' => $vendorLines,
          'item_count' => count($vendorLines),
          'quantity' => $qtySum,
        ];
      }
    }

    if ($outGroups === []) {
      return NULL;
    }

    $placed = $order->getPlacedTime();
    $placedTs = $placed ? (int) $placed : 0;
    $placedDisplay = $placedTs > 0 ? $this->dateFormatter->format($placedTs, 'medium') : '';

    $customerLabel = $this->buildCustomerDisplayLabel($order);

    return [
      'order_id' => (int) $order->id(),
      'order_number' => (string) ($order->getOrderNumber() ?: ('#' . $order->id())),
      'placed_timestamp' => $placedTs,
      'placed_display' => $placedDisplay,
      'customer_display_label' => $customerLabel,
      'groups' => $outGroups,
      'group_counts' => $groupCounts,
      'addon_item_count' => $addonItemCount,
    ];
  }

  /**
   * @param array<string, mixed> $line
   *   Composition line from {@see OperationalPurchaseCompositionManager}.
   *
   * @return array<string, mixed>|null
   */
  private function buildVendorLine(array $line, OrderInterface $order): ?array {
    $oid = (int) ($line['order_item_id'] ?? 0);
    if ($oid < 1) {
      return NULL;
    }
    $item = NULL;
    foreach ($order->getItems() as $candidate) {
      if ((int) $candidate->id() === $oid) {
        $item = $candidate;
        break;
      }
    }
    if (!$item instanceof OrderItemInterface) {
      return NULL;
    }

    $pres = is_array($line['presentation'] ?? NULL) ? $line['presentation'] : [];
    $pres = $this->stripForbiddenRecursive($pres);

    $pe = $item->getPurchasedEntity();
    $variationLabel = $pe instanceof ProductVariationInterface ? $pe->label() : '';
    $productLabel = '';
    $product = NULL;
    $thumbnail_url = '';
    $thumbnail_alt = '';
    $pickup_note = '';
    $short_description = '';
    $size_label = '';
    if ($pe instanceof ProductVariationInterface) {
      $product = $pe->getProduct();
      $productLabel = $product ? $product->label() : '';
      $size = $this->visualPresenter->resolveVariationSize($pe);
      $size_label = $size['size_label'] !== ''
        ? $size['size_label']
        : $this->visualPresenter->meaningfulVariationLabel($variationLabel, $productLabel);
      if ($product instanceof ProductInterface) {
        $payload = is_array($line['presentation'] ?? NULL) ? $line['presentation'] : [];
        $visual = $this->visualPresenter->buildProductVisualDocument($product, $payload);
        $thumb = $this->visualPresenter->buildPrimaryThumbnailForProduct($product);
        $thumbnail_url = (string) ($thumb['url'] ?? '');
        $thumbnail_alt = (string) ($thumb['alt'] ?? $productLabel);
        $pickup_note = (string) ($visual['pickup_note'] ?? '');
        $short_description = (string) ($visual['short_description'] ?? '');
      }
    }

    $chips = [];
    foreach (is_array($pres['operational_chips'] ?? NULL) ? $pres['operational_chips'] : [] as $chip) {
      if (!is_array($chip)) {
        continue;
      }
      $label = trim((string) ($chip['label'] ?? ''));
      if ($label === '') {
        continue;
      }
      $tone = strtolower(trim((string) ($chip['tone'] ?? 'muted')));
      if (!preg_match('/^[a-z0-9_-]{1,32}$/', $tone)) {
        $tone = 'muted';
      }
      $chips[] = ['label' => $label, 'tone' => $tone];
    }

    return [
      'order_item_id' => $oid,
      'quantity' => (int) $item->getQuantity(),
      'product_label' => $productLabel,
      'variation_label' => $variationLabel,
      'size_label' => $size_label,
      'thumbnail_url' => $thumbnail_url,
      'thumbnail_alt' => $thumbnail_alt,
      'pickup_note' => $pickup_note,
      'short_description' => $short_description !== '' ? $short_description : trim((string) ($pres['operational_summary'] ?? '')),
      'operational_summary' => trim((string) ($pres['operational_summary'] ?? '')),
      'payment_status_label' => $this->paymentStatusLabel($order),
      'customer_visibility' => trim((string) ($pres['customer_visibility'] ?? '')),
      'chips' => $chips,
    ];
  }

  /**
   * Privacy-safe purchaser label (no raw email; aligns with vendor orders name).
   */
  private function buildCustomerDisplayLabel(OrderInterface $order): string {
    $customer = $order->getCustomer();
    if ($customer && !$customer->isAnonymous()) {
      $name = trim($customer->getDisplayName());
      if ($name !== '') {
        return $name;
      }
      $uid = (int) $customer->id();
      if ($uid > 0) {
        return (string) $this->t('Customer #@id', ['@id' => (string) $uid]);
      }
    }
    return (string) $this->t('Guest customer');
  }

  private function paymentStatusLabel(OrderInterface $order): string {
    $state = $order->getState()->getId();
    return match ($state) {
      'completed', 'fulfilled', 'fulfillment' => (string) $this->t('Paid'),
      'placed' => (string) $this->t('Payment received'),
      'refunded' => (string) $this->t('Refunded'),
      'partially_refunded' => (string) $this->t('Partially refunded'),
      default => (string) $this->t('In progress'),
    };
  }

  private function groupLabel(string $groupKey): string {
    return match ($groupKey) {
      'merchandise' => (string) $this->t('Merch & pickup'),
      'hospitality' => (string) $this->t('Hospitality'),
      'timed_collection' => (string) $this->t('Timed collection'),
      'bundles' => (string) $this->t('Packages'),
      'parking' => (string) $this->t('Parking & access'),
      default => (string) $this->t('Add-ons'),
    };
  }

  /**
   * @param array<string, mixed> $line
   */
  private function compositionLineIsForEvent(array $line, int $eventId, OrderInterface $order): bool {
    $oid = (int) ($line['order_item_id'] ?? 0);
    if ($oid < 1) {
      return FALSE;
    }
    foreach ($order->getItems() as $item) {
      if ((int) $item->id() === $oid) {
        return $this->orderItemIsForEvent($item, $eventId);
      }
    }
    return FALSE;
  }

  private function orderItemIsForEvent(OrderItemInterface $item, int $eventId): bool {
    if ($item->bundle() === 'boost') {
      return FALSE;
    }
    if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
      if ((int) $item->get('field_target_event')->target_id === $eventId) {
        return TRUE;
      }
    }
    $var = $item->getPurchasedEntity();
    if ($var) {
      if ($var->hasField('field_event') && !$var->get('field_event')->isEmpty()) {
        if ((int) $var->get('field_event')->target_id === $eventId) {
          return TRUE;
        }
      }
      if ($var->hasField('field_event_ref') && !$var->get('field_event_ref')->isEmpty()) {
        if ((int) $var->get('field_event_ref')->target_id === $eventId) {
          return TRUE;
        }
      }
      try {
        $product = $var->getProduct();
        if ($product && $product->hasField('field_event') && !$product->get('field_event')->isEmpty()) {
          if ((int) $product->get('field_event')->target_id === $eventId) {
            return TRUE;
          }
        }
      }
      catch (\Throwable) {
      }
    }
    return FALSE;
  }

  private function getEventStoreId(NodeInterface $event): ?int {
    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return NULL;
    }
    $product = $event->get('field_product_target')->entity;
    if (!$product) {
      return NULL;
    }
    $stores = $product->getStores();
    if ($stores === []) {
      return NULL;
    }
    return (int) $stores[0]->id();
  }

  /**
   * @param \Drupal\Core\Entity\EntityStorageInterface $orderItemStorage
   * @return int[]
   */
  private function findOrderIdsByVariationEvent($orderItemStorage, int $eventId): array {
    $varStorage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $vids = [];
    $ids = $varStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_event', $eventId)
      ->execute();
    if ($ids) {
      $vids = array_merge($vids, array_map('intval', (array) $ids));
    }
    try {
      $ids2 = $varStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_event_ref', $eventId)
        ->execute();
      if ($ids2) {
        $vids = array_merge($vids, array_map('intval', (array) $ids2));
      }
    }
    catch (\Throwable) {
    }
    $vids = array_values(array_unique(array_filter($vids)));
    if ($vids === []) {
      return [];
    }
    $itemIds = $orderItemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('purchased_entity', $vids, 'IN')
      ->execute();
    if (empty($itemIds)) {
      return [];
    }
    $items = $orderItemStorage->loadMultiple($itemIds);
    $orderIds = [];
    foreach ($items as $item) {
      if ($item instanceof OrderItemInterface && $item->getOrderId()) {
        $orderIds[(int) $item->getOrderId()] = TRUE;
      }
    }
    return array_keys($orderIds);
  }

  /**
   * @return int[]
   */
  private function findOrderIdsByStoreAndState($orderStorage, int $storeId): array {
    $ids = $orderStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('store_id', $storeId)
      ->condition('state', self::INCLUDED_STATES, 'IN')
      ->execute();
    return array_map('intval', (array) $ids);
  }

  /**
   * @param int[] $orderIds
   *
   * @return int[]
   */
  private function filterOrderIdsHavingEventItems($orderStorage, array $orderIds, int $eventId): array {
    $out = [];
    foreach ($orderStorage->loadMultiple($orderIds) as $order) {
      if (!$order instanceof OrderInterface) {
        continue;
      }
      foreach ($order->getItems() as $item) {
        if ($this->orderItemIsForEvent($item, $eventId)) {
          $out[] = (int) $order->id();
          break;
        }
      }
    }
    return $out;
  }

  /**
   * @param array<int|string, mixed> $data
   *
   * @return array<int|string, mixed>
   */
  public function stripForbiddenRecursive(array $data): array {
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
      if (!is_string($key) || in_array($key, self::FORBIDDEN_VENDOR_ORDER_KEYS, TRUE)) {
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
