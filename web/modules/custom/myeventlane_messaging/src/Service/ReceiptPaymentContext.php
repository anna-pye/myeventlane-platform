<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\RequeueException;

/**
 * Resolves receipt references and payment facts after order placement.
 */
final class ReceiptPaymentContext {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CurrencyFormatterInterface $currencyFormatter,
  ) {}

  /**
   * Refreshes only receipt metadata, preserving queued supplier/tax evidence.
   */
  public function resolve(int $orderId): array {
    $order = $this->entityTypeManager->getStorage('commerce_order')->loadUnchanged($orderId);
    if (!$order instanceof OrderInterface || !$order->getPlacedTime()
      || trim((string) $order->getOrderNumber()) === '') {
      throw new RequeueException('Receipt is waiting for a placed, numbered order.');
    }

    $firstName = '';
    $profile = $order->getBillingProfile();
    if ($profile && $profile->hasField('address') && !$profile->get('address')->isEmpty()) {
      $firstName = trim((string) $profile->get('address')->first()->get('given_name')->getValue());
    }
    $paid = $order->getTotalPaid();
    $balance = $order->getBalance();
    $gstInclusive = TRUE;
    foreach ($order->collectAdjustments() as $adjustment) {
      if ($adjustment->getType() === 'tax' && !$adjustment->isIncluded() && !$adjustment->getAmount()->isZero()) {
        $gstInclusive = FALSE;
      }
    }
    return [
      'order_number' => trim((string) $order->getOrderNumber()),
      'first_name' => $firstName,
      'amount_paid' => $paid ? $this->currencyFormatter->format($paid->getNumber(), $paid->getCurrencyCode()) : '',
      'balance_due' => $balance ? $this->currencyFormatter->format($balance->getNumber(), $balance->getCurrencyCode()) : '',
      'organiser_gst_inclusive' => $gstInclusive,
    ];
  }

}
