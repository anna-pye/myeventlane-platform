<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_commerce\Service\OrderItemClassifier;
use Drupal\myeventlane_core\MelReadinessHelper;

/**
 * Builds GST-aware pricing rows from Commerce orders (adjustments + subtotals).
 *
 * Used for cart, checkout sidebar, confirmation, and email context. Does not
 * hardcode tax amounts; reads adjustments and Australian GST config.
 */
final class OrderPricingBreakdownBuilder {

  public function __construct(
    private readonly CurrencyFormatter $currencyFormatter,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly OrderItemClassifier $orderItemClassifier,
    private readonly MelReadinessHelper $readinessHelper,
  ) {}

  /**
   * @return array{
   *   subtotal_formatted: string,
   *   tax_rows: list<array{label: string, amount_formatted: string}>,
   *   fee_rows: list<array{label: string, amount_formatted: string}>,
   *   platform_fee_absorbed: bool,
   *   total_formatted: string,
   *   show_includes_gst_note: bool,
   *   show_cart_includes_gst_note: bool,
   * }
   */
  public function build(OrderInterface $order): array {
    $subtotal = $this->calculateTicketSubtotal($order);
    $subtotal_formatted = $subtotal ? $this->formatPrice($subtotal) : '';

    $tax_rows = [];
    foreach ($order->getAdjustments() as $adjustment) {
      if ($adjustment->getType() !== 'tax') {
        continue;
      }
      $amount = $adjustment->getAmount();
      if (!$amount instanceof Price || $amount->isZero()) {
        continue;
      }
      $raw_label = trim((string) $adjustment->getLabel());
      $label = $raw_label === '' ? $this->readinessHelper->customerCheckoutTaxRowFallbackLabel() : $raw_label;
      $label = $this->decorateGstRowLabel($label);
      $tax_rows[] = [
        'label' => $label,
        'amount_formatted' => $this->formatPrice($amount),
      ];
    }

    $fee_rows = [];
    $fees = NULL;
    foreach ($order->getAdjustments() as $adjustment) {
      if ($adjustment->getType() !== 'fee') {
        continue;
      }
      $amount = $adjustment->getAmount();
      if (!$amount instanceof Price || $amount->isZero()) {
        continue;
      }
      if ($fees === NULL) {
        $fees = $amount;
      }
      else {
        $fees = $fees->add($amount);
      }
      $fee_label = trim((string) $adjustment->getLabel());
      if ($fee_label === '') {
        $fee_label = $this->readinessHelper->customerCheckoutFeeRowFallbackLabel();
      }
      $fee_rows[] = [
        'label' => $fee_label,
        'amount_formatted' => $this->formatPrice($amount),
      ];
    }

    $store = $order->getStore();
    $prices_include_tax = $this->storePricesIncludeTax($store);

    $platform_fee_absorbed = FALSE;
    if (($fees === NULL || $fees->isZero())
      && $subtotal instanceof Price
      && !$subtotal->isZero()
      && $this->configFactory->get('myeventlane_core.settings')->get('fee_payer') === 'organizer_absorbs') {
      $platform_fee_absorbed = TRUE;
    }

    $total = $order->getTotalPrice();
    $total_formatted = $total ? $this->formatPrice($total) : '';

    $has_gst = $tax_rows !== [];
    $au_store = $this->storeIsAustralia($store);
    $gst_type_active = $this->australianGstTaxTypeIsActive();

    $show_includes_gst_note = $prices_include_tax && $au_store && $gst_type_active && $has_gst;
    $show_cart_includes_gst_note = $show_includes_gst_note;

    return [
      'subtotal_formatted' => $subtotal_formatted,
      'tax_rows' => $tax_rows,
      'fee_rows' => $fee_rows,
      'platform_fee_absorbed' => $platform_fee_absorbed,
      'total_formatted' => $total_formatted,
      'show_includes_gst_note' => $show_includes_gst_note,
      'show_cart_includes_gst_note' => $show_cart_includes_gst_note,
    ];
  }

  private function storePricesIncludeTax(?StoreInterface $store): bool {
    if (!$store || !$store->hasField('prices_include_tax') || $store->get('prices_include_tax')->isEmpty()) {
      return FALSE;
    }
    return (bool) $store->get('prices_include_tax')->value;
  }

  private function storeIsAustralia(?StoreInterface $store): bool {
    if (!$store) {
      return FALSE;
    }
    $address = $store->getAddress();
    if (!$address) {
      return FALSE;
    }
    return $address->getCountryCode() === 'AU';
  }

  /**
   * Whether the Australian GST Commerce tax type is enabled (config entity).
   */
  public function australianGstTaxTypeIsActive(): bool {
    if (!$this->entityTypeManager->hasDefinition('commerce_tax_type')) {
      return FALSE;
    }
    $entity = $this->entityTypeManager->getStorage('commerce_tax_type')->load('australian_gst');
    return $entity !== NULL && method_exists($entity, 'status') && $entity->status();
  }

  /**
   * Event-page helper: show “Includes GST” when paid commerce path uses AU + GST.
   */
  public function eventPaidListingShouldShowIncludesGstNote(?StoreInterface $store): bool {
    return $this->storeIsAustralia($store)
      && $this->storePricesIncludeTax($store)
      && $this->australianGstTaxTypeIsActive();
  }

  private function decorateGstRowLabel(string $label): string {
    $pct = $this->getAustralianGstPercentDisplay();
    if ($pct === NULL) {
      return $label;
    }
    if (!str_contains(strtolower($label), 'gst')) {
      return $label;
    }
    return 'GST (' . $pct . '%)';
  }

  private function getAustralianGstPercentDisplay(): ?string {
    $config = $this->configFactory->get('commerce_tax.commerce_tax_type.australian_gst');
    if (!$config || !$config->get('status')) {
      return NULL;
    }
    $rates = $config->get('configuration')['rates'] ?? [];
    $first = is_array($rates) ? reset($rates) : FALSE;
    if (!is_array($first) || !isset($first['percentage'])) {
      return NULL;
    }
    $pct = (float) $first['percentage'];
    return (string) (int) round($pct * 100);
  }

  private function calculateTicketSubtotal(OrderInterface $order): ?Price {
    $subtotal = NULL;
    foreach ($order->getItems() as $item) {
      if ($this->orderItemClassifier->isDonation($item)) {
        continue;
      }
      $item_total = $item->getTotalPrice();
      if ($item_total instanceof Price) {
        $subtotal = $subtotal === NULL ? $item_total : $subtotal->add($item_total);
      }
    }
    return $subtotal;
  }

  private function formatPrice(?Price $price): string {
    if (!$price instanceof Price) {
      return '';
    }
    return $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());
  }

}
