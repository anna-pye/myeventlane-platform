<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;

/**
 * Issued ticket entity (one per entitlement).
 *
 * @ContentEntityType(
 *   id = "myeventlane_ticket",
 *   label = @Translation("Ticket"),
 *   label_collection = @Translation("Tickets"),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "default" = "Drupal\Core\Entity\ContentEntityForm",
 *       "add" = "Drupal\Core\Entity\ContentEntityForm",
 *       "edit" = "Drupal\Core\Entity\ContentEntityForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     }
 *   },
 *   base_table = "myeventlane_ticket",
 *   data_table = "myeventlane_ticket_field_data",
 *   admin_permission = "administer myeventlane tickets",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "ticket_code",
 *     "owner" = "purchaser_uid"
 *   },
 *   links = {
 *     "collection" = "/admin/content/myeventlane/tickets"
 *   }
 * )
 */
final class Ticket extends ContentEntityBase {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  public const ENTITLEMENT_TICKET = 'ticket';
  public const ENTITLEMENT_MERCH = 'merch';
  public const ENTITLEMENT_PARKING = 'parking';
  public const ENTITLEMENT_DRINK = 'drink';
  public const ENTITLEMENT_FOOD = 'food';
  public const ENTITLEMENT_VIP = 'vip';
  public const ENTITLEMENT_ADDON = 'addon';

  public const FULFILMENT_PENDING = 'pending';
  public const FULFILMENT_READY = 'ready';
  public const FULFILMENT_COLLECTED = 'collected';
  public const FULFILMENT_REDEEMED = 'redeemed';
  public const FULFILMENT_EXPIRED = 'expired';
  public const FULFILMENT_CANCELLED = 'cancelled';

