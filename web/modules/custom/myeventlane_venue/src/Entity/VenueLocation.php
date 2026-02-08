<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the Venue Location entity.
 *
 * A venue can have multiple locations. Each location stores canonical address
 * data and coordinates for event use.
 *
 * @ContentEntityType(
 *   id = "myeventlane_venue_location",
 *   label = @Translation("Venue Location"),
 *   label_collection = @Translation("Venue Locations"),
 *   label_singular = @Translation("venue location"),
 *   label_plural = @Translation("venue locations"),
 *   label_count = @PluralTranslation(
 *     singular = "@count venue location",
 *     plural = "@count venue locations"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\myeventlane_venue\VenueLocationListBuilder",
 *     "access" = "Drupal\myeventlane_venue\Entity\VenueLocationAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\myeventlane_venue\Form\VenueLocationForm",
 *       "add" = "Drupal\myeventlane_venue\Form\VenueLocationForm",
 *       "edit" = "Drupal\myeventlane_venue\Form\VenueLocationForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   base_table = "myeventlane_venue_location",
 *   admin_permission = "administer myeventlane venues",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "title"
 *   },
 *   links = {
 *     "canonical" = "/venues/{myeventlane_venue}/locations/{myeventlane_venue_location}",
 *     "collection" = "/admin/structure/myeventlane/venue-locations",
 *     "add-form" = "/venues/{myeventlane_venue}/locations/add",
 *     "edit-form" = "/venues/{myeventlane_venue}/locations/{myeventlane_venue_location}/edit",
 *     "delete-form" = "/venues/{myeventlane_venue}/locations/{myeventlane_venue_location}/delete"
 *   }
 * )
 */
class VenueLocation extends ContentEntityBase implements EntityChangedInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  protected function urlRouteParameters($rel): array {
    $uri_route_parameters = parent::urlRouteParameters($rel);

    // Add the parent venue ID to the route parameters.
    // This is required for routes like edit-form, delete-form, canonical
    // which include {myeventlane_venue} in their path.
    $venue_id = $this->get('venue_id')->target_id;
    if ($venue_id) {
      $uri_route_parameters['myeventlane_venue'] = $venue_id;
    }

    return $uri_route_parameters;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Reference to parent venue.
    $fields['venue_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Venue'))
      ->setDescription(new TranslatableMarkup('The venue this location belongs to.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'myeventlane_venue')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Location title/name.
    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Location title'))
      ->setDescription(new TranslatableMarkup('A name for this location (e.g., "Main Hall", "Rooftop Bar").'))
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
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Reference to myeventlane_location entity (if using hybrid storage).
    // TODO: Add entity reference if myeventlane_location entity type exists.
    // For now, we store address data directly.

    // Canonical address text.
    $fields['address_text'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Address'))
      ->setDescription(new TranslatableMarkup('The full street address.'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 500,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Latitude.
    $fields['lat'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Latitude'))
      ->setDescription(new TranslatableMarkup('The latitude coordinate.'))
      ->setSettings([
        'precision' => 18,
        'scale' => 15,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Longitude.
    $fields['lng'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Longitude'))
      ->setDescription(new TranslatableMarkup('The longitude coordinate.'))
      ->setSettings([
        'precision' => 18,
        'scale' => 15,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 6,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Is primary location.
    $fields['is_primary'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Primary location'))
      ->setDescription(new TranslatableMarkup('Whether this is the primary location for the venue.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Notes.
    $fields['notes'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Notes'))
      ->setDescription(new TranslatableMarkup('Additional notes about this location (access instructions, parking, etc.).'))
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 15,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Created timestamp.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('The time that the location was created.'))
      ->setDisplayConfigurable('view', TRUE);

    // Changed timestamp.
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('The time that the location was last updated.'))
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * Gets the parent venue.
   *
   * @return \Drupal\myeventlane_venue\Entity\Venue|null
   *   The parent venue entity, or NULL.
   */
  public function getVenue(): ?Venue {
    $venue = $this->get('venue_id')->entity;
    return $venue instanceof Venue ? $venue : NULL;
  }

  /**
   * Gets the location title.
   *
   * @return string
   *   The title.
   */
  public function getTitle(): string {
    return $this->get('title')->value ?? '';
  }

  /**
   * Gets the address text.
   *
   * @return string
   *   The address.
   */
  public function getAddressText(): string {
    return $this->get('address_text')->value ?? '';
  }

  /**
   * Gets the latitude.
   *
   * @return float|null
   *   The latitude, or NULL if not set.
   */
  public function getLatitude(): ?float {
    $value = $this->get('lat')->value;
    return $value !== NULL ? (float) $value : NULL;
  }

  /**
   * Gets the longitude.
   *
   * @return float|null
   *   The longitude, or NULL if not set.
   */
  public function getLongitude(): ?float {
    $value = $this->get('lng')->value;
    return $value !== NULL ? (float) $value : NULL;
  }

  /**
   * Checks if this is the primary location.
   *
   * @return bool
   *   TRUE if primary, FALSE otherwise.
   */
  public function isPrimary(): bool {
    return (bool) $this->get('is_primary')->value;
  }

}
