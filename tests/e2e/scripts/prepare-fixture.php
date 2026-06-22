<?php

/**
 * @file
 * Prepare deterministic E2E checkout fixtures on local DDEV.
 *
 * Usage:
 *   ddev drush php:script tests/e2e/scripts/prepare-fixture.php
 *
 * Prints JSON to stdout:
 *   baseUrl, eventNid, eventPath, eventTitle, paymentMode, buyerEmail
 */

declare(strict_types=1);

if (!defined('DRUPAL_ROOT')) {
  fwrite(STDERR, "Run via: ddev drush php:script tests/e2e/scripts/prepare-fixture.php\n");
  exit(1);
}

$paymentMode = getenv('MEL_E2E_PAYMENT_MODE') ?: 'manual';
if (!in_array($paymentMode, ['manual', 'stripe_test'], TRUE)) {
  fwrite(STDERR, "Invalid MEL_E2E_PAYMENT_MODE: {$paymentMode}\n");
  exit(1);
}

$baseUrl = rtrim(getenv('MEL_E2E_BASE_URL') ?: 'https://myeventlane.ddev.site', '/');
$targetTitle = getenv('MEL_E2E_EVENT_TITLE') ?: '[MEL TEST] Event 8 - Paid';
$buyerEmail = getenv('MEL_E2E_BUYER_EMAIL') ?: 'e2e-buyer+' . gmdate('YmdHis') . '@example.test';

/** @var \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager */
$entityTypeManager = \Drupal::entityTypeManager();

// Ensure seed module is available; seed events if the paid fixture is missing.
$moduleHandler = \Drupal::moduleHandler();
if (!$moduleHandler->moduleExists('myeventlane_seed')) {
  fwrite(STDERR, "myeventlane_seed is not enabled. Enable it or create a paid test event manually.\n");
  exit(1);
}

$nodeStorage = $entityTypeManager->getStorage('node');
$existing = $nodeStorage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'event')
  ->condition('title', $targetTitle)
  ->range(0, 1)
  ->execute();

if ($existing === []) {
  /** @var \Drupal\myeventlane_seed\Service\DemoEventSeeder $seeder */
  $seeder = \Drupal::service('myeventlane_seed.demo_event_seeder');
  try {
    $seeder->seed(['use-settings' => TRUE]);
  }
  catch (\Throwable $e) {
    fwrite(STDERR, 'Failed to seed demo events: ' . $e->getMessage() . "\n");
    exit(1);
  }
  $existing = $nodeStorage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'event')
    ->condition('title', $targetTitle)
    ->range(0, 1)
    ->execute();
}

if ($existing === []) {
  fwrite(STDERR, "Paid fixture event not found after seeding: {$targetTitle}\n");
  exit(1);
}

/** @var \Drupal\node\NodeInterface $event */
$event = $nodeStorage->load((int) reset($existing));
if ($event === NULL || !$event->isPublished()) {
  fwrite(STDERR, "Fixture event exists but is not published.\n");
  exit(1);
}

if ($event->get('field_event_type')->value !== 'paid') {
  fwrite(STDERR, "Fixture event is not a paid event.\n");
  exit(1);
}

$alias = \Drupal::service('path_alias.manager')->getAliasByPath('/node/' . $event->id());
$bookPath = '/event/' . $event->id() . '/book';

// Payment gateway prep for deterministic checkout.
$stateFile = DRUPAL_ROOT . '/../tests/e2e/fixtures/payment-gateway-state.json';
$gatewayStorage = $entityTypeManager->getStorage('commerce_payment_gateway');
$stripeIds = ['stripe', 'stripe_pe_recurring', 'stripe_myeventlane_v2'];
$manualId = 'mel_stripe_cc';

if ($paymentMode === 'manual') {
  $previous = [];
  foreach (array_merge($stripeIds, [$manualId]) as $gatewayId) {
    $gateway = $gatewayStorage->load($gatewayId);
    if ($gateway !== NULL) {
      $previous[$gatewayId] = (bool) $gateway->status();
    }
  }

  foreach ($stripeIds as $gatewayId) {
    $gateway = $gatewayStorage->load($gatewayId);
    if ($gateway !== NULL && $gateway->status()) {
      $gateway->set('status', FALSE);
      $gateway->save();
    }
  }

  $manual = $gatewayStorage->load($manualId);
  if ($manual === NULL || !$manual->status()) {
    fwrite(STDERR, "Manual payment gateway mel_stripe_cc is missing or disabled.\n");
    exit(1);
  }

  if (!is_dir(dirname($stateFile))) {
    mkdir(dirname($stateFile), 0775, TRUE);
  }
  file_put_contents($stateFile, json_encode([
    'paymentMode' => $paymentMode,
    'previous' => $previous,
  ], JSON_PRETTY_PRINT));
}
else {
  // stripe_test: ensure stripe gateway is enabled with test keys from settings overrides.
  $stripe = $gatewayStorage->load('stripe');
  if ($stripe === NULL || !$stripe->status()) {
    fwrite(STDERR, "Stripe gateway is not enabled for stripe_test mode.\n");
    exit(1);
  }
  $cfg = $stripe->getPluginConfiguration();
  if (($cfg['mode'] ?? '') !== 'test') {
    fwrite(STDERR, "Stripe gateway is not in test mode.\n");
    exit(1);
  }
  if (empty($cfg['publishable_key']) || empty($cfg['secret_key'])) {
    fwrite(STDERR, "Stripe test keys are not configured. Set MEL_STRIPE_PUBLISHABLE_KEY and MEL_STRIPE_SECRET_KEY in DDEV web_environment.\n");
    exit(1);
  }
}

\Drupal::service('cache_tags.invalidator')->invalidateTags(['config:commerce_payment_gateway_list']);

// Ensure ticket carts use the MEL single-page checkout flow (not Commerce default).
$orderTypeConfig = \Drupal::configFactory()->getEditable('commerce_order.commerce_order_type.default');
$thirdParty = $orderTypeConfig->get('third_party_settings.commerce_checkout') ?? [];
if (($thirdParty['checkout_flow'] ?? 'default') !== 'mel_event_checkout') {
  $thirdParty['checkout_flow'] = 'mel_event_checkout';
  $orderTypeConfig->set('third_party_settings.commerce_checkout', $thirdParty);
  $orderTypeConfig->save(TRUE);
}

echo json_encode([
  'baseUrl' => $baseUrl,
  'eventNid' => (int) $event->id(),
  'eventPath' => $alias,
  'bookPath' => $bookPath,
  'eventTitle' => $event->label(),
  'paymentMode' => $paymentMode,
  'buyerEmail' => $buyerEmail,
], JSON_THROW_ON_ERROR);