  public const STATUS_ISSUED_UNASSIGNED = 'issued_unassigned';
  public const STATUS_ASSIGNED = 'assigned';
  public const STATUS_CHECKED_IN = 'checked_in';
  public const STATUS_REFUNDED = 'refunded';
  public const STATUS_VOID = 'void';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Ticket code used for QR/PDF lookup. Must be unique + unguessable.
    $fields['ticket_code'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Ticket code'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => 0])
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 0])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', TRUE);

    // Event node reference.
    $fields['event_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Event'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('view', ['type' => 'entity_reference_label', 'weight' => 1])
      ->setDisplayConfigurable('view', TRUE);

    // Commerce references (nullable for RSVP if you later link RSVP to tickets).
    $fields['order_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Order'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'commerce_order');

    $fields['order_item_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Order item'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'commerce_order_item');

    $fields['purchased_entity'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Purchased variation'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'commerce_product_variation');

    // Optional pointer to the paragraph ticket type config on the event.
    $fields['ticket_type_config'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Ticket type config (paragraph)'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'paragraph');

    // Preferred pointer to mel_ticket_type for new ticket definitions.
    $fields['mel_ticket_type'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Ticket type (MEL)'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'mel_ticket_type');

    // Purchaser = entity owner.
    $fields['purchaser_uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Purchaser'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'user');

    // Holder identity: required before check-in and before PDF access if you want.
    $fields['holder_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Holder name'))
      ->setRequired(FALSE)
      ->setSetting('max_length', 255);

    $fields['holder_email'] = BaseFieldDefinition::create('email')
      ->setLabel(t('Holder email'))
      ->setRequired(FALSE);

    // Status lifecycle.
    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        self::STATUS_ISSUED_UNASSIGNED => 'Issued (unassigned)',
        self::STATUS_ASSIGNED => 'Assigned',
        self::STATUS_CHECKED_IN => 'Checked in',
        self::STATUS_REFUNDED => 'Refunded',
        self::STATUS_VOID => 'Void',
      ])
      ->setDefaultValue(self::STATUS_ISSUED_UNASSIGNED);

    $fields['checked_in_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Checked in at'))
      ->setRequired(FALSE);

    $fields['checked_in_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Checked in by'))
      ->setDescription(t('User account that performed ticket check-in.'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'user');

    $fields['entitlement_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Entitlement type'))
      ->setDescription(t('Operational entitlement represented by this ticket row.'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        self::ENTITLEMENT_TICKET => 'Ticket',
        self::ENTITLEMENT_MERCH => 'Merch pickup',
        self::ENTITLEMENT_PARKING => 'Parking',
        self::ENTITLEMENT_DRINK => 'Drink voucher',
        self::ENTITLEMENT_FOOD => 'Food voucher',
        self::ENTITLEMENT_VIP => 'VIP access',
        self::ENTITLEMENT_ADDON => 'Event add-on',
      ])
      ->setDefaultValue(self::ENTITLEMENT_TICKET);

    $fields['redemption_limit'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Redemption limit'))
      ->setDescription(t('Maximum successful operational redemptions allowed.'))
      ->setRequired(FALSE)
      ->setDefaultValue(1);

    $fields['redemption_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Redemption count'))
      ->setDescription(t('Number of successful operational redemptions recorded.'))
      ->setRequired(FALSE)
      ->setDefaultValue(0);

    $fields['fulfilment_status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Fulfilment status'))
      ->setDescription(t('Operational fulfilment state for pickup and redemption workflows.'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        self::FULFILMENT_PENDING => 'Pending',
        self::FULFILMENT_READY => 'Ready',
        self::FULFILMENT_COLLECTED => 'Collected',
        self::FULFILMENT_REDEEMED => 'Redeemed',
        self::FULFILMENT_EXPIRED => 'Expired',
        self::FULFILMENT_CANCELLED => 'Cancelled',
      ])
      ->setDefaultValue(self::FULFILMENT_PENDING);

    $fields['collect_window'] = BaseFieldDefinition::create('daterange')
      ->setLabel(t('Collection window'))
      ->setDescription(t('Optional start and end window for pickup or redemption.'))
      ->setRequired(FALSE)
      ->setSetting('datetime_type', 'datetime');

    $fields['collect_location'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Collection location'))
      ->setDescription(t('Human-readable collection or redemption location.'))
      ->setRequired(FALSE);

    $fields['vehicle_registration'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Vehicle registration'))
      ->setDescription(t('Optional vehicle registration for parking passes.'))
      ->setRequired(FALSE)
      ->setSetting('max_length', 32);

    $fields['vendor_reference'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Vendor reference'))
      ->setDescription(t('Vendor responsible for fulfilment or redemption when scoped below the event.'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'myeventlane_vendor');

    $fields['expires_at'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Expires at'))
      ->setDescription(t('Optional entitlement expiry timestamp.'))
      ->setRequired(FALSE)
      ->setSetting('datetime_type', 'datetime');

    $fields['metadata_json'] = BaseFieldDefinition::create('map')
      ->setLabel(t('Metadata'))
      ->setDescription(t('Structured operational metadata for integrations and future fulfilment workflows.'))
      ->setRequired(FALSE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

  /**
   * Returns the operational entitlement type.
   */
  public function getEntitlementType(): string {
    $value = $this->hasField('entitlement_type') ? (string) ($this->get('entitlement_type')->value ?? '') : '';
    return $value !== '' ? $value : self::ENTITLEMENT_TICKET;
  }

  /**
   * Returns the configured redemption limit.
   */
  public function getRedemptionLimit(): int {
    if (!$this->hasField('redemption_limit') || $this->get('redemption_limit')->isEmpty()) {
      return 1;
    }
    return max(0, (int) $this->get('redemption_limit')->value);
  }

  /**
   * Returns the current redemption count.
   */
  public function getRedemptionCount(): int {
    if (!$this->hasField('redemption_count') || $this->get('redemption_count')->isEmpty()) {
      return 0;
    }
    return max(0, (int) $this->get('redemption_count')->value);
  }

  /**
   * Returns the fulfilment status.
   */
  public function getFulfilmentStatus(): string {
    $value = $this->hasField('fulfilment_status') ? (string) ($this->get('fulfilment_status')->value ?? '') : '';
    return $value !== '' ? $value : self::FULFILMENT_PENDING;
  }

  /**
   * Returns the created timestamp for QR signing and audit display.
   */
  public function getCreatedTime(): int {
    return $this->hasField('created') ? (int) $this->get('created')->value : 0;
  }

}
