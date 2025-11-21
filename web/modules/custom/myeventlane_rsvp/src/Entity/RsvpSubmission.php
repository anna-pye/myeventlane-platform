<?php

namespace Drupal\myeventlane_rsvp\Entity;

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
 *     "list_builder" = "Drupal\myeventlane_rsvp\RsvpSubmissionListBuilder",
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

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['attendee_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Attendee name'))
      ->setRequired(TRUE);

    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Status'))
      ->setDefaultValue('confirmed');

    $fields['event_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Event'))
      ->setRequired(TRUE);

    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('User'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback('Drupal\user\EntityOwner::getCurrentUserId');

    $fields['changed'] = BaseFieldDefinition::create('changed');

    return $fields;
  }

  public function getStatus(): string {
    return $this->get('status')->value;
  }

  public function setStatus(string $status): static {
    $this->set('status', $status);
    return $this;
  }

  public function getAttendeeName(): ?string {
    return $this->get('attendee_name')->value;
  }

  public function setAttendeeName(string $name): static {
    $this->set('attendee_name', $name);
    return $this;
  }

  public function getEventId(): ?int {
    return $this->get('event_id')->value;
  }

  public function setEventId(int $nid): static {
    $this->set('event_id', $nid);
    return $this;
  }

}