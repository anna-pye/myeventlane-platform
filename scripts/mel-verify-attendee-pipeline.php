<?php

/**
 * @file
 * Verifies ticket holder paragraphs ↔ event_attendee.extra_data ↔ CSV row.
 *
 * Usage (DDEV): cd web && drush php:script ../scripts/mel-verify-attendee-pipeline.php
 */

declare(strict_types=1);

use Drupal\myeventlane_attendee\Attendee\TicketAttendee;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;

$storage = \Drupal::entityTypeManager()->getStorage('event_attendee');
$ids = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('source', EventAttendee::SOURCE_TICKET)
  ->sort('id', 'DESC')
  ->range(0, 5)
  ->execute();

if (!$ids) {
  echo "No ticket event_attendee records found. Run a paid checkout with custom questions first.\n";
  return;
}

foreach ($storage->loadMultiple($ids) as $attendee) {
  if (!$attendee instanceof EventAttendee) {
    continue;
  }
  $eid = $attendee->getEventId() ?? 0;
  $oiid = (int) ($attendee->get('order_item')->target_id ?? 0);
  echo "--- event_attendee {$attendee->id()} | event {$eid} | order_item {$oiid}\n";

  $extra = $attendee->getExtraDataMap();
  echo 'extra_data (raw): ' . json_encode($extra, JSON_UNESCAPED_UNICODE) . "\n";

  $adapter = new TicketAttendee($attendee);
  $row = $adapter->toExportRow();
  echo 'CSV custom_answers: ' . ($row['custom_answers'] ?? '') . "\n";

  if ($oiid > 0) {
    $oi = \Drupal::entityTypeManager()->getStorage('commerce_order_item')->load($oiid);
    if ($oi && $oi->hasField('field_ticket_holder')) {
      foreach ($oi->get('field_ticket_holder')->referencedEntities() as $delta => $holder) {
        $hid = $holder->id();
        echo "  holder delta {$delta} paragraph " . ($hid ?? 'new') . "\n";
        if ($holder->hasField('field_attendee_questions')) {
          foreach ($holder->get('field_attendee_questions')->referencedEntities() as $qdelta => $q) {
            $label = $q->hasField('field_question_label') ? (string) ($q->get('field_question_label')->value ?? '') : '';
            $machine = $q->hasField('field_question_machine_name') ? (string) ($q->get('field_question_machine_name')->value ?? '') : '';
            $ans = $q->hasField('field_attendee_extra_field') ? (string) ($q->get('field_attendee_extra_field')->value ?? '') : '';
            echo "    Q{$qdelta} machine={$machine} label={$label} answer_nonempty=" . ($ans !== '' ? 'yes' : 'no') . "\n";
          }
        }
        else {
          echo "    (no field_attendee_questions on holder)\n";
        }
      }
    }
    else {
      echo "  (order item missing or no field_ticket_holder)\n";
    }
  }
  echo "\n";
}

echo "Vendor list uses the same extra_data as JSON/map; template renders Label: value (see attendees.html.twig).\n";
