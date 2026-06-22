<?php

/**
 * @file
 * Complete pending manual-gateway payments for E2E verification.
 *
 * Commerce Manual leaves payments in "pending" at checkout. Ticket issuance
 * listens for ORDER_PAID, which fires when the payment is received. This script
 * simulates a vendor marking the payment received — test infrastructure only.
 *
 * Usage:
 *   ddev drush php:script tests/e2e/scripts/complete-manual-payment.php
 *   Env: MEL_E2E_ORDER_ID
 */

declare(strict_types=1);

if (!defined('DRUPAL_ROOT')) {
  fwrite(STDERR, "Run via: ddev drush php:script tests/e2e/scripts/complete-manual-payment.php\n");
  exit(1);
}

$orderId = (int) (getenv('MEL_E2E_ORDER_ID') ?: 0);
if ($orderId < 1) {
  fwrite(STDERR, "MEL_E2E_ORDER_ID is required.\n");
  exit(1);
}

/** @var \Drupal\commerce_order\Entity\OrderInterface|null $order */
$order = \Drupal::entityTypeManager()->getStorage('commerce_order')->load($orderId);
if ($order === NULL) {
  fwrite(STDERR, "Order {$orderId} not found.\n");
  exit(1);
}

$paymentStorage = \Drupal::entityTypeManager()->getStorage('commerce_payment');
$paymentIds = $paymentStorage->getQuery()
  ->accessCheck(FALSE)
  ->condition('order_id', $orderId)
  ->condition('state', 'pending')
  ->execute();

$completed = [];
$skipped = [];

foreach ($paymentIds as $paymentId) {
  /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
  $payment = $paymentStorage->load($paymentId);
  if ($payment === NULL) {
    continue;
  }

  $gateway = $payment->getPaymentGateway();
  if ($gateway === NULL || $gateway->getPluginId() !== 'manual') {
    $skipped[] = (int) $paymentId;
    continue;
  }

  $state = $payment->getState();
  if (!$state->isTransitionAllowed('receive')) {
    $skipped[] = (int) $paymentId;
    continue;
  }

  $state->applyTransitionById('receive');
  $payment->save();
  $completed[] = (int) $paymentId;
}

echo json_encode([
  'orderId' => $orderId,
  'completedPaymentIds' => $completed,
  'skippedPaymentIds' => $skipped,
  'ok' => count($completed) >= 1 || count($paymentIds) === 0,
], JSON_THROW_ON_ERROR);
