<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\OrderProcessor;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderProcessorInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_price\RounderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\myeventlane_commerce\Service\TicketBackedOrderItemClassifierInterface;
use Drupal\myeventlane_core\Service\PlatformFeeSettings;

/**
 * Applies configurable platform fees to ticket and operational extras subtotals.
 *
 * Ticket lines use {@see PlatformFeeSettings::getTicketFeePercent()}. Operational
 * extras use {@see PlatformFeeSettings::getOperationalExtrasFeePercent()}
 * (defaults to double the ticket rate). Excludes donations, Boost, and Pro.
 *
 * Configured at: Admin > Config > MyEventLane > General settings.
 */
final class PlatformFeeOrderProcessor implements OrderProcessorInterface {

  use StringTranslationTrait;

  private const SOURCE_TICKET_FEE = 'myeventlane_platform_fee';

  private const SOURCE_EXTRAS_FEE = 'myeventlane_operational_extras_platform_fee';

  private const EXCLUDED_ORDER_TYPES = [
    'recurring',
  ];

  private const EXCLUDED_BUNDLES = [
    'checkout_donation',
    'platform_donation',
    'rsvp_donation',
    'boost',
    'recurring_product_variation',
    'recurring_standalone',
  ];

  /**
   * Product variation types whose items never incur a platform fee.
   */
  private const EXCLUDED_VARIATION_TYPES = [
    'mel_pro_subscription_variation',
    'boost_duration',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RounderInterface $rounder,
    private readonly TicketBackedOrderItemClassifierInterface $ticketBackedClassifier,
    private readonly PlatformFeeSettings $platformFeeSettings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function process(OrderInterface $order): void {
    if (in_array($order->bundle(), self::EXCLUDED_ORDER_TYPES, TRUE)) {
      return;
    }

    $settings = $this->configFactory->get('myeventlane_core.settings');
    if ($settings->get('fee_payer') === 'organizer_absorbs') {
      return;
    }

    $this->removePlatformFeeAdjustments($order);

    $ticket_percent = $this->platformFeeSettings->getTicketFeePercent();
    $extras_percent = $this->platformFeeSettings->getOperationalExtrasFeePercent();

    if ($ticket_percent > 0) {
      $ticket_subtotal = $this->computeTicketBackedSubtotal($order);
      if ($ticket_subtotal && (float) $ticket_subtotal->getNumber() > 0) {
        $this->addFeeAdjustment(
          $order,
          $ticket_subtotal,
          $ticket_percent,
          self::SOURCE_TICKET_FEE,
          (string) $this->t('Platform fee (@pct%)', ['@pct' => $this->formatPercentLabel($ticket_percent)])
        );
      }
    }

    if ($extras_percent > 0) {
      $extras_subtotal = $this->computeOperationalExtrasSubtotal($order);
      if ($extras_subtotal && (float) $extras_subtotal->getNumber() > 0) {
        $this->addFeeAdjustment(
          $order,
          $extras_subtotal,
          $extras_percent,
          self::SOURCE_EXTRAS_FEE,
          (string) $this->t('Extras platform fee (@pct%)', ['@pct' => $this->formatPercentLabel($extras_percent)])
        );
      }
    }
  }

  private function removePlatformFeeAdjustments(OrderInterface $order): void {
    foreach ($order->getAdjustments() as $adjustment) {
      $source = $adjustment->getSourceId();
      if ($source === self::SOURCE_TICKET_FEE || $source === self::SOURCE_EXTRAS_FEE) {
        $order->removeAdjustment($adjustment);
      }
    }
  }

  private function addFeeAdjustment(
    OrderInterface $order,
    Price $subtotal,
    float $percent,
    string $source_id,
    string $label,
  ): void {
    $fee_price = $subtotal->multiply((string) ($percent / 100));
    $rounded = $this->rounder->round($fee_price);
    if ((float) $rounded->getNumber() <= 0) {
      return;
    }

    $order->addAdjustment(new Adjustment([
      'type' => 'fee',
      'label' => $label,
      'amount' => $rounded,
      'source_id' => $source_id,
    ]));
  }

  private function formatPercentLabel(float $percent): string {
    return $percent === (float) (int) $percent
      ? (string) (int) $percent
      : number_format($percent, 1);
  }

  /**
   * Subtotal of ticket-backed order items only.
   */
  private function computeTicketBackedSubtotal(OrderInterface $order): ?Price {
    $total = NULL;

    foreach ($order->getItems() as $item) {
      $purchased = $item->getPurchasedEntity();
      $variation_bundle = $purchased instanceof ProductVariationInterface ? $purchased->bundle() : NULL;
      if (!$this->isEligibleLineItem($item->bundle(), $variation_bundle)) {
        continue;
      }
      if (!$this->ticketBackedClassifier->isTicketBackedOrderItem($item)) {
        continue;
      }
      $price = $item->getTotalPrice();
      if (!$price) {
        continue;
      }
      $total = $total ? $total->add($price) : $price;
    }

    return $total;
  }

  /**
   * Subtotal of operational extras order items (not ticket-backed).
   */
  private function computeOperationalExtrasSubtotal(OrderInterface $order): ?Price {
    $total = NULL;

    foreach ($order->getItems() as $item) {
      $purchased = $item->getPurchasedEntity();
      $variation_bundle = $purchased instanceof ProductVariationInterface ? $purchased->bundle() : NULL;
      if (!$this->isEligibleLineItem($item->bundle(), $variation_bundle)) {
        continue;
      }
      if (!$this->ticketBackedClassifier->isOperationalOrderItem($item)) {
        continue;
      }
      $price = $item->getTotalPrice();
      if (!$price) {
        continue;
      }
      $total = $total ? $total->add($price) : $price;
    }

    return $total;
  }

  /**
   * @param string|null $variation_bundle
   */
  private function isEligibleLineItem(string $order_item_bundle, ?string $variation_bundle): bool {
    if (in_array($order_item_bundle, self::EXCLUDED_BUNDLES, TRUE)) {
      return FALSE;
    }
    if ($variation_bundle !== NULL && in_array($variation_bundle, self::EXCLUDED_VARIATION_TYPES, TRUE)) {
      return FALSE;
    }
    return TRUE;
  }

}
