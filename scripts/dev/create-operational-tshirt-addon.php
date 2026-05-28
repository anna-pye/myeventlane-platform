<?php

/**
 * @file
 * One-off dev script: published operational T-shirt add-on for an event.
 *
 * Usage (local/staging only):
 *   ddev drush php:script scripts/dev/create-operational-tshirt-addon.php -- 1540
 */

declare(strict_types=1);

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\node\NodeInterface;

$event_id = isset($extra[0]) ? (int) $extra[0] : 0;
if ($event_id < 1) {
  throw new \InvalidArgumentException('Pass an event node ID, e.g. ddev drush php:script scripts/dev/create-operational-tshirt-addon.php -- 1540');
}

$entity_type_manager = \Drupal::entityTypeManager();
$node_storage = $entity_type_manager->getStorage('node');
$event = $node_storage->load($event_id);
if (!$event instanceof NodeInterface) {
  $hint = '';
  $recent = $node_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'event')
    ->condition('status', 1)
    ->sort('nid', 'DESC')
    ->range(0, 5)
    ->execute();
  if ($recent !== []) {
    $hint = ' Recent published event IDs in this database: ' . implode(', ', $recent) . '.';
  }
  throw new \InvalidArgumentException("Node {$event_id} was not found in this database.{$hint} Use an event ID that exists here, or run this script against staging where event 1540 lives.");
}
if ($event->bundle() !== 'event') {
  throw new \InvalidArgumentException("Node {$event_id} exists but bundle is \"{$event->bundle()}\" (expected \"event\").");
}

$store = NULL;
if ($event->hasField('field_event_store') && !$event->get('field_event_store')->isEmpty()) {
  $store = $event->get('field_event_store')->entity;
}
if (!$store && $event->hasField('field_product_target') && !$event->get('field_product_target')->isEmpty()) {
  $ticket_product = $event->get('field_product_target')->entity;
  if ($ticket_product && method_exists($ticket_product, 'getStores')) {
    $stores = $ticket_product->getStores();
    $store = reset($stores) ?: NULL;
  }
}
if (!$store) {
  throw new \RuntimeException("Could not resolve Commerce store for event {$event_id}. Set field_event_store or link a ticket product first.");
}

$product_type_storage = $entity_type_manager->getStorage('commerce_product_type');
$variation_type_storage = $entity_type_manager->getStorage('commerce_product_variation_type');
if (!$product_type_storage->load('operational_merchandise')) {
  throw new \RuntimeException('Missing product type: operational_merchandise. Import config before running this script.');
}
if (!$variation_type_storage->load('operational_merchandise_var')) {
  throw new \RuntimeException('Missing variation type: operational_merchandise_var. Import config before running this script.');
}

$product_storage = $entity_type_manager->getStorage('commerce_product');
$variation_storage = $entity_type_manager->getStorage('commerce_product_variation');

$sku = "MEL-{$event_id}-TSHIRT";
$existing_variations = $variation_storage->loadByProperties([
  'sku' => $sku,
  'type' => 'operational_merchandise_var',
]);
$variation = reset($existing_variations);
if (!$variation instanceof ProductVariation) {
  $variation = ProductVariation::create([
    'type' => 'operational_merchandise_var',
    'sku' => $sku,
    'title' => 'T-shirt',
    'price' => new Price('35.00', 'AUD'),
    'status' => TRUE,
  ]);
  $variation->save();
}

$product_title = "Event T-shirt - Node {$event_id}";
$query = $product_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'operational_merchandise')
  ->condition('title', $product_title)
  ->range(0, 1);
$pids = $query->execute();
$product = $pids ? $product_storage->load(reset($pids)) : NULL;

$payload = [
  'operational_product_type' => 'merch_pickup',
  'fulfillment_mode' => 'pickup',
  'reservation_mode' => 'commerce_cart',
  'pickup_mode' => 'venue_pickup',
  'readiness_mode' => 'ready',
  'continuity_mode' => 'collect_after_entry',
  'capability_reference' => 'operational_merchandise',
  'customer_visibility' => 'visible',
  'collection_rules' => [],
  'hospitality_rules' => [],
  'operational_summary' => 'Collect your T-shirt at the venue.',
  'operational_chips' => [
    ['label' => 'Venue pickup', 'tone' => 'info'],
    ['label' => 'Add-on', 'tone' => 'muted'],
  ],
];

if (!$product instanceof Product) {
  $product = Product::create([
    'type' => 'operational_merchandise',
    'title' => $product_title,
    'stores' => [$store],
    'variations' => [$variation],
    'status' => TRUE,
  ]);
}
else {
  $product->set('stores', [$store]);
  $product->set('variations', [$variation]);
  $product->set('status', TRUE);
}

if (!$product->hasField('field_event')) {
  throw new \RuntimeException('Product bundle is missing field_event.');
}
$product->set('field_event', ['target_id' => $event_id]);

if (!$product->hasField('field_mel_operational_product')) {
  throw new \RuntimeException('Product bundle is missing field_mel_operational_product.');
}
$product->set('field_mel_operational_product', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$product->save();
$variation->save();

$book_path = '/event/' . $event_id . '/book';
echo "Created/reused operational T-shirt add-on\n";
echo "Event ID: {$event_id}\n";
echo "Store ID: {$store->id()}\n";
echo "Product ID: {$product->id()}\n";
echo "Variation ID: {$variation->id()}\n";
echo "SKU: {$sku}\n";
echo "Product edit: /admin/commerce/products/{$product->id()}/edit\n";
echo "QA booking URL: {$book_path}\n";
echo "Node URL: /node/{$event_id}\n";
