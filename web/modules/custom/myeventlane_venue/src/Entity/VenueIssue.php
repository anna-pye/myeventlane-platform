<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Venue Issue entity.
 *
 * Allows users to flag issues or request changes to venues they can view.
 *
 * @ContentEntityType(
 *   id = "myeventlane_venue_issue",
 *   label = @Translation("Venue Issue"),
 *   label_collection = @Translation("Venue Issues"),
 *   label_singular = @Translation("venue issue"),
 *   label_plural = @Translation("venue issues"),
 *   label_count = @PluralTranslation(
 *     singular = "@count venue issue",
 *     plural = "@count venue issues"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "access" = "Drupal\myeventlane_venue\Entity\VenueIssueAccessControlHandler",
 *     "list_builder" = "Drupal\myeventlane_venue\VenueIssueListBuilder",
 *     "form" = {
 *       "default" = "Drupal\Core\Entity\ContentEntityForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   base_table = "myeventlane_venue_issue",
 *   admin_permission = "administer myeventlane venues",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "owner" = "uid"
 *   },
 *   links = {
 *     "collection" = "/admin/structure/myeventlane/venue-issues",
 *     "delete-form" = "/admin/structure/myeventlane/venue-issues/{myeventlane_venue_issue}/delete"
 *   }
 * )
 */
class VenueIssue extends ContentEntityBase implements EntityOwnerInterface {

  use EntityOwnerTrait;

  /**
   * Issue types.
   */
  public const TYPE_ADDRESS = 'address';
  public const TYPE_DUPLICATE = 'duplicate';
  public const TYPE_ACCESSIBILITY = 'accessibility';
  public const TYPE_INAPPROPRIATE = 'inappropriate';
  public const TYPE_OTHER = 'other';

  /**
   * Issue statuses.
   */
  public const STATUS_OPEN = 'open';
  public const STATUS_TRIAGED = 'triaged';
  public const STATUS_CLOSED = 'closed';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Reference to venue.
    $fields['venue_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Venue'))
      ->setDescription(new TranslatableMarkup('The venue this issue is about.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'myeventlane_venue')
      ->setSetting('handler', 'default')
      ->setDisplayConfigurable('view', TRUE);

    // Reporter (uid).
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Reporter'))
      ->setDescription(new TranslatableMarkup('The user who reported this issue.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default:user')
      ->setSetting('handler_settings', [
        'include_anonymous' => FALSE,
      ])
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner')
      ->setDisplayConfigurable('view', TRUE);

    // Issue type.
    $fields['type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Issue type'))
      ->setDescription(new TranslatableMarkup('The type of issue being reported.'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        self::TYPE_ADDRESS => 'Incorrect address',
        self::TYPE_DUPLICATE => 'Duplicate venue',
        self::TYPE_ACCESSIBILITY => 'Accessibility issue',
        self::TYPE_INAPPROPRIATE => 'Inappropriate content',
        self::TYPE_OTHER => 'Other',
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'list_default',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Message.
    $fields['message'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Message'))
      ->setDescription(new TranslatableMarkup('Describe the issue or requested change.'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 5,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Status.
    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setDescription(new TranslatableMarkup('The current status of this issue.'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATUS_OPEN)
      ->setSetting('allowed_values', [
        self::STATUS_OPEN => 'Open',
        self::STATUS_TRIAGED => 'Triaged',
        self::STATUS_CLOSED => 'Closed',
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 10,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'list_default',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Created timestamp.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('The time that the issue was reported.'))
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * Gets the venue.
   *
   * @return \Drupal\myeventlane_venue\Entity\Venue|null
   *   The venue entity, or NULL.
   */
  public function getVenue(): ?Venue {
    $venue = $this->get('venue_id')->entity;
    return $venue instanceof Venue ? $venue : NULL;
  }

  /**
   * Gets the issue type.
   *
   * @return string
   *   The issue type.
   */
  public function getIssueType(): string {
    return $this->get('type')->value ?? self::TYPE_OTHER;
  }

  /**
   * Gets the message.
   *
   * @return string
   *   The message.
   */
  public function getMessage(): string {
    return $this->get('message')->value ?? '';
  }

  /**
   * Gets the status.
   *
   * @return string
   *   The status.
   */
  public function getStatus(): string {
    return $this->get('status')->value ?? self::STATUS_OPEN;
  }

}
