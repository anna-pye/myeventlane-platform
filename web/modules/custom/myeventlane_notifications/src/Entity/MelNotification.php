<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\myeventlane_notifications\NotificationContext;
use Drupal\myeventlane_notifications\NotificationDomain;

/**
 * Defines a platform notification (broadcast or targeted template).
 *
 * @ContentEntityType(
 *   id = "mel_notification",
 *   label = @Translation("MEL notification"),
 *   label_collection = @Translation("MEL notifications"),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "access" = "Drupal\myeventlane_notifications\Entity\MelNotificationAccessControlHandler",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder"
 *   },
 *   base_table = "mel_notification",
 *   admin_permission = "administer mel notifications entities",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "title"
 *   },
 *   links = {
 *     "canonical" = "/admin/mel-notification/{mel_notification}"
 *   }
 * )
 */
final class MelNotification extends ContentEntityBase {

  public const STATUS_DRAFT = 'draft';
  public const STATUS_QUEUED = 'queued';
  public const STATUS_PROCESSING = 'processing';
  public const STATUS_SENT = 'sent';
  public const STATUS_PARTIALLY_FAILED = 'partially_failed';
  public const STATUS_FAILED = 'failed';
  public const STATUS_CANCELLED = 'cancelled';

  public const TYPE_SYSTEM = 'system';
  public const TYPE_EVENT = 'event';
  public const TYPE_TICKET = 'ticket';
  public const TYPE_PROMO = 'promo';
  public const TYPE_REMINDER = 'reminder';

  public const AUDIENCE_ALL = 'all';
  public const AUDIENCE_ROLE = 'role';
  public const AUDIENCE_USER_IDS = 'user_ids';
  public const AUDIENCE_EVENT_ATTENDEES = 'event_attendees';
  public const AUDIENCE_VENDOR = 'vendor';

  public const PRIORITY_LOW = 'low';
  public const PRIORITY_NORMAL = 'normal';
  public const PRIORITY_HIGH = 'high';
  public const PRIORITY_CRITICAL = 'critical';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Type'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        self::TYPE_SYSTEM => 'System',
        self::TYPE_EVENT => 'Event',
        self::TYPE_TICKET => 'Ticket',
        self::TYPE_PROMO => 'Promo',
        self::TYPE_REMINDER => 'Reminder',
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Title'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 512)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['message'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Message'))
      ->setRequired(TRUE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['audience_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Audience type'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        self::AUDIENCE_ALL => 'All users',
        self::AUDIENCE_ROLE => 'Roles',
        self::AUDIENCE_USER_IDS => 'Specific users',
        self::AUDIENCE_EVENT_ATTENDEES => 'Event attendees',
        self::AUDIENCE_VENDOR => 'Vendor (organiser)',
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['audience_data'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Audience data (JSON)'))
      ->setDescription(new TranslatableMarkup('Structured audience parameters validated by AudienceResolver.'))
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['channels'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Channels'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('allowed_values', [
        'toast' => 'In-app toast',
        'email' => 'Email',
        'push_future' => 'Push (future)',
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATUS_DRAFT)
      ->setSetting('allowed_values', [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_QUEUED => 'Queued',
        self::STATUS_PROCESSING => 'Processing',
        self::STATUS_SENT => 'Sent',
        self::STATUS_PARTIALLY_FAILED => 'Partially failed',
        self::STATUS_FAILED => 'Failed',
        self::STATUS_CANCELLED => 'Cancelled',
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['scheduled_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Scheduled at'))
      ->setDescription(new TranslatableMarkup('Optional Unix timestamp; drafts are promoted when due.'))
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['priority'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Priority'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::PRIORITY_NORMAL)
      ->setSetting('allowed_values', [
        self::PRIORITY_LOW => 'Low',
        self::PRIORITY_NORMAL => 'Normal',
        self::PRIORITY_HIGH => 'High',
        self::PRIORITY_CRITICAL => 'Critical',
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['priority_score'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Priority score'))
      ->setDescription(new TranslatableMarkup('Numeric sort hint for feeds (intelligence layer).'))
      ->setDefaultValue(0)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['suppression_key'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Suppression key'))
      ->setDescription(new TranslatableMarkup('Optional key to collapse duplicate sends within a time window.'))
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['group_key'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Group key'))
      ->setDescription(new TranslatableMarkup('Optional key for client-side grouping (intelligence layer).'))
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['action_label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Action label'))
      ->setDescription(new TranslatableMarkup('Optional CTA label (e.g. “View ticket”).'))
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['action_uri'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Action URI'))
      ->setDescription(new TranslatableMarkup('Internal site path (e.g. /my-tickets) for the CTA.'))
      ->setSetting('max_length', 2048)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['action_context'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Action context'))
      ->setDescription(new TranslatableMarkup('Optional machine token for analytics/routing (not shown in UI).'))
      ->setSetting('max_length', 128)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $context_values = [];
    foreach (NotificationContext::allowed() as $key) {
      $context_values[$key] = $key;
    }

    $fields['context'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Context'))
      ->setDescription(new TranslatableMarkup('personal, business, or platform audience surface.'))
      ->setRequired(TRUE)
      ->setDefaultValue(NotificationContext::PERSONAL)
      ->setSetting('allowed_values', $context_values)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $domain_values = [];
    foreach (NotificationDomain::allowed() as $key) {
      $domain_values[$key] = $key;
    }

    $fields['domain'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Domain'))
      ->setDescription(new TranslatableMarkup('What happened (tickets, sales, refunds, etc.).'))
      ->setRequired(TRUE)
      ->setDefaultValue(NotificationDomain::PLATFORM)
      ->setSetting('allowed_values', $domain_values)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['route_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Route name'))
      ->setDescription(new TranslatableMarkup('Drupal route for deep-linking (preferred over action_uri).'))
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['route_parameters'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Route parameters (JSON)'))
      ->setDescription(new TranslatableMarkup('JSON object of route parameters for route_name.'))
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['event_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Event'))
      ->setDescription(new TranslatableMarkup('Optional event used to scope organiser updates.'))
      ->setSetting('target_type', 'node')
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['requires_action'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Requires organiser action'))
      ->setDescription(new TranslatableMarkup('True when an organiser must make a decision or complete a task.'))
      ->setDefaultValue(FALSE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    return $fields;
  }

}
