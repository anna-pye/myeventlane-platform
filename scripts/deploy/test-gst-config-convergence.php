<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$autoload = getenv('MEL_VENDOR_AUTOLOAD') ?: $root . '/vendor/autoload.php';
require $autoload;

use Symfony\Component\Yaml\Yaml;

$failures = [];

foreach (['recurring_product_variation', 'recurring_standalone'] as $bundle) {
  $path = $root . '/config/sync/commerce_order.commerce_order_item_type.' . $bundle . '.yml';
  $config = Yaml::parseFile($path);
  if (($config['third_party_settings']['commerce_tax']['taxable_type'] ?? NULL) !== 'events') {
    $failures[] = $bundle . ' must remain taxable as events.';
  }

  $keys = array_keys($config);
  $expectedTail = ['id', 'label', 'traits', 'locked', 'purchasableEntityType', 'orderType'];
  if (array_slice($keys, -count($expectedTail)) !== $expectedTail) {
    $failures[] = $bundle . ' is not in Drupal-normalised key order.';
  }
}

$taxFields = [
  'field_acnc_status',
  'field_dgr_status',
  'field_gst_effective_date',
  'field_gst_registration_status',
  'field_tax_declaration_at',
  'field_tax_entity_type',
];
$displays = [
  'core.entity_form_display.myeventlane_vendor.myeventlane_vendor.default',
  'core.entity_view_display.myeventlane_vendor.myeventlane_vendor.default',
  'core.entity_view_display.myeventlane_vendor.myeventlane_vendor.full',
];

foreach ($displays as $display) {
  $config = Yaml::parseFile($root . '/config/sync/' . $display . '.yml');
  $dependencies = $config['dependencies']['config'] ?? [];
  foreach ($taxFields as $field) {
    $fieldConfig = 'field.field.myeventlane_vendor.myeventlane_vendor.' . $field;
    if (!in_array($fieldConfig, $dependencies, TRUE)) {
      $failures[] = $display . ' is missing dependency ' . $fieldConfig . '.';
    }
    if (($config['hidden'][$field] ?? NULL) !== TRUE) {
      $failures[] = $display . ' must explicitly hide ' . $field . '.';
    }
  }
}

if ($failures !== []) {
  fwrite(STDERR, "GST config convergence test failed:\n- " . implode("\n- ", $failures) . "\n");
  exit(1);
}

fwrite(STDOUT, "GST config convergence test passed.\n");
