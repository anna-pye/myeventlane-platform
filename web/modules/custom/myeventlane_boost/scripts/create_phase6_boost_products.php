<?php

/**
 * @file
 * Script to create Phase 6 boost products with correct pricing.
 *
 * Run with: ddev drush scr web/modules/custom/myeventlane_boost/scripts/create_phase6_boost_products.php.
 */

declare(strict_types=1);

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductType;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\Core\Url;

// Ensure there's at least one store.
$store_storage = \Drupal::entityTypeManager()->getStorage('commerce_store');
$stores = $store_storage->loadMultiple();
if (!$stores) {
  throw new \RuntimeException("No Commerce Store found. Create one at /admin/commerce/config/store first.");
}
$store = reset($stores);

// Check types.
if (!ProductType::load('boost_upgrade')) {
  throw new \RuntimeException("Product type 'boost_upgrade' not found. Run the scaffold first.");
}
if (!ProductVariationType::load('boost_duration')) {
  throw new \RuntimeException("Variation type 'boost_duration' not found. Run the scaffold first.");
}

// Check if boost product already exists.
$product_storage = \Drupal::entityTypeManager()->getStorage('commerce_product');
$existing = $product_storage->loadByProperties([
  'type' => 'boost_upgrade',
  'title' => 'Event Boost',
]);

if (!empty($existing)) {
  $product = reset($existing);
  echo "Boost product already exists (ID: {$product->id()}). Updating variations...\n";
}
else {
  // Create product.
  $product = Product::create([
    'type' => 'boost_upgrade',
    'title' => 'Event Boost',
    'status' => 1,
  ]);
  if (method_exists($product, 'setStores')) {
    $product->setStores([$store]);
  }
  else {
    $product->set('stores', [$store]);
  }
  $product->save();
  echo "Created boost product (ID: {$product->id()}).\n";
}

// Create/update variations with Phase 6 pricing:
// 7 days at $5/day = $35
// 10 days at $4/day = $40
// 30 days at $3/day = $90.
$defs = [
  ['days' => 7, 'amount' => '35.00', 'sku' => 'BOOST-7D', 'title' => '7 Day Boost'],
  ['days' => 10, 'amount' => '40.00', 'sku' => 'BOOST-10D', 'title' => '10 Day Boost'],
  ['days' => 30, 'amount' => '90.00', 'sku' => 'BOOST-30D', 'title' => '30 Day Boost'],
];

$variations = [];
foreach ($defs as $def) {
  // Check if variation exists by SKU.
  $variation_storage = \Drupal::entityTypeManager()->getStorage('commerce_product_variation');
  $existing_vars = $variation_storage->loadByProperties([
    'sku' => $def['sku'],
  ]);

  if (!empty($existing_vars)) {
    $variation = reset($existing_vars);
    // Update existing variation.
    $variation->set('field_boost_days', (int) $def['days']);
    $variation->setPrice(new Price($def['amount'], 'AUD'));
    $variation->setTitle($def['title']);
    $variation->save();
    echo "Updated variation: {$def['title']} (SKU: {$def['sku']}, {$def['days']} days, \${$def['amount']})\n";
  }
  else {
    // Create new variation.
    $variation = ProductVariation::create([
      'type' => 'boost_duration',
      'sku' => $def['sku'],
      'title' => $def['title'],
      'price' => new Price($def['amount'], 'AUD'),
      'status' => 1,
      'product_id' => $product->id(),
    ]);
    $variation->set('field_boost_days', (int) $def['days']);
    $variation->save();
    echo "Created variation: {$def['title']} (SKU: {$def['sku']}, {$def['days']} days, \${$def['amount']})\n";
  }
  $variations[] = $variation;
}

// Link variations to product.
$product->set('variations', $variations);
$product->save();

// Output.
$url = Url::fromRoute('entity.commerce_product.canonical', [
  'commerce_product' => $product->id(),
], ['absolute' => TRUE])->toString();

echo "\n";
echo "✅ Phase 6 Boost products configured:\n";
echo "  Product ID: {$product->id()}\n";
echo "  Title     : {$product->label()}\n";
echo "  URL       : {$url}\n";
echo "  Variations:\n";
foreach ($variations as $v) {
  $days = (int) $v->get('field_boost_days')->value;
  $price = $v->getPrice();
  $price_str = '$' . number_format((float) $price->getNumber(), 2);
  echo "    - {$v->label()} | SKU={$v->getSku()} | {$days} days | {$price_str} AUD\n";
}
echo "\nDone!\n";
