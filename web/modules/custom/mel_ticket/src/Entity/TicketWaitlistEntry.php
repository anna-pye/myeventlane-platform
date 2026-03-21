<?php

declare(strict_types=1);

namespace Drupal\mel_ticket\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Waitlist queue entry for a sold-out paid ticket tier.
 *
 * @ContentEntityType(
 *   id = "mel_ticket_waitlist_entry",
 *   label = @Translation("Ticket tier waitlist entry"),
 *   label_collection = @Translation("Ticket tier waitlist entries"),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *   },
 *   base_table = "mel_ticket_waitlist_entry",
 *   admin_permission = "administer mel_ticket_type entities",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 * )
 */
final class TicketWaitlistEntry extends ContentEntityBase {

  public const STATUS_WAITING = 'waiting';

  public const STATUS_OFFERED = 'offered';

  public const STATUS_ACCEPTED = 'accepted';

  public const STATUS_EXPIRED = 'expired';

  public const STATUS_CANCELLED = 'cancelled';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['event'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Event'))
      ->setSetting('target_type', 'node')
      ->setSetting('handler_settings', ['target_bundles' => ['event' => 'event']])
      ->setRequired(TRUE);

    $fields['ticket_type'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Ticket tier'))
      ->setSetting('target_type', 'mel_ticket_type')
      ->setRequired(TRUE);

    $fields['mail'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Email'))
      ->setSetting('max_length', 254)
      ->setRequired(TRUE);

    $fields['mail_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Email hash'))
      ->setDescription(t('SHA-256 of the normalised email for privacy-safe lookups.'))
      ->setSetting('max_length', 128)
      ->setRequired(FALSE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('User'))
      ->setSetting('target_type', 'user')
      ->setRequired(FALSE);

    $fields['quantity'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Requested quantity'))
      ->setRequired(TRUE)
      ->setDefaultValue(1);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATUS_WAITING)
      ->setSetting('allowed_values', [
        self::STATUS_WAITING => 'Waiting',
        self::STATUS_OFFERED => 'Offered',
        self::STATUS_ACCEPTED => 'Accepted',
        self::STATUS_EXPIRED => 'Expired',
        self::STATUS_CANCELLED => 'Cancelled',
      ]);

    $fields['offer_expires'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Offer expires'))
      ->setDescription(t('Unix timestamp when an offered slot expires.'))
      ->setRequired(FALSE);

    $fields['offer_token'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Offer token'))
      ->setSetting('max_length', 128)
      ->setRequired(FALSE);

    $fields['offer_reserved'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Offer reserved quantity'))
      ->setDescription(t('Tickets reserved while the offer is active.'))
      ->setRequired(FALSE)
      ->setDefaultValue(0);

    $fields['offer_mail_sent'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Offer notification sent'))
      ->setDescription(t('TRUE once the waitlist-offer email has been queued or sent for this offer.'))
      ->setDefaultValue(FALSE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

}
