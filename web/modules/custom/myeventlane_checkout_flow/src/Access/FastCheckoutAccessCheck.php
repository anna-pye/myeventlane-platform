<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Access;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\myeventlane_checkout_flow\Service\FastCheckoutEligibility;

/**
 * Protects Stripe express checkout confirmation for MEL ticket orders.
 */
final class FastCheckoutAccessCheck {

  public function __construct(
    private readonly FastCheckoutEligibility $eligibility,
  ) {}

  /**
   * Checks access to the Commerce Stripe express confirmation route.
   */
  public function access(PaymentGatewayInterface $commerce_payment_gateway, OrderInterface $commerce_order): AccessResultInterface {
    if (!$this->eligibility->hasTicketSelection($commerce_order)) {
      return AccessResult::allowed()->addCacheableDependency($commerce_order);
    }

    $allowed = $this->eligibility->canConfirmFastCheckout($commerce_order, $commerce_payment_gateway);
    return AccessResult::allowedIf($allowed)
      ->addCacheableDependency($commerce_order)
      ->addCacheableDependency($commerce_payment_gateway);
  }

}
