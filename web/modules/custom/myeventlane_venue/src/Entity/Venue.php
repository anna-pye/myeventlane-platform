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
 * Defines the Venue entity.
 *
 * Venues represent reusable location entities that can be referenced by Events.
 * Each venue belongs to a vendor and contains location information including
 * address, coordinates, and optional Google/Apple Maps place ID.
 *
 * @ContentEntityType(
 *   id = "myeventlane_venue",
 *   label = @Translation("Venue"),
 *   label_collection = @Translation("Venues"),
 *   label_singular = @Translation("venue"),
 *   label_plural = @Translation("venues"),
 *   label_count = @PluralTranslation(
 *     singular = "@count venue",
 *     plural = "@count venues"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\myeventlane_venue\VenueStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\myeventlane_venue\VenueListBuilder",
 *     "access" = "Drupal\myeventlane_venue\Entity\VenueAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\myeventlane_venue\Form\VenueForm",
 *       "add" = "Drupal\myeventlane_venue\Form\VenueForm",
 *       "edit" = "Drupal\myeventlane_venue\Form\VenueForm",
 *       "delete" = "Drupal\myeventlane_venue\Form\VenueDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   base_table = "myeventlane_venue",
 *   data_table = "myeventlane_venue_field_data",
 *   admin_permission = "administer myeventlane venue",
 *   field_ui_base_route = "myeventlane_venue.settings",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "name",
 *   },
 *   links = {
 *     "canonical" = "/venue/{myeventlane_venue}",
 *     "collection" = "/admin/structure/myeventlane/venue",
 *     "add-form" = "/admin/structure/myeventlane/venue/add",
 *     "edit-form" = "/admin/structure/myeventlane/venue/{myeventlane_venue}/edit",
 *     "delete-form" = "/admin/structure/myeventlane/venue/{myeventlane_venue}/delete"
 *   }
 * )
 */
class Venue extends ContentEntityBase implements EntityChangedInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Venue name (label field).
    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Venue name'))
      ->setDescription(new TranslatableMarkup('The name of the venue (e.g., "Grand Ballroom", "Sydney Opera House").'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -10,
        'settings' => [
          'size' => 60,
          'placeholder' => 'Enter venue name',
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Location address field.
    $fields['field_location'] = BaseFieldDefinition::create('address')
      ->setLabel(new TranslatableMarkup('Location'))
      ->setDescription(new TranslatableMarkup('The physical address of the venue.'))
      ->setRequired(FALSE)
      ->setSettings([
        'available_countries' => ['AU'],
        'langcode_override' => '',
        'field_overrides' => [
          'givenName' => ['override' => 'hidden'],
          'additionalName' => ['override' => 'hidden'],
          'familyName' => ['override' => 'hidden'],
          'organization' => ['override' => 'optional'],
          'addressLine1' => ['override' => 'required'],
          'addressLine2' => ['override' => 'optional'],
          'postalCode' => ['override' => 'optional'],
          'sortingCode' => ['override' => 'hidden'],
          'dependentLocality' => ['override' => 'hidden'],
          'locality' => ['override' => 'optional'],
          'administrativeArea' => ['override' => 'optional'],
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'address_default',
        'weight' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'address_default',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Latitude field.
    $fields['field_latitude'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Latitude'))
      ->setDescription(new TranslatableMarkup('The latitude coordinate of the venue location.'))
      ->setRequired(FALSE)
      ->setSettings([
        'precision' => 10,
        'scale' => 7,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 1,
        'settings' => [
          'placeholder' => '-33.8688',
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_decimal',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Longitude field.
    $fields['field_longitude'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Longitude'))
      ->setDescription(new TranslatableMarkup('The longitude coordinate of the venue location.'))
      ->setRequired(FALSE)
      ->setSettings([
        'precision' => 10,
        'scale' => 7,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 2,
        'settings' => [
          'placeholder' => '151.2093',
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_decimal',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Place ID field (for Google/Apple Maps).
    $fields['field_place_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Place ID'))
      ->setDescription(new TranslatableMarkup('The Google Maps or Apple Maps place ID for this venue.'))
      ->setRequired(FALSE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 3,
        'settings' => [
          'size' => 60,
          'placeholder' => 'ChIJN1t_tDeuEmsRUsoyG83frY4',
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Provider field (google_maps or apple_maps).
    $fields['field_provider'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Map Provider'))
      ->setDescription(new TranslatableMarkup('The map provider used to geocode this venue.'))
      ->setRequired(FALSE)
      ->setSettings([
        'allowed_values' => [
          'google_maps' => 'Google Maps',
          'apple_maps' => 'Apple Maps',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 4,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'list_default',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Vendor reference field.
    $fields['field_vendor'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Vendor'))
      ->setDescription(new TranslatableMarkup('The vendor that owns this venue.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'myeventlane_vendor')
      ->setSetting('handler', 'default:myeventlane_vendor')
      ->setSetting('handler_settings', [
        'target_bundles' => NULL,
        'sort' => [
          'field' => 'name',
          'direction' => 'ASC',
        ],
        'auto_create' => FALSE,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 10,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Created timestamp.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('The time that the venue was created.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Changed timestamp.
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('The time that the venue was last updated.'))
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * Gets the venue name.
   *
   * @return string
   *   The venue name.
   */
  public function getName(): string {
    return $this->get('name')->value ?? '';
  }

  /**
   * Sets the venue name.
   *
   * @param string $name
   *   The venue name.
   *
   * @return $this
   */
  public function setName(string $name): static {
    $this->set('name', $name);
    return $this;
  }

  /**
   * Gets the created time.
   *
   * @return int
   *   The created timestamp.
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * Sets the created time.
   *
   * @param int $timestamp
   *   The created timestamp.
   *
   * @return $this
   */
  public function setCreatedTime(int $timestamp): static {
    $this->set('created', $timestamp);
    return $this;
  }

}
