<?php

namespace Drupal\myeventlane_tickets\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Stores unique ticket verification codes.
 *
 * @ContentEntityType(
 *   id = "ticket_code",
 *   label = @Translation("Ticket Code"),
 *   base_table = "ticket_code",
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *   }
 * )
 */
class TicketCode extends ContentEntityBase {

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {

    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel('ID')
      ->setReadOnly(TRUE);

    $fields['code'] = BaseFieldDefinition::create('string')
      ->setLabel('Code')
      ->setRequired(TRUE);

    $fields['order_item_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel('Order Item')
      ->setSetting('target_type', 'commerce_order_item')
      ->setRequired(TRUE);

    $fields['event_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel('Event')
      ->setSetting('target_type', 'node')
      ->setRequired(TRUE);

    return $fields;
  }

}