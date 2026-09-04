<?php

/**
 * @file
 * Deployment hooks for MyEventLane Commerce.
 */

declare(strict_types=1);

use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\myeventlane_commerce\Service\OperationalVariationStockResolver;

/**
 * Isolates organiser inventory and activates paid-only stock confirmation.
 */
function myeventlane_commerce_deploy_stock_hardening_v1(array &$sandbox): string {
  return \Drupal::service('myeventlane_commerce.operational_stock_migration')->migrate();
}

/**
 * Migrates legacy add-on quantities into Commerce Stock exactly once.
 */
function myeventlane_commerce_deploy_migrate_operational_addon_stock(array &$sandbox): string {
  $moduleHandler = \Drupal::moduleHandler();
  foreach (['commerce_stock', 'commerce_stock_field', 'commerce_stock_local'] as $module) {
    if (!$moduleHandler->moduleExists($module)) {
      throw new \RuntimeException(sprintf('Cannot migrate add-on stock: %s is not enabled.', $module));
    }
  }

  $database = \Drupal::database();
  foreach (['commerce_stock_transaction', 'commerce_stock_location_level'] as $table) {
    if (!$database->schema()->tableExists($table)) {
      throw new \RuntimeException(sprintf('Cannot migrate add-on stock: %s is missing.', $table));
    }
  }

  $entityTypeManager = \Drupal::entityTypeManager();
  $locationStorage = $entityTypeManager->getStorage('commerce_stock_location');
  $locationIds = $locationStorage->getQuery()
    ->accessCheck(FALSE)
    ->condition('status', TRUE)
    ->execute();
  if ($locationIds === []) {
    $location = $locationStorage->create([
      'type' => 'default',
      'name' => 'MyEventLane inventory',
      'status' => TRUE,
      'uid' => 0,
    ]);
    $location->save();
  }

  $storage = $entityTypeManager->getStorage('commerce_product_variation');
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', OperationalVariationStockResolver::OPERATIONAL_VARIATION_BUNDLES, 'IN')
    ->sort('variation_id', 'ASC')
    ->execute();

  $finite = 0;
  $unlimited = 0;
  $existing = 0;
  foreach ($storage->loadMultiple($ids) as $variation) {
    if (!$variation instanceof ProductVariationInterface
      || !$variation->hasField(OperationalVariationStockResolver::FIELD_STOCK_QUANTITY)
      || !$variation->hasField('commerce_stock_always_in_stock')
      || !$variation->hasField('field_stock_level')) {
      continue;
    }

    $transactionCount = (int) $database->select('commerce_stock_transaction', 't')
      ->condition('entity_type', 'commerce_product_variation')
      ->condition('entity_id', (int) $variation->id())
      ->countQuery()
      ->execute()
      ->fetchField();
    if ($transactionCount > 0) {
      // Existing Commerce Stock history wins. Never reset a live ledger from
      // the legacy snapshot field.
      $existing++;
      continue;
    }

    $legacy = $variation->get(OperationalVariationStockResolver::FIELD_STOCK_QUANTITY);
    if ($legacy->isEmpty()) {
      $variation->set('commerce_stock_always_in_stock', 1);
      $variation->save();
      $unlimited++;
      continue;
    }

    $variation->set('commerce_stock_always_in_stock', 0);
    $quantity = max(0, (int) $legacy->value);
    if ($quantity > 0) {
      $variation->set('field_stock_level', [
        'adjustment' => $quantity,
        'stock_transaction_note' => 'MyEventLane legacy add-on stock migration',
      ]);
    }
    $variation->save();
    $finite++;
  }

  return sprintf(
    'Migrated operational add-on stock: %d finite, %d unlimited, %d existing Commerce Stock ledgers preserved.',
    $finite,
    $unlimited,
    $existing,
  );
}
