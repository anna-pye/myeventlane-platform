<?php

declare(strict_types=1);

/**
 * @file
 * Post-update hooks for myeventlane_tickets.
 */

use Drupal\myeventlane_tickets\Entity\Ticket;

/**
 * Add mel_ticket_type field storage to issued tickets.
 */
function myeventlane_tickets_post_update_mel_ticket_type_field(): void {
  $update_manager = \Drupal::entityDefinitionUpdateManager();
  if ($update_manager->getFieldStorageDefinition('mel_ticket_type', 'myeventlane_ticket')) {
    return;
  }
  $entity_type = \Drupal::entityTypeManager()->getDefinition('myeventlane_ticket');
  $definitions = Ticket::baseFieldDefinitions($entity_type);
  if (empty($definitions['mel_ticket_type'])) {
    throw new \RuntimeException('mel_ticket_type base field missing from Ticket definition.');
  }
  $update_manager->installFieldStorageDefinition(
    'mel_ticket_type',
    'myeventlane_ticket',
    'myeventlane_tickets',
    $definitions['mel_ticket_type']
  );
}
