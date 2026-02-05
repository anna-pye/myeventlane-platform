<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\OrderProcessor;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderProcessorInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Applies a configurable platform fee (% of ticket subtotal) to orders.
 *
 * Fee is calculated on ticket items only. Excludes: donations
 * (checkout_donation, platform_donation, rsvp_donation) and Boost.
 * Configured at: Admin > Config > MyEventLane > General settings.
 */
final class PlatformFeeOrderProcessor implements OrderProcessorInterface {

  use StringTranslationTrait;

  /**
   * Order item bundles excluded from the fee base.
   */
  private const EXCLUDED_BUNDLES = [
    'checkout_donation',
    'platform_donation',
    'rsvp_donation',
    'boost',
  ];

  /**
   * Constructs the processor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\commerce_price\RounderInterface $rounder
   *   The price rounder.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RounderInterface $rounder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function process(OrderInterface $order): void {
    $settings = $this->configFactory->get('myeventlane_core.settings');
    if ($settings->get('fee_payer') === 'organizer_absorbs') {
      return;
    }

    $percent = (float) ($settings->get('platform_fee_percent') ?? 5);

    if ($percent <= 0) {
      return;
    }

    $subtotal = $this->computeTicketSubtotal($order);
    if (!$subtotal || (float) $subtotal->getNumber() <= 0) {
      return;
    }

    $feePrice = $subtotal->multiply((string) ($percent / 100));
    $rounded = $this->rounder->round($feePrice);
    if ((float) $rounded->getNumber() <= 0) {
      return;
    }

    $pctLabel = $percent === (float) (int) $percent
      ? (string) (int) $percent
      : number_format($percent, 1);

    $order->addAdjustment(new Adjustment([
      'type' => 'fee',
      'label' => (string) $this->t('Platform fee (@pct%)', ['@pct' => $pctLabel]),
      'amount' => $rounded,
      'source_id' => 'myeventlane_platform_fee',
    ]));
  }

  /**
   * Computes the subtotal of ticket items (excludes donations and Boost).
   */
  private function computeTicketSubtotal(OrderInterface $order): ?Price {
    $total = NULL;

    foreach ($order->getItems() as $item) {
      if (in_array($item->bundle(), self::EXCLUDED_BUNDLES, TRUE)) {
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

}
