<?php

/**
 * @file
 * Verify issued tickets for a completed checkout order.
 *
 * Usage:
 *   ddev drush php:script tests/e2e/scripts/verify-order-tickets.php -- ORDER_ID HOLDER_EMAIL
 */

declare(strict_types=1);

if (!defined('DRUPAL_ROOT')) {
  fwrite(STDERR, "Run via: ddev drush php:script tests/e2e/scripts/verify-order-tickets.php -- ORDER_ID HOLDER_EMAIL\n");
  exit(1);
}

$orderId = (int) (getenv('MEL_E2E_ORDER_ID') ?: 0);
$holderEmail = (string) (getenv('MEL_E2E_HOLDER_EMAIL') ?: '');

if ($orderId < 1 || $holderEmail === '') {
  fwrite(STDERR, "ORDER_ID and HOLDER_EMAIL are required.\n");
  exit(1);
}

/** @var \Drupal\commerce_order\Entity\OrderInterface|null $order */
$order = \Drupal::entityTypeManager()->getStorage('commerce_order')->load($orderId);
if ($order === NULL) {
  fwrite(STDERR, "Order {$orderId} not found.\n");
  exit(1);
}

$ticketStorage = \Drupal::entityTypeManager()->getStorage('myeventlane_ticket');
$ticketIds = $ticketStorage->getQuery()
  ->accessCheck(FALSE)
  ->condition('order_id', $orderId)
  ->condition('holder_email', $holderEmail)
  ->execute();

$holderParagraphCount = 0;
foreach ($order->getItems() as $item) {
  if ($item->hasField('field_ticket_holder')) {
    $holderParagraphCount += count($item->get('field_ticket_holder')->referencedEntities());
  }
}

echo json_encode([
  'orderId' => $orderId,
  'orderState' => $order->getState()->getId(),
  'orderEmail' => $order->getEmail(),
  'ticketCount' => count($ticketIds),
  'ticketIds' => array_map('intval', array_values($ticketIds)),
  'holderParagraphCount' => $holderParagraphCount,
  'ok' => $order->getState()->getId() === 'completed' && count($ticketIds) >= 1,
], JSON_THROW_ON_ERROR);
