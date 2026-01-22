<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_attendees\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Event Attendee entity.
 *
 * This is the unified attendance record for MyEventLane. All attendance—whether
 * from RSVP submissions or ticket purchases—is stored in this entity.
 *
 * @ContentEntityType(
 *   id = "event_attendee",
 *   label = @Translation("Event Attendee"),
 *   label_collection = @Translation("Event Attendees"),
 *   label_singular = @Translation("attendee"),
 *   label_plural = @Translation("attendees"),
 *   label_count = @PluralTranslation(
 *     singular = "@count attendee",
 *     plural = "@count attendees"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "list_builder" = "Drupal\myeventlane_event_attendees\EventAttendeeListBuilder",
 *     "views_data" = "Drupal\myeventlane_event_attendees\EventAttendeeViewsData",
 *     "form" = {
 *       "default" = "Drupal\Core\Entity\ContentEntityForm",
 *       "add" = "Drupal\Core\Entity\ContentEntityForm",
 *       "edit" = "Drupal\Core\Entity\ContentEntityForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "access" = "Drupal\myeventlane_event_attendees\EventAttendeeAccessControlHandler"
 *   },
 *   base_table = "event_attendee",
 *   admin_permission = "administer event attendees",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "name",
 *     "owner" = "uid"
 *   },
 *   links = {
 *     "canonical" = "/admin/structure/event-attendees/{event_attendee}",
 *     "collection" = "/admin/structure/event-attendees",
 *     "add-form" = "/admin/structure/event-attendees/add",
 *     "edit-form" = "/admin/structure/event-attendees/{event_attendee}/edit",
 *     "delete-form" = "/admin/structure/event-attendees/{event_attendee}/delete"
 *   }
 * )
 */
