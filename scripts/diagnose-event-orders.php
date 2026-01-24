<?php

/**
 * @file
 * Diagnose why the Vendor Event Orders list may be empty.
 *
 * Run: ddev drush scr scripts/diagnose-event-orders.php
 * Or with an event ID: ddev drush scr scripts/diagnose-event-orders.php 456
 *
 * Prints: events and their resolved store; order-item and variation links;
 * recent completed orders and their store/state/items; step-by-step
 * discovery counts for the first event.
 */

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\node\NodeInterface;

$eid = 0;
if (isset($argv[1]) && is_numeric($argv[1])) {
  $eid = (int) $argv[1];
}

$etm = \Drupal::entityTypeManager();
$orderStorage = $etm->getStorage('commerce_order');
$orderItemStorage = $etm->getStorage('commerce_order_item');
$varStorage = $etm->getStorage('commerce_product_variation');
$nodeStorage = $etm->getStorage('node');

$INCLUDED = [
  'completed', 'partially_refunded', 'refunded',
  'placed', 'fulfilled', 'fulfillment',
];

function resolve_event_store(NodeInterface $n): ?int {
  if ($n->hasField('field_event_store') && !$n->get('field_event_store')->isEmpty()) {
    $t = $n->get('field_event_store')->target_id;
    if ($t) {
      return (int) $t;
    }
  }
  if ($n->hasField('field_event_vendor') && !$n->get('field_event_vendor')->isEmpty()) {
    $v = $n->get('field_event_vendor')->entity;
    if ($v && $v->hasField('field_vendor_store') && !$v->get('field_vendor_store')->isEmpty()) {
      $s = $v->get('field_vendor_store')->entity;
      if ($s) {
        return (int) $s->id();
      }
    }
  }
  return NULL;
}

echo "=== 1) Event nodes (up to 5) and resolved store ===\n";
$events = $nodeStorage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'event')
  ->range(0, 5)
  ->execute();
foreach ($events as $nid) {
  $n = $nodeStorage->load($nid);
  if (!$n instanceof NodeInterface) {
    continue;
  }
  $sid = resolve_event_store($n);
  echo "  nid=$nid title=" . $n->label() . " resolved_store=" . ($sid ?? 'NULL') . "\n";
}

echo "\n=== 2) Recent completed orders (up to 5, any store) ===\n";
$oids = $orderStorage->getQuery()
  ->accessCheck(FALSE)
  ->condition('state', 'completed')
  ->sort('order_id', 'DESC')
  ->range(0, 5)
  ->execute();
foreach ($oids as $oid) {
  $o = $orderStorage->load($oid);
  if (!$o instanceof OrderInterface) {
    continue;
  }
  $ostore = $o->getStoreId();
  $state = $o->getState() ? $o->getState()->getId() : '?';
  echo "  order_id=$oid store_id=" . ($ostore ?? '?') . " state=$state\n";
  foreach ($o->getItems() as $it) {
    if (!$it instanceof OrderItemInterface) {
      continue;
    }
    $ft = $it->hasField('field_target_event') && !$it->get('field_target_event')->isEmpty()
      ? (int) $it->get('field_target_event')->target_id : NULL;
    $pe = $it->getPurchasedEntity();
    $ve = $pe && $pe->hasField('field_event') && !$pe->get('field_event')->isEmpty()
      ? (int) $pe->get('field_event')->target_id : NULL;
    $vr = NULL;
    if ($pe && $pe->hasField('field_event_ref') && !$pe->get('field_event_ref')->isEmpty()) {
      $vr = (int) $pe->get('field_event_ref')->target_id;
    }
    echo "    item " . $it->id() . " field_target_event=" . ($ft ?? 'NULL') . " var.field_event=" . ($ve ?? 'NULL') . " var.field_event_ref=" . ($vr ?? 'NULL') . "\n";
  }
}

$run_for = $eid > 0 ? [$eid] : array_slice(array_keys($events), 0, 1);
if (empty($run_for)) {
  echo "\n=== 3) No event to run step-by-step. Create an event or pass nid: drush scr scripts/diagnose-event-orders.php <nid> ===\n";
  exit(0);
}

foreach ($run_for as $evId) {
  $n = $nodeStorage->load($evId);
  if (!$n instanceof NodeInterface) {
    echo "\n=== 3) Event $evId not found or not a node. ===\n";
    continue;
  }
  $storeId = resolve_event_store($n);
  echo "\n=== 3) Step-by-step for event $evId (" . $n->label() . "), resolved_store=" . ($storeId ?? 'NULL') . " ===\n";

  $oi1 = $orderItemStorage->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_target_event', $evId)
    ->execute();
  echo "  primary (order_items field_target_event=$evId): " . count($oi1) . " items\n";

  $vids = [];
  $v1 = $varStorage->getQuery()->accessCheck(FALSE)->condition('field_event', $evId)->execute();
  if ($v1) {
    $vids = array_merge($vids, array_map('intval', (array) $v1));
  }
  try {
    $v2 = $varStorage->getQuery()->accessCheck(FALSE)->condition('field_event_ref', $evId)->execute();
    if ($v2) {
      $vids = array_merge($vids, array_map('intval', (array) $v2));
    }
  } catch (\Throwable $e) {
    // field_event_ref may not exist.
  }
  $vids = array_values(array_unique(array_filter($vids)));
  $byVar = 0;
  if (!empty($vids)) {
    $oi2 = $orderItemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('purchased_entity', $vids, 'IN')
      ->execute();
    $byVar = count($oi2);
  }
  echo "  byVariation (variations field_event/field_event_ref=$evId): " . count($vids) . " variations -> $byVar order items\n";

  $storeStep = 'N/A (no store)';
  $filterStep = 'N/A';
  if ($storeId !== NULL) {
    $ordIds = $orderStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('store_id', $storeId)
      ->condition('state', $INCLUDED, 'IN')
      ->execute();
    $storeStep = count($ordIds) . " orders (store_id=$storeId, state IN included)";
    $kept = 0;
    foreach ($orderStorage->loadMultiple($ordIds) as $o) {
      if (!$o instanceof OrderInterface) {
        continue;
      }
      foreach ($o->getItems() as $it) {
        if (!$it instanceof OrderItemInterface) {
          continue;
        }
        $ok = FALSE;
        if ($it->hasField('field_target_event') && !$it->get('field_target_event')->isEmpty()) {
          if ((int) $it->get('field_target_event')->target_id === $evId) {
            $ok = TRUE;
          }
        }
        if (!$ok) {
          $pe = $it->getPurchasedEntity();
          if ($pe && $pe->hasField('field_event') && !$pe->get('field_event')->isEmpty()) {
            if ((int) $pe->get('field_event')->target_id === $evId) {
              $ok = TRUE;
            }
          }
        }
        if (!$ok && $pe = $it->getPurchasedEntity()) {
          if ($pe->hasField('field_event_ref') && !$pe->get('field_event_ref')->isEmpty()) {
            if ((int) $pe->get('field_event_ref')->target_id === $evId) {
              $ok = TRUE;
            }
          }
        }
        if ($ok) {
          $kept++;
          break;
        }
      }
    }
    $filterStep = "$kept orders with at least one item for this event";
  }
  echo "  findOrderIdsByStoreAndState: $storeStep\n";
  echo "  filterOrderIdsHavingEventItems: $filterStep\n";
}

echo "\n=== Done ===\n";
