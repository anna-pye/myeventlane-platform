<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\OrderPreprocessor;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderPreprocessorInterface;
use Drupal\commerce_order\OrderProcessorInterface;
use Drupal\commerce_price\Price;
use Drupal\myeventlane_commerce\Service\TicketBackedOrderItemClassifierInterface;

/**
 * Restores configured bundle prices before Commerce recalculates included tax.
 */
final class TicketBundlePricePreprocessor implements OrderPreprocessorInterface, OrderProcessorInterface {

  public const TAX_PLACEHOLDER_SOURCE = 'myeventlane_ticket_bundle_tax_placeholder';

  public function __construct(
    private readonly TicketBackedOrderItemClassifierInterface $ticketBackedOrderItemClassifier,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function preprocess(OrderInterface $order): void {
    foreach ($order->getItems() as $order_item) {
      $number = trim((string) $order_item->getData('mel_ticket_bundle_gross_unit_price', ''));
      $currency = strtoupper(trim((string) $order_item->getData('mel_ticket_bundle_currency', '')));
      if ($number === '' || !is_numeric($number) || !preg_match('/^[A-Z]{3}$/', $currency)) {
        continue;
      }
      $order_item->setUnitPrice(new Price($number, $currency), TRUE);
    }
  }

  /**
   * Preserves buyer-facing ticket prices until a billing profile is known.
   *
   * For tax-inclusive stores Commerce removes default tax when an order has no
   * billing profile and therefore no tax adjustment. That made an existing
   * standalone ticket fall from A$50.00 to A$45.45 whenever adding or removing
   * a bundle refreshed the shared cart. A temporary, included zero-value tax
   * adjustment preserves advertised prices for ticket-backed lines until the
   * buyer supplies a billing profile. A lower-priority processor removes the
   * marker immediately after tax processing.
   */
  public function process(OrderInterface $order): void {
    $store = $order->getStore();
    if (!$store
      || !(bool) $store->get('prices_include_tax')->value
      || $order->getBillingProfile() !== NULL) {
      return;
    }
    foreach ($order->getItems() as $order_item) {
      $is_bundle_component = trim((string) $order_item->getData('mel_ticket_bundle_gross_unit_price', '')) !== '';
      if (!$is_bundle_component && !$this->ticketBackedOrderItemClassifier->isTicketBackedOrderItem($order_item)) {
        continue;
      }
      $unit_price = $order_item->getUnitPrice();
      if (!$unit_price) {
        continue;
      }
      $order_item->addAdjustment(new Adjustment([
        'type' => 'tax',
        'label' => 'Ticket bundle tax marker',
        'amount' => new Price('0', $unit_price->getCurrencyCode()),
        'source_id' => self::TAX_PLACEHOLDER_SOURCE,
        'included' => TRUE,
      ]));
    }
  }

}
