<?php

namespace Drupal\myeventlane_rsvp\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\user\EntityOwnerTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the RSVP Submission entity.
 *
 * @ContentEntityType(
 *   id = "rsvp_submission",
 *   label = @Translation("RSVP Submission"),
 *   base_table = "rsvp_submission",
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\myeventlane_rsvp\Entity\RsvpSubmissionListBuilder",
 *     "views_data" = "Drupal\myeventlane_rsvp\Entity\RsvpSubmissionViewsData",
 *     "form" = {
 *       "add" = "Drupal\myeventlane_rsvp\Form\RsvpSubmissionForm",
 *       "edit" = "Drupal\myeventlane_rsvp\Form\RsvpSubmissionForm",
 *       "delete" = "Drupal\myeventlane_rsvp\Form\RsvpSubmissionDeleteForm"
 *     }
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "uid" = "user_id",
 *     "label" = "attendee_name"
 *   }
 * )
 */
class RsvpSubmission extends ContentEntityBase implements RsvpSubmissionInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Attendee name field (label field).
    $fields['attendee_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Attendee name'))
      ->setDescription(t('The full name of the attendee.'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Alias: 'name' field for compatibility with RsvpPublicForm.
    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setDescription(t('Alias for attendee_name for form compatibility.'))
      ->setRequired(FALSE)
      ->setSettings([
        'max_length' => 255,
      ])
      ->setComputed(FALSE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    // Email field.
    $fields['email'] = BaseFieldDefinition::create('email')
      ->setLabel(t('Email'))
      ->setDescription(t('The email address of the attendee.'))
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'email_mailto',
        'weight' => 1,
      ])
      ->setDisplayOptions('form', [
        'type' => 'email_default',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Guests count field.
    $fields['guests'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Number of guests'))
      ->setDescription(t('The total number of guests including the primary attendee.'))
      ->setRequired(FALSE)
      ->setDefaultValue(1)
      ->setSetting('min', 1)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_integer',
        'weight' => 2,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Phone field (optional).
    $fields['phone'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Phone'))
      ->setDescription(t('The phone number of the attendee (optional).'))
      ->setRequired(FALSE)
      ->setSettings([
        'max_length' => 32,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 3,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Status field.
    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Status'))
      ->setDescription(t('The RSVP status: confirmed, waitlist, cancelled.'))
      ->setRequired(TRUE)
      ->setDefaultValue('confirmed')
      ->setSettings([
        'max_length' => 32,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 4,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Event reference field.
    $fields['event_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Event'))
      ->setDescription(t('The event this RSVP is for.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default')
      ->setSetting('handler_settings', ['target_bundles' => ['event' => 'event']])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => 5,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 5,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // User reference field (optional, for logged-in users).
    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('User'))
      ->setDescription(t('The Drupal user who submitted this RSVP (if logged in).'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'user')
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => 6,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Donation field (optional).
    $fields['donation'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Donation'))
      ->setDescription(t('Optional donation amount in AUD.'))
      ->setRequired(FALSE)
      ->setDefaultValue(0)
      ->setSetting('precision', 10)
      ->setSetting('scale', 2)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_decimal',
        'weight' => 10,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Created timestamp.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the RSVP was submitted.'));

    // Changed timestamp.
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the RSVP was last updated.'));

    // Check-in status.
    $fields['checked_in'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Checked in'))
      ->setDescription(t('Whether this attendee has checked in.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'boolean',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Check-in timestamp.
    $fields['checked_in_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Checked in at'))
      ->setDescription(t('The time the attendee checked in.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 16,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return $this->get('status')->value ?? 'confirmed';
  }

  /**
   * {@inheritdoc}
   */
  public function setStatus(string $status): static {
    $this->set('status', $status);
    return $this;
  }

  /**
   * Gets the attendee name.
   *
   * @return string|null
   *   The attendee name.
   */
  public function getAttendeeName(): ?string {
    return $this->get('attendee_name')->value;
  }

  /**
   * Sets the attendee name.
   *
   * @param string $name
   *   The attendee name.
   *
   * @return static
   *   The entity.
   */
  public function setAttendeeName(string $name): static {
    $this->set('attendee_name', $name);
    // Also set the 'name' alias for compatibility.
    $this->set('name', $name);
    return $this;
  }

  /**
   * Gets the event ID.
   *
   * @return int|null
   *   The event node ID.
   */
  public function getEventId(): ?int {
    $value = $this->get('event_id')->target_id;
    return $value ? (int) $value : NULL;
  }

  /**
   * Sets the event ID.
   *
   * @param int $nid
   *   The event node ID.
   *
   * @return static
   *   The entity.
   */
  public function setEventId(int $nid): static {
    $this->set('event_id', ['target_id' => $nid]);
    return $this;
  }

  /**
   * Gets the event entity.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The event node.
   */
  public function getEvent(): ?object {
    return $this->get('event_id')->entity;
  }

  /**
   * Gets the email address.
   *
   * @return string|null
   *   The email address.
   */
  public function getEmail(): ?string {
    return $this->get('email')->value;
  }

  /**
   * Sets the email address.
   *
   * @param string $email
   *   The email address.
   *
   * @return static
   *   The entity.
   */
  public function setEmail(string $email): static {
    $this->set('email', $email);
    return $this;
  }

  /**
   * Gets the number of guests.
   *
   * @return int
   *   The number of guests.
   */
  public function getGuests(): int {
    return (int) ($this->get('guests')->value ?? 1);
  }

  /**
   * Sets the number of guests.
   *
   * @param int $guests
   *   The number of guests.
   *
   * @return static
   *   The entity.
   */
  public function setGuests(int $guests): static {
    $this->set('guests', $guests);
    return $this;
  }

  /**
   * Gets the donation amount.
   *
   * @return float
   *   The donation amount.
   */
  public function getDonation(): float {
    return (float) ($this->get('donation')->value ?? 0);
  }

  /**
   * Sets the donation amount.
   *
   * @param float $amount
   *   The donation amount.
   *
   * @return static
   *   The entity.
   */
  public function setDonation(float $amount): static {
    $this->set('donation', $amount);
    return $this;
  }

  /**
   * Checks if the attendee has checked in.
   *
   * @return bool
   *   TRUE if checked in.
   */
  public function isCheckedIn(): bool {
    return (bool) $this->get('checked_in')->value;
  }

  /**
   * Marks the attendee as checked in.
   *
   * @return static
   *   The entity.
   */
  public function checkIn(): static {
    $this->set('checked_in', TRUE);
    $this->set('checked_in_at', \Drupal::time()->getRequestTime());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);

    // If 'name' is set but 'attendee_name' is not, sync them.
    if (!$this->get('name')->isEmpty() && $this->get('attendee_name')->isEmpty()) {
      $this->set('attendee_name', $this->get('name')->value);
    }
    // If 'attendee_name' is set but 'name' is not, sync the other way.
    elseif (!$this->get('attendee_name')->isEmpty() && $this->get('name')->isEmpty()) {
      $this->set('name', $this->get('attendee_name')->value);
    }

    // Handle integer event_id being passed instead of entity reference.
    $eventIdValue = $this->get('event_id')->getValue();
    if (!empty($eventIdValue) && is_array($eventIdValue)) {
      $firstValue = reset($eventIdValue);
      if (is_numeric($firstValue)) {
        // Convert integer to entity reference format.
        $this->set('event_id', ['target_id' => (int) $firstValue]);
      }
    }
  }

}
