<?php

declare(strict_types=1);

/**
 * Local-only migration acceptance check. Prints counts and hashes, never orders.
 *
 * Run with Drush php:script. Read-only unless MEL_APPLY_STOCK_HARDENING=1.
 * Take a database snapshot before opting in. Never run against production.
 */
if (getenv('IS_DDEV_PROJECT') !== 'true') {
  throw new RuntimeException('This acceptance helper is restricted to local DDEV.');
}
$db = \Drupal::database();
$fingerprint = static function (string $table, ?int $maxId = NULL) use ($db): array {
  $query = $db->select($table, 't')->fields('t');
  if ($maxId !== NULL) {
    $query->condition('id', $maxId, '<=');
  }
  $hashes = [];
  foreach ($query->execute() as $row) {
    $hashes[] = hash('sha256', serialize((array) $row));
  }
  sort($hashes);
  return ['rows' => count($hashes), 'sha256' => hash('sha256', implode('', $hashes))];
};
$orderTables = $db->schema()->findTables('commerce_order%');
$before = [];
foreach ($orderTables as $table) {
  $before[$table] = $fingerprint($table);
}
$maxId = (int) $db->query('SELECT MAX(id) FROM {commerce_stock_transaction}')->fetchField();
$stockBefore = $fingerprint('commerce_stock_transaction', $maxId);
$totals = static function () use ($db): array {
  $query = $db->select('commerce_stock_transaction', 't');
  $query->fields('t', ['entity_type', 'entity_id']);
  $query->addExpression('SUM(qty)', 'quantity');
  $query->groupBy('entity_type')->groupBy('entity_id');
  $query->orderBy('entity_type')->orderBy('entity_id');
  return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
};
$totalsBefore = $totals();
if (getenv('MEL_APPLY_STOCK_HARDENING') !== '1') {
  print json_encode(['order_tables' => count($before), 'stock_history' => $stockBefore, 'mode' => 'read-only'], JSON_PRETTY_PRINT) . PHP_EOL;
  return;
}
$transaction = $db->startTransaction();
try {
  $result = \Drupal::service('myeventlane_commerce.operational_stock_migration')->migrate();
  foreach ($before as $table => $expected) {
    if ($fingerprint($table) !== $expected) {
      throw new RuntimeException('Migration changed an existing order table. Rolling back.');
    }
  }
  if ($fingerprint('commerce_stock_transaction', $maxId) !== $stockBefore || $totals() !== $totalsBefore) {
    throw new RuntimeException('Migration changed original stock history or total inventory. Rolling back.');
  }
  unset($transaction);
  print json_encode([
    'result' => $result,
    'order_tables_unchanged' => count($before),
    'original_stock_rows_unchanged' => $stockBefore['rows'],
    'inventory_totals_unchanged' => TRUE,
    'stock_rows_after' => $fingerprint('commerce_stock_transaction')['rows'],
  ], JSON_PRETTY_PRINT) . PHP_EOL;
}
catch (\Throwable $e) {
  $transaction->rollBack();
  throw $e;
}
