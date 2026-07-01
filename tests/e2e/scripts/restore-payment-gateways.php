<?php

/**
 * @file
 * Restore payment gateway state after E2E runs.
 *
 * Usage:
 *   ddev drush php:script tests/e2e/scripts/restore-payment-gateways.php
 */

declare(strict_types=1);

if (!defined('DRUPAL_ROOT')) {
  fwrite(STDERR, "Run via: ddev drush php:script tests/e2e/scripts/restore-payment-gateways.php\n");
  exit(1);
}

$stateFile = DRUPAL_ROOT . '/../tests/e2e/fixtures/payment-gateway-state.json';
if (!is_readable($stateFile)) {
  echo json_encode(['restored' => FALSE, 'reason' => 'no state file'], JSON_THROW_ON_ERROR);
  return;
}

$state = json_decode((string) file_get_contents($stateFile), TRUE, 512, JSON_THROW_ON_ERROR);
$previous = $state['previous'] ?? [];
if (!is_array($previous) || $previous === []) {
  unlink($stateFile);
  echo json_encode(['restored' => FALSE, 'reason' => 'empty state'], JSON_THROW_ON_ERROR);
  return;
}

$gatewayStorage = \Drupal::entityTypeManager()->getStorage('commerce_payment_gateway');
foreach ($previous as $gatewayId => $enabled) {
  $gateway = $gatewayStorage->load($gatewayId);
  if ($gateway !== NULL) {
    $gateway->set('status', (bool) $enabled);
    $gateway->save();
  }
}

\Drupal::service('cache_tags.invalidator')->invalidateTags(['config:commerce_payment_gateway_list']);
unlink($stateFile);

echo json_encode(['restored' => TRUE, 'gateways' => array_keys($previous)], JSON_THROW_ON_ERROR);
