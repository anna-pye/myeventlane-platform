<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Drupal\node\NodeInterface;

/**
 * Read-only event-scoped operational extras sales summary for vendor dashboards.
 *
 * Ticket-backed order items, boosts, and donations are excluded. Grouping uses
 * {@see OperationalPurchaseCompositionManager} plus operational product metadata
 * (never product titles).
 */
final class EventOperationalExtrasSalesSummaryBuilder {

  use StringTranslationTrait;

  /**
   * Display groups shown on Manage event (stable order).
   *
   * @var list<string>
   */
  public const DISPLAY_GROUPS = [
    'merchandise',
    'food_drink',
    'parking',
    'hospitality',
    'timed_collection',
    'bundles',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly VendorOperationalAddonOrderBuilder $vendorOperationalAddonOrderBuilder,
    private readonly OperationalPurchaseCompositionManager $purchaseCompositionManager,
    private readonly OperationalMerchandiseManager $operationalMerchandiseManager,
    private readonly EventOperationalAddonBuilder $eventOperationalAddonBuilder,
    private readonly OperationalVariationStockResolver $stockResolver,
    private readonly TicketBackedOrderItemClassifierInterface $ticketBackedClassifier,
    private readonly CurrencyFormatter $currencyFormatter,
    private readonly RouteProviderInterface $routeProvider,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds the Merch & add-on sales panel payload.
   *
   * @return array<string, mixed>
   */
  public function buildExtrasSalesPanel(NodeInterface $event): array {
    $event_id = (int) $event->id();
    if ($event_id < 1 || $event->bundle() !== 'event') {
      return $this->emptyPanel($event);
    }

    $groups = $this->emptyGroupAccumulator();
    $catalog_stock = $this->buildCatalogStockByGroup($event);
    $order_ids_with_extras = [];
    $currency_code = NULL;
    $revenue_totals = array_fill_keys(self::DISPLAY_GROUPS, 0.0);
    $products = [];

    foreach ($this->vendorOperationalAddonOrderBuilder->loadQualifyingOrdersForEvent($event) as $order) {
      if (!$this->vendorOperationalAddonOrderBuilder->orderHasOperationalAddons($order, $event_id)) {
        continue;
      }
      $composition = $this->purchaseCompositionManager->composeForOrder($order);
      $composition_groups = is_array($composition['groups'] ?? NULL) ? $composition['groups'] : [];
      $order_touched = FALSE;

      foreach (['merchandise', 'hospitality', 'timed_collection', 'bundles', 'parking'] as $composition_key) {
        $lines = is_array($composition_groups[$composition_key] ?? NULL) ? $composition_groups[$composition_key] : [];
        foreach ($lines as $line) {
          if (!is_array($line)) {
            continue;
          }
          $order_item = $this->resolveOrderItem($line, $order);
          if (!$order_item instanceof OrderItemInterface) {
            continue;
          }
          if ($this->ticketBackedClassifier->isTicketBackedOrderItem($order_item)) {
            continue;
          }
          if (!$this->orderItemIsForEvent($order_item, $event_id)) {
            continue;
          }

          $presentation = is_array($line['presentation'] ?? NULL) ? $line['presentation'] : [];
          $display_key = $this->displayCategoryFromCompositionGroup(
            $composition_key,
            strtolower((string) ($presentation['operational_product_type'] ?? '')),
          );
          if (!isset($groups[$display_key])) {
            $display_key = 'bundles';
          }

          $qty = (int) round((float) $order_item->getQuantity());
          $total_price = $order_item->getAdjustedTotalPrice();
          if ($total_price instanceof Price) {
            $currency_code = $currency_code ?? $total_price->getCurrencyCode();
            $revenue_totals[$display_key] += (float) $total_price->getNumber();
          }

          $groups[$display_key]['items_sold'] += $qty;
          $groups[$display_key]['order_ids'][(int) $order->id()] = TRUE;
          $order_touched = TRUE;

          $product_id = (int) ($line['product_id'] ?? 0);
          $product_label = $this->resolveProductLabelFromLine($line, $display_key);
          $product_key = $product_id > 0 ? 'product:' . $product_id : 'label:' . md5($product_label . ':' . $display_key);
          if (!isset($products[$product_key])) {
            $products[$product_key] = [
              'label' => $product_label,
              'category' => $this->groupLabel($display_key),
              'items_sold' => 0,
              'revenue_amount' => 0.0,
            ];
          }
          $products[$product_key]['items_sold'] += $qty;
          if ($total_price instanceof Price) {
            $products[$product_key]['revenue_amount'] += (float) $total_price->getNumber();
          }
        }
      }

      if ($order_touched) {
        $order_ids_with_extras[(int) $order->id()] = TRUE;
      }
    }

    $rows = [];
    $total_revenue_amount = 0.0;
    $total_items = 0;
    $top_category = NULL;
    $top_items = 0;

    foreach (self::DISPLAY_GROUPS as $group_key) {
      $bucket = $groups[$group_key];
      $items_sold = (int) $bucket['items_sold'];
      $revenue_amount = (float) ($revenue_totals[$group_key] ?? 0.0);
      $order_count = count($bucket['order_ids']);
      $catalog = $catalog_stock[$group_key] ?? $this->emptyCatalogStock();
      $total_revenue_amount += $revenue_amount;
      $total_items += $items_sold;

      if ($items_sold > $top_items) {
        $top_items = $items_sold;
        $top_category = $this->groupLabel($group_key);
      }

      $rows[] = [
        'key' => $group_key,
        'label' => $this->groupLabel($group_key),
        'items_sold' => $items_sold,
        'revenue' => $this->formatAmount($revenue_amount, $currency_code),
        'order_count' => $order_count,
        'stock_remaining_label' => $catalog['stock_label'],
        'stock_state' => $catalog['stock_state'],
        'has_catalog' => $catalog['has_catalog'],
        'status' => $this->resolveGroupStatus($items_sold, $catalog),
      ];
    }

    $product_rows = [];
    foreach ($products as $product) {
      if ((int) ($product['items_sold'] ?? 0) <= 0 && (float) ($product['revenue_amount'] ?? 0.0) <= 0.0) {
        continue;
      }
      $product_rows[] = [
        'label' => (string) ($product['label'] ?? ''),
        'category' => (string) ($product['category'] ?? ''),
        'items_sold' => (int) ($product['items_sold'] ?? 0),
        'revenue' => $this->formatAmount((float) ($product['revenue_amount'] ?? 0.0), $currency_code),
      ];
    }
    usort($product_rows, static function (array $a, array $b): int {
      $sold_cmp = ($b['items_sold'] ?? 0) <=> ($a['items_sold'] ?? 0);
      return $sold_cmp !== 0 ? $sold_cmp : strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });

    $addon_orders_url = NULL;
    if ($this->routeExists('myeventlane_vendor.console.event_operational_addon_orders')) {
      $addon_orders_url = Url::fromRoute('myeventlane_vendor.console.event_operational_addon_orders', ['event' => $event_id])->toString();
    }

    return [
      'has_rows' => $total_items > 0 || $this->eventHasConfiguredCatalog($event),
      'summary' => [
        'total_revenue' => $this->formatAmount($total_revenue_amount, $currency_code),
        'total_items_sold' => $total_items,
        'orders_with_extras' => count($order_ids_with_extras),
        'top_category' => $top_category ?? (string) $this->t('—'),
      ],
      'rows' => $rows,
      'product_rows' => $product_rows,
      'empty_message' => (string) $this->t('No extras sold yet. Configure merch and add-ons to start tracking sales here.'),
      'helper_copy' => (string) $this->t('Track extras sold through this event. Tickets are counted separately.'),
      'sold_order_states' => VendorOperationalAddonOrderBuilder::QUALIFYING_ORDER_STATES,
      'sold_basis' => 'operational_composition_lines',
      'actions' => [
        'view_orders' => [
          'label' => (string) $this->t('View extras orders'),
          'url' => $addon_orders_url,
          'available' => $addon_orders_url !== NULL,
        ],
        'manage_extras' => [
          'label' => (string) $this->t('Manage merch & add-ons'),
          'url' => Url::fromRoute('myeventlane_event_studio.workspace_extras', ['node' => $event_id])->toString(),
          'available' => TRUE,
        ],
      ],
      'operational_documents' => $this->buildOperationalDocumentsBlock(),
      'cache_tags' => $this->vendorOperationalAddonOrderBuilder->collectCacheTagsForEvent($event),
    ];
  }

  /**
   * Builds a themed render array for the extras sales panel.
   *
   * @return array<string, mixed>
   */
  public function buildExtrasSalesPanelRenderArray(NodeInterface $event, int $weight = 0): array {
    $panel = $this->buildExtrasSalesPanel($event);
    return [
      '#theme' => 'mel_event_studio_extras_sales_panel',
      '#panel' => $panel,
      '#weight' => $weight,
      '#cache' => [
        'tags' => $panel['cache_tags'] ?? $event->getCacheTags(),
        'contexts' => ['user'],
      ],
    ];
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  private function emptyGroupAccumulator(): array {
    $groups = [];
    foreach (self::DISPLAY_GROUPS as $key) {
      $groups[$key] = [
        'items_sold' => 0,
        'order_ids' => [],
      ];
    }
    return $groups;
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  private function buildCatalogStockByGroup(NodeInterface $event): array {
    $out = [];
    foreach (self::DISPLAY_GROUPS as $key) {
      $out[$key] = $this->emptyCatalogStock();
    }

    $built = $this->eventOperationalAddonBuilder->buildForEvent($event);
    $product_ids = $built['product_ids'] ?? [];
    if (!$product_ids) {
      return $out;
    }

    /** @var \Drupal\commerce_product\Entity\ProductInterface[] $products */
    $products = $this->entityTypeManager->getStorage('commerce_product')->loadMultiple($product_ids);
    foreach ($products as $product) {
      if (!$product instanceof ProductInterface) {
        continue;
      }
      $payload = $this->operationalMerchandiseManager->normalizeProductFieldFromEntity($product);
      $group_key = $this->displayCategoryForProduct($product, $payload);
      if (!isset($out[$group_key])) {
        $group_key = 'bundles';
      }
      $stock = $this->stockResolver->summarizeProductStock($product);
      $out[$group_key]['has_catalog'] = TRUE;
      $out[$group_key]['product_count']++;
      $out[$group_key]['stock_states'][] = (string) ($stock['stock_state'] ?? 'unknown');
      if ($stock['total_stock'] === NULL) {
        $out[$group_key]['has_unlimited'] = TRUE;
      }
      elseif (is_int($stock['total_stock'])) {
        $out[$group_key]['total_finite_stock'] += $stock['total_stock'];
      }
      if (!empty($stock['sold_out'])) {
        // Keep all_sold_out TRUE only when every product in the group is sold out.
      }
      else {
        $out[$group_key]['all_sold_out'] = FALSE;
      }
      if (!empty($stock['low_stock'])) {
        $out[$group_key]['has_low_stock'] = TRUE;
      }
    }

    foreach ($out as $key => $bucket) {
      if (!$bucket['has_catalog']) {
        continue;
      }
      if ($bucket['all_sold_out']) {
        $out[$key]['stock_state'] = 'sold_out';
        $out[$key]['stock_label'] = (string) $this->t('Sold out');
      }
      elseif ($bucket['has_unlimited']) {
        $out[$key]['stock_state'] = 'unlimited';
        $out[$key]['stock_label'] = (string) $this->t('No stock limit');
      }
      elseif ($bucket['has_low_stock']) {
        $out[$key]['stock_state'] = 'low';
        $out[$key]['stock_label'] = (string) $this->t('@count remaining', ['@count' => $bucket['total_finite_stock']]);
      }
      else {
        $out[$key]['stock_state'] = 'finite';
        $out[$key]['stock_label'] = (string) $this->t('@count remaining', ['@count' => $bucket['total_finite_stock']]);
      }
    }

    return $out;
  }

  /**
   * @return array{has_catalog: bool, product_count: int, stock_state: string, stock_label: string, total_finite_stock: int, has_unlimited: bool, all_sold_out: bool, has_low_stock: bool, stock_states: list<string>}
   */
  private function emptyCatalogStock(): array {
    return [
      'has_catalog' => FALSE,
      'product_count' => 0,
      'stock_state' => 'unknown',
      'stock_label' => (string) $this->t('—'),
      'total_finite_stock' => 0,
      'has_unlimited' => FALSE,
      'all_sold_out' => TRUE,
      'has_low_stock' => FALSE,
      'stock_states' => [],
    ];
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function displayCategoryForProductBundle(string $bundle, array $payload): string {
    $product_type = strtolower((string) ($payload['operational_product_type'] ?? ''));
    $capability = strtolower((string) ($payload['capability_reference'] ?? ''));

    if ($bundle === 'operational_merchandise' && $capability === 'parking_addon') {
      return 'parking';
    }
    if ($bundle === 'hospitality_package') {
      return 'hospitality';
    }
    if ($bundle === 'operational_bundle') {
      return 'bundles';
    }
    if ($bundle === 'timed_collection_product') {
      return $this->isFoodDrinkType($product_type) ? 'food_drink' : 'timed_collection';
    }
    if ($this->isFoodDrinkType($product_type)) {
      return 'food_drink';
    }
    return 'merchandise';
  }

  private function displayCategoryFromCompositionGroup(string $composition_key, string $product_type): string {
    return match ($composition_key) {
      'parking' => 'parking',
      'hospitality' => 'hospitality',
      'bundles' => 'bundles',
      'timed_collection' => $this->isFoodDrinkType($product_type) ? 'food_drink' : 'timed_collection',
      default => $this->isFoodDrinkType($product_type) ? 'food_drink' : 'merchandise',
    };
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function displayCategoryForProduct(ProductInterface $product, array $payload): string {
    return $this->displayCategoryForProductBundle($product->bundle(), $payload);
  }

  private function isFoodDrinkType(string $product_type): bool {
    if ($product_type === '') {
      return FALSE;
    }
    return str_contains($product_type, 'food')
      || str_contains($product_type, 'drink')
      || $product_type === 'food_drink'
      || $product_type === 'food_drink_redemption';
  }

  /**
   * @param array<string, mixed> $catalog
   *
   * @return array{key: string, label: string, tone: string}
   */
  private function resolveGroupStatus(int $items_sold, array $catalog): array {
    $stock_state = (string) ($catalog['stock_state'] ?? 'unknown');
    $has_catalog = !empty($catalog['has_catalog']);

    if ($items_sold === 0) {
      if (!$has_catalog) {
        return [
          'key' => 'no_sales',
          'label' => (string) $this->t('No sales yet'),
          'tone' => 'muted',
        ];
      }
      if ($stock_state === 'sold_out') {
        return [
          'key' => 'sold_out',
          'label' => (string) $this->t('Sold out'),
          'tone' => 'warning',
        ];
      }
      if ($stock_state === 'unlimited') {
        return [
          'key' => 'no_stock_limit',
          'label' => (string) $this->t('No stock limit'),
          'tone' => 'info',
        ];
      }
      return [
        'key' => 'selling',
        'label' => (string) $this->t('Selling'),
        'tone' => 'success',
      ];
    }

    if ($stock_state === 'sold_out') {
      return [
        'key' => 'sold_out',
        'label' => (string) $this->t('Sold out'),
        'tone' => 'warning',
      ];
    }
    if ($stock_state === 'low') {
      return [
        'key' => 'low_stock',
        'label' => (string) $this->t('Low stock'),
        'tone' => 'warning',
      ];
    }
    if ($stock_state === 'unlimited') {
      return [
        'key' => 'no_stock_limit',
        'label' => (string) $this->t('No stock limit'),
        'tone' => 'info',
      ];
    }

    return [
      'key' => 'selling',
      'label' => (string) $this->t('Selling'),
      'tone' => 'success',
    ];
  }

  /**
   * Customer-safe product label for analytics and vendor dashboards.
   *
   * @param array<string, mixed> $line
   */
  private function resolveProductLabelFromLine(array $line, string $display_key): string {
    $presentation = is_array($line['presentation'] ?? NULL) ? $line['presentation'] : [];
    $summary = trim((string) ($presentation['operational_summary'] ?? ''));
    if ($summary !== '') {
      return $summary;
    }
    return $this->groupLabel($display_key);
  }

  /**
   * @param array<string, mixed> $line
   */
  private function resolveOrderItem(array $line, OrderInterface $order): ?OrderItemInterface {
    $order_item_id = (int) ($line['order_item_id'] ?? 0);
    if ($order_item_id < 1) {
      return NULL;
    }
    foreach ($order->getItems() as $item) {
      if ((int) $item->id() === $order_item_id && $item instanceof OrderItemInterface) {
        return $item;
      }
    }
    return NULL;
  }

  private function orderItemIsForEvent(OrderItemInterface $item, int $event_id): bool {
    if ($item->bundle() === 'boost') {
      return FALSE;
    }
    if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
      if ((int) $item->get('field_target_event')->target_id === $event_id) {
        return TRUE;
      }
    }
    $variation = $item->getPurchasedEntity();
    if (!$variation instanceof ProductVariationInterface) {
      return FALSE;
    }
    if ($variation->hasField('field_event') && !$variation->get('field_event')->isEmpty()) {
      if ((int) $variation->get('field_event')->target_id === $event_id) {
        return TRUE;
      }
    }
    $product = $variation->getProduct();
    if ($product instanceof ProductInterface
      && $product->hasField('field_event')
      && !$product->get('field_event')->isEmpty()
      && (int) $product->get('field_event')->target_id === $event_id) {
      return TRUE;
    }
    return FALSE;
  }

  private function eventHasConfiguredCatalog(NodeInterface $event): bool {
    return $this->eventOperationalAddonBuilder->buildForEvent($event)['addons'] !== [];
  }

  private function formatAmount(float $amount, ?string $currency_code): string {
    if ($amount <= 0.0) {
      return '—';
    }
    $code = $currency_code ?? 'AUD';
    return $this->currencyFormatter->format((string) $amount, $code);
  }

  private function groupLabel(string $group_key): string {
    return match ($group_key) {
      'merchandise' => (string) $this->t('Merchandise'),
      'food_drink' => (string) $this->t('Food / drink'),
      'parking' => (string) $this->t('Parking'),
      'hospitality' => (string) $this->t('Hospitality'),
      'timed_collection' => (string) $this->t('Timed collection'),
      'bundles' => (string) $this->t('Bundles / other add-ons'),
      default => (string) $this->t('Add-ons'),
    };
  }

  /**
   * @return array<string, mixed>
   */
  private function emptyPanel(NodeInterface $event): array {
    $event_id = (int) $event->id();
    $rows = [];
    foreach (self::DISPLAY_GROUPS as $group_key) {
      $rows[] = [
        'key' => $group_key,
        'label' => $this->groupLabel($group_key),
        'items_sold' => 0,
        'revenue' => '—',
        'order_count' => 0,
        'stock_remaining_label' => '—',
        'stock_state' => 'unknown',
        'has_catalog' => FALSE,
        'status' => [
          'key' => 'no_sales',
          'label' => (string) $this->t('No sales yet'),
          'tone' => 'muted',
        ],
      ];
    }

    return [
      'has_rows' => FALSE,
      'summary' => [
        'total_revenue' => '—',
        'total_items_sold' => 0,
        'orders_with_extras' => 0,
        'top_category' => '—',
      ],
      'rows' => $rows,
      'product_rows' => [],
      'empty_message' => (string) $this->t('No extras sold yet. Configure merch and add-ons to start tracking sales here.'),
      'helper_copy' => (string) $this->t('Track extras sold through this event. Tickets are counted separately.'),
      'sold_order_states' => VendorOperationalAddonOrderBuilder::QUALIFYING_ORDER_STATES,
      'sold_basis' => 'operational_composition_lines',
      'actions' => [
        'view_orders' => ['label' => (string) $this->t('View extras orders'), 'url' => NULL, 'available' => FALSE],
        'manage_extras' => [
          'label' => (string) $this->t('Manage merch & add-ons'),
          'url' => $event_id > 0
            ? Url::fromRoute('myeventlane_event_studio.workspace_extras', ['node' => $event_id])->toString()
            : NULL,
          'available' => $event_id > 0,
        ],
      ],
      'operational_documents' => $this->buildOperationalDocumentsBlock(),
      'cache_tags' => $event->getCacheTags(),
    ];
  }

  /**
   * Operational document actions for Manage event (routes deferred until implemented).
   *
   * @return array<string, mixed>
   */
  private function buildOperationalDocumentsBlock(): array {
    $coming_soon = (string) $this->t('Coming soon');
    $deferred_notice = (string) $this->t('Document generation is coming soon.');

    $item = static fn (string $key, string $label): array => [
      'key' => $key,
      'label' => $label,
      'url' => NULL,
      'available' => FALSE,
      'deferred' => TRUE,
      'coming_soon' => $coming_soon,
    ];

    return [
      'heading' => (string) $this->t('Operational documents'),
      'helper' => (string) $this->t('Use these to prepare merch, parking, food/drink, and pickup packs for your event.'),
      'deferred_notice' => $deferred_notice,
      'items' => [
        'packing_slips' => $item('packing_slips', (string) $this->t('Generate packing slips')),
        'parking_slips' => $item('parking_slips', (string) $this->t('Generate parking slips')),
        'labels' => $item('labels', (string) $this->t('Print item labels')),
        'export_report' => $item('export_report', (string) $this->t('Export extras report')),
      ],
    ];
  }

  private function routeExists(string $route_name): bool {
    try {
      $this->routeProvider->getRouteByName($route_name);
      return TRUE;
    }
    catch (RouteNotFoundException) {
      return FALSE;
    }
  }

}
