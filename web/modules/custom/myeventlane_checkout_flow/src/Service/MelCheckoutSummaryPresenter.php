<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Cache\Cache;
use Drupal\myeventlane_core\GovernedOperationalTemplates;
use Drupal\myeventlane_core\MelReadinessHelper;

/**
 * Canonical checkout order summary presentation (grouped structure, labels, trust).
 *
 * Commerce remains authoritative for amounts and line items; Twig is presentation-only.
 */
final class MelCheckoutSummaryPresenter {

  public function __construct(
    private readonly CheckoutGroupedSummaryBuilderInterface $groupedSummaryBuilder,
    private readonly MelReadinessHelper $readinessHelper,
    private readonly GovernedOperationalTemplates $operationalTemplates,
  ) {}

  /**
   * Render array for grouped summary or governed empty state when the order has no lines.
   *
   * @param array<string, mixed> $options
   *   - surface: 'checkout' (default), 'cart', or 'complete'. Controls layout-only flags
   *     (e.g. jump-to-payment link) — pricing still comes from Commerce via builders only.
   *
   * @return array<string, mixed>
   */
  public function buildGroupedSummaryRenderArray(OrderInterface $order, array $options = []): array {
    $surface = (string) ($options['surface'] ?? 'checkout');
    if (!in_array($surface, ['checkout', 'cart', 'complete'], TRUE)) {
      $surface = 'checkout';
    }

    $built = $this->groupedSummaryBuilder->build($order);
    $cache = [
      'tags' => Cache::mergeTags($order->getCacheTags(), (array) ($built['cache_tags'] ?? [])),
      'contexts' => $order->getCacheContexts(),
    ];

    $has_pricing_block = $this->hasPricingBlock($built);
    $has_grouped_line_items = $this->hasGroupedLineItems($built);
    if (!$has_grouped_line_items && !$has_pricing_block) {
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-checkout-order-summary', 'mel-checkout-order-summary--empty-state']],
        'empty' => $this->operationalTemplates->checkoutOrderSummaryNoLinesEmpty(),
        '#cache' => $cache,
      ];
    }

    $labels = $this->readinessHelper->customerCheckoutOrderSummarySurfaceLabels();
    $trust = [
      'line_secure' => $labels['trust_footer_secure'],
      'line_instant' => $labels['trust_footer_instant'],
      'line_refund' => $labels['trust_footer_refund_hint'],
    ];

    return [
      '#theme' => 'mel_checkout_order_summary_grouped',
      '#cache' => $cache,
      'grouped_items' => $built['grouped_items'],
      'order_total' => $built['order_total'],
      'subtotal_formatted' => $built['subtotal_formatted'],
      'optional_donation_formatted' => $built['optional_donation_formatted'],
      'tax_rows' => $built['tax_rows'],
      'fee_rows' => $built['fee_rows'],
      'platform_fee_absorbed' => $built['platform_fee_absorbed'],
      'show_includes_gst_note' => $built['show_includes_gst_note'],
      'labels' => $labels,
      'trust' => $trust,
      'has_pricing_block' => $has_pricing_block,
      'has_grouped_line_items' => $has_grouped_line_items,
      'surface' => $surface,
      'show_jump_to_payment' => $surface === 'checkout',
    ];
  }

  /**
   * Whether any event group has ticket/add-on lines to show (not pricing-only).
   *
   * @param array<string, mixed> $built
   *   Output shape from CheckoutGroupedSummaryBuilder::build().
   */
  private function hasGroupedLineItems(array $built): bool {
    foreach ($built['grouped_items'] as $event) {
      if (!is_array($event)) {
        continue;
      }
      foreach ($event['items'] ?? [] as $item) {
        if (!is_array($item)) {
          continue;
        }
        if ((int) ($item['quantity'] ?? 0) <= 0) {
          continue;
        }
        $ticket_type = trim((string) ($item['ticket_type'] ?? ''));
        $total_price = trim((string) ($item['total_price'] ?? ''));
        if ($ticket_type !== '' || $total_price !== '' || !empty($item['operational_addon_display'])) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * @param array<string, mixed> $built
   *   Output shape from CheckoutGroupedSummaryBuilder::build().
   */
  private function hasPricingBlock(array $built): bool {
    if (($built['order_total'] ?? '') !== ''
      || ($built['subtotal_formatted'] ?? '') !== ''
      || ($built['optional_donation_formatted'] ?? '') !== '') {
      return TRUE;
    }
    if (!empty($built['tax_rows']) || !empty($built['fee_rows'])) {
      return TRUE;
    }
    return !empty($built['platform_fee_absorbed']);
  }

}