class EventAttendee extends ContentEntityBase implements EntityChangedInterface, EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * Attendance source constants.
   */
  public const SOURCE_RSVP = 'rsvp';
  public const SOURCE_TICKET = 'ticket';
  public const SOURCE_MANUAL = 'manual';

  /**
   * Status constants.
   */
  public const STATUS_CONFIRMED = 'confirmed';
  public const STATUS_WAITLIST = 'waitlist';
  public const STATUS_CANCELLED = 'cancelled';
  public const STATUS_CHECKED_IN = 'checked_in';
  public const STATUS_NO_SHOW = 'no_show';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Add owner field from trait.
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    // Event node reference.
    $fields['event'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Event'))
      ->setDescription(new TranslatableMarkup('The event this attendance record belongs to.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'node')
      ->setSetting('handler_settings', ['target_bundles' => ['event' => 'event']])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -10,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Attendee name.
    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setDescription(new TranslatableMarkup('Full name of the attendee.'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Attendee email.
    $fields['email'] = BaseFieldDefinition::create('email')
      ->setLabel(new TranslatableMarkup('Email'))
      ->setDescription(new TranslatableMarkup('Email address of the attendee.'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'email_default',
        'weight' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'email_mailto',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Phone (optional).
    $fields['phone'] = BaseFieldDefinition::create('telephone')
      ->setLabel(new TranslatableMarkup('Phone'))
      ->setDescription(new TranslatableMarkup('Phone number of the attendee (optional).'))
      ->setRequired(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'telephone_default',
        'weight' => 5,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'telephone_link',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Attendance status.
    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setDescription(new TranslatableMarkup('Current attendance status.'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATUS_CONFIRMED)
      ->setSettings([
        'allowed_values' => [
          self::STATUS_CONFIRMED => 'Confirmed',
          self::STATUS_WAITLIST => 'Waitlist',
          self::STATUS_CANCELLED => 'Cancelled',
          self::STATUS_CHECKED_IN => 'Checked in',
          self::STATUS_NO_SHOW => 'No show',
        ],
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

    // Source of attendance record.
    $fields['source'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Source'))
      ->setDescription(new TranslatableMarkup('How this attendance was created.'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::SOURCE_RSVP)
      ->setSettings([
        'allowed_values' => [
          self::SOURCE_RSVP => 'RSVP',
          self::SOURCE_TICKET => 'Ticket purchase',
          self::SOURCE_MANUAL => 'Manual entry',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 15,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'list_default',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Commerce order item reference (for ticket purchases).
    $fields['order_item'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Order item'))
      ->setDescription(new TranslatableMarkup('The Commerce order item if this attendance is from a ticket purchase.'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'commerce_order_item')
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Ticket code (unique identifier for check-in).
    $fields['ticket_code'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Ticket code'))
      ->setDescription(new TranslatableMarkup('Unique code for check-in and verification.'))
      ->setRequired(FALSE)
      ->setSettings([
        'max_length' => 64,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 25,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Check-in timestamp.
    $fields['checked_in_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Checked in at'))
      ->setDescription(new TranslatableMarkup('When this attendee was checked in.'))
      ->setRequired(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 30,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Promotion timestamp (when promoted from waitlist).
    $fields['promoted_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Promoted at'))
      ->setDescription(new TranslatableMarkup('When this attendee was promoted from the waitlist.'))
      ->setRequired(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 32,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Accessibility needs (optional).
    $fields['accessibility_needs'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Accessibility needs'))
      ->setDescription(new TranslatableMarkup('Accessibility requirements or needs for this attendee (optional).'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler_settings', [
        'target_bundles' => ['accessibility' => 'accessibility'],
      ])
      ->setCardinality(-1)
      ->setDisplayOptions('form', [
        'type' => 'options_buttons',
        'weight' => 40,
        'settings' => [],
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => 40,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Extra data (for custom questions).
    $fields['extra_data'] = BaseFieldDefinition::create('map')
      ->setLabel(new TranslatableMarkup('Extra data'))
      ->setDescription(new TranslatableMarkup('Additional attendee data from custom questions.'))
      ->setRequired(FALSE);

    // User reference (if attendee is a registered user).
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('User'))
      ->setDescription(new TranslatableMarkup('The user account if the attendee is registered.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default:user')
      ->setDefaultValueCallback(static::class . '::getCurrentUserId')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 35,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Created timestamp.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('When this attendance record was created.'));

    // Changed timestamp.
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('When this attendance record was last updated.'));

    return $fields;
  }

  /**
   * Gets the attendee's name.
   */
  public function getName(): string {
    return $this->get('name')->value ?? '';
  }

  /**
   * Sets the attendee's name.
   */
  public function setName(string $name): static {
    $this->set('name', $name);
    return $this;
  }

  /**
   * Gets the attendee's email.
   */
  public function getEmail(): string {
    return $this->get('email')->value ?? '';
  }

  /**
   * Sets the attendee's email.
   */
  public function setEmail(string $email): static {
    $this->set('email', $email);
    return $this;
  }

  /**
   * Gets the attendance status.
   */
  public function getStatus(): string {
    return $this->get('status')->value ?? self::STATUS_CONFIRMED;
  }

  /**
   * Sets the attendance status.
   */
  public function setStatus(string $status): static {
    $this->set('status', $status);
    return $this;
  }

  /**
   * Gets the attendance source.
   */
  public function getSource(): string {
    return $this->get('source')->value ?? self::SOURCE_RSVP;
  }

  /**
   * Sets the attendance source.
   */
  public function setSource(string $source): static {
    $this->set('source', $source);
    return $this;
  }

  /**
   * Gets the event entity.
   */
  public function getEvent(): ?object {
    return $this->get('event')->entity;
  }

  /**
   * Gets the event ID.
   */
  public function getEventId(): ?int {
    return $this->get('event')->target_id ? (int) $this->get('event')->target_id : NULL;
  }

  /**
   * Sets the event.
   */
  public function setEvent(object|int $event): static {
    $this->set('event', is_object($event) ? $event->id() : $event);
    return $this;
  }

  /**
   * Gets the ticket code.
   */
  public function getTicketCode(): ?string {
    return $this->get('ticket_code')->value;
  }

  /**
   * Sets the ticket code.
   */
  public function setTicketCode(string $code): static {
    $this->set('ticket_code', $code);
    return $this;
  }

  /**
   * Checks if the attendee is checked in.
   */
  public function isCheckedIn(): bool {
    return $this->getStatus() === self::STATUS_CHECKED_IN;
  }

  /**
   * Marks the attendee as checked in.
   */
  public function checkIn(): static {
    $this->setStatus(self::STATUS_CHECKED_IN);
    $this->set('checked_in_at', \Drupal::time()->getRequestTime());
    return $this;
  }

  /**
   * Checks if the attendee is on the waitlist.
   */
  public function isWaitlisted(): bool {
    return $this->getStatus() === self::STATUS_WAITLIST;
  }

  /**
   * Checks if the attendance is from a ticket purchase.
   */
  public function isFromTicket(): bool {
    return $this->getSource() === self::SOURCE_TICKET;
  }

  /**
   * Checks if the attendance is from an RSVP.
   */
  public function isFromRsvp(): bool {
    return $this->getSource() === self::SOURCE_RSVP;
  }

  /**
   * Gets the created timestamp.
   *
   * @return int
   *   The created timestamp.
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * Sets the created timestamp.
   *
   * @param int $timestamp
   *   The created timestamp.
   *
   * @return static
   *   The entity.
   */
  public function setCreatedTime(int $timestamp): static {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * Default value callback for the uid field.
   *
   * Returns the current user ID if the user is authenticated.
   *
   * @return array
   *   An array containing the target_id for the entity reference field.
   */
  public static function getCurrentUserId(): array {
    $current_user = \Drupal::currentUser();
    $uid = $current_user->id();

    // Return empty array for anonymous users (no default value).
    if ($uid === 0) {
      return [];
    }

    return [['target_id' => $uid]];
  }

}
