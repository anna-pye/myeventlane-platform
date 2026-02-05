<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Event Review entity.
 *
 * One review per user per event.
 * Feature-flagged via myeventlane_account.reviews.
 *
 * @ContentEntityType(
 *   id = "event_review",
 *   label = @Translation("Event Review"),
 *   label_collection = @Translation("Event Reviews"),
 *   label_singular = @Translation("review"),
 *   label_plural = @Translation("reviews"),
 *   base_table = "event_review",
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "storage_schema" = "Drupal\myeventlane_account\EventReviewStorageSchema",
 *     "access" = "Drupal\myeventlane_account\EventReviewAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\myeventlane_account\Form\EventReviewForm",
 *       "edit" = "Drupal\myeventlane_account\Form\EventReviewForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     }
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "owner" = "uid"
 *   },
 *   constraints = {
 *     "UniqueUserEvent" = "Drupal\myeventlane_account\Plugin\Validation\Constraint\UniqueUserEventConstraint"
 *   }
 * )
 */
class EventReview extends ContentEntityBase implements EntityChangedInterface, EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['event_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Event'))
      ->setDescription(new TranslatableMarkup('The event this review is for.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'node')
      ->setSetting('handler_settings', ['target_bundles' => ['event' => 'event']])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['rating'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Rating'))
      ->setDescription(new TranslatableMarkup('Star rating from 1 to 5.'))
      ->setRequired(TRUE)
      ->setSetting('min', 1)
      ->setSetting('max', 5)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['body'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Review'))
      ->setDescription(new TranslatableMarkup('Optional written review.'))
      ->setRequired(FALSE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('When the review was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('When the review was last updated.'));

    return $fields;
  }

  /**
   * Gets the event node ID.
   */
  public function getEventId(): ?int {
    $target_id = $this->get('event_id')->target_id;
    return $target_id ? (int) $target_id : NULL;
  }

  /**
   * Gets the event node.
   */
  public function getEvent(): ?NodeInterface {
    $entity = $this->get('event_id')->entity;
    return $entity instanceof NodeInterface ? $entity : NULL;
  }

  /**
   * Sets the event.
   */
  public function setEvent(NodeInterface|int $event): static {
    $this->set('event_id', is_object($event) ? $event->id() : $event);
    return $this;
  }

  /**
   * Gets the rating (1–5).
   */
  public function getRating(): int {
    return (int) ($this->get('rating')->value ?? 0);
  }

  /**
   * Sets the rating.
   */
  public function setRating(int $rating): static {
    $this->set('rating', max(1, min(5, $rating)));
    return $this;
  }

  /**
   * Gets the review body.
   */
  public function getBody(): string {
    return $this->get('body')->value ?? '';
  }

  /**
   * Sets the review body.
   */
  public function setBody(string $body): static {
    $this->set('body', $body);
    return $this;
  }

}
