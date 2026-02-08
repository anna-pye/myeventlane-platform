<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the Venue Access entity.
 *
 * This entity represents explicit permission grants for shared venues.
 * Created via the share-by-link flow. Non-editable by vendors.
 *
 * @ContentEntityType(
 *   id = "myeventlane_venue_access",
 *   label = @Translation("Venue Access"),
 *   label_collection = @Translation("Venue Access Grants"),
 *   label_singular = @Translation("venue access grant"),
 *   label_plural = @Translation("venue access grants"),
 *   label_count = @PluralTranslation(
 *     singular = "@count venue access grant",
 *     plural = "@count venue access grants"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "access" = "Drupal\myeventlane_venue\Entity\VenueAccessAccessControlHandler",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   base_table = "myeventlane_venue_access",
 *   admin_permission = "administer myeventlane venues",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid"
 *   },
 *   links = {
 *     "collection" = "/admin/structure/myeventlane/venue-access"
 *   }
 * )
 */
class VenueAccess extends ContentEntityBase {

  /**
   * Role: Use (can use venue for events).
   */
  public const ROLE_USE = 'use';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Reference to venue.
    $fields['venue_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Venue'))
      ->setDescription(new TranslatableMarkup('The venue being shared.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'myeventlane_venue')
      ->setSetting('handler', 'default');

    // Reference to user with access.
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('User'))
      ->setDescription(new TranslatableMarkup('The user who has been granted access.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default:user')
      ->setSetting('handler_settings', [
        'include_anonymous' => FALSE,
      ]);

    // Role (currently only "use").
    $fields['role'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Role'))
      ->setDescription(new TranslatableMarkup('The access role granted.'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::ROLE_USE)
      ->setSettings([
        'max_length' => 32,
      ]);

    // Created timestamp.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('The time that the access was granted.'));

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
   * Gets the user ID.
   *
   * @return int
   *   The user ID.
   */
  public function getUserId(): int {
    return (int) $this->get('uid')->target_id;
  }

  /**
   * Gets the role.
   *
   * @return string
   *   The role.
   */
  public function getRole(): string {
    return $this->get('role')->value ?? self::ROLE_USE;
  }

  /**
   * Creates a venue access grant.
   *
   * @param \Drupal\myeventlane_venue\Entity\Venue $venue
   *   The venue.
   * @param int $uid
   *   The user ID.
   * @param string $role
   *   The role (default: 'use').
   *
   * @return static
   *   The created entity.
   */
  public static function createGrant(Venue $venue, int $uid, string $role = self::ROLE_USE): static {
    return static::create([
      'venue_id' => $venue->id(),
      'uid' => $uid,
      'role' => $role,
    ]);
  }

}
