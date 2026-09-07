<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\myeventlane_core\Service\PlatformFeeSettings;

/**
 * Quotes buyer totals without altering stored ticket prices or order items.
 */
final class PublicPriceCalculator {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly PlatformFeeSettings $feeSettings,
    private readonly RounderInterface $rounder,
  ) {}

  public function buyerFeePercent(bool $extras = FALSE): float {
    if ($this->configFactory->get('myeventlane_core.settings')->get('fee_payer') === 'organizer_absorbs') {
      return 0.0;
    }
    return $extras ? $this->feeSettings->getOperationalExtrasFeePercent() : $this->feeSettings->getTicketFeePercent();
  }

  /**
   * Matches PlatformFeeOrderProcessor: round the fee on the category subtotal.
   * Never multiply a rounded fee-inclusive unit price to quote a group total.
   */
  public function total(Price $subtotal, bool $extras = FALSE): Price {
    if (!$subtotal->isPositive()) {
      return $subtotal;
    }
    $fee = $this->rounder->round($subtotal->multiply((string) ($this->buyerFeePercent($extras) / 100)));
    return $subtotal->add($fee);
  }

}
