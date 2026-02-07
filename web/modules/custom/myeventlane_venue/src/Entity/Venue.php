<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Venue entity.
 *
 * Venues are reusable location containers owned by vendors. They support
 * multiple locations, share-by-link access, and optional public directory
 * visibility.
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
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\myeventlane_venue\VenueListBuilder",
 *     "access" = "Drupal\myeventlane_venue\Entity\VenueAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\myeventlane_venue\Form\VenueForm",
 *       "add" = "Drupal\myeventlane_venue\Form\VenueForm",
 *       "edit" = "Drupal\myeventlane_venue\Form\VenueForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   base_table = "myeventlane_venue",
 *   admin_permission = "administer myeventlane venues",
 *   field_ui_base_route = "myeventlane_venue.admin_settings",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "name",
 *     "owner" = "uid"
 *   },
 *   links = {
 *     "canonical" = "/venues/{myeventlane_venue}",
 *     "collection" = "/admin/structure/myeventlane/venues",
 *     "add-form" = "/admin/structure/myeventlane/venues/add",
 *     "edit-form" = "/admin/structure/myeventlane/venues/{myeventlane_venue}/edit",
 *     "delete-form" = "/admin/structure/myeventlane/venues/{myeventlane_venue}/delete"
 *   }
 * )
 */
class Venue extends ContentEntityBase implements EntityChangedInterface, EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * Visibility: Shared by link / explicit permission.
   */
  public const VISIBILITY_SHARED = 'shared';

  /**
   * Visibility: Public directory listing.
   */
  public const VISIBILITY_PUBLIC = 'public';

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    // Set the owner to the current user if not already set.
    $ownerId = $this->getOwnerId();
    if ($ownerId === NULL) {
      $ownerId = (int) \Drupal::currentUser()->id();
      $this->setOwnerId($ownerId);
    }

    // Generate share_token if not set.
    if ($this->isNew() && empty($this->get('share_token')->value)) {
      $this->set('share_token', $this->generateShareToken());
    }
  }

  /**
   * Generates a unique share token.
   *
   * @return string
   *   The generated token.
   */
  protected function generateShareToken(): string {
    return bin2hex(random_bytes(16));
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Venue name (label field).
    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Venue name'))
      ->setDescription(new TranslatableMarkup('The name of the venue.'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -10,
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Owner (uid) field.
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Owner'))
      ->setDescription(new TranslatableMarkup('The user who owns this venue.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default:user')
      ->setSetting('handler_settings', [
        'include_anonymous' => FALSE,
      ])
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner')
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

    // Visibility (shared or public).
    $fields['visibility'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Visibility'))
      ->setDescription(new TranslatableMarkup('Controls who can see and use this venue.'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::VISIBILITY_SHARED)
      ->setSetting('allowed_values', [
        self::VISIBILITY_SHARED => 'Shared by link',
        self::VISIBILITY_PUBLIC => 'Public directory',
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

    // Share token (read-only, auto-generated).
    $fields['share_token'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Share token'))
      ->setDescription(new TranslatableMarkup('Token for share-by-link access. Auto-generated.'))
      ->setRequired(FALSE)
      ->setSettings([
        'max_length' => 64,
      ])
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    // Description.
    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDescription(new TranslatableMarkup('A description of the venue.'))
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 5,
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'text_default',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Venue image.
    $fields['image'] = BaseFieldDefinition::create('image')
      ->setLabel(new TranslatableMarkup('Venue image'))
      ->setDescription(new TranslatableMarkup('An image representing this venue.'))
      ->setSettings([
        'file_directory' => 'venues',
        'alt_field' => TRUE,
        'alt_field_required' => FALSE,
        'max_filesize' => '5 MB',
        'file_extensions' => 'png jpg jpeg gif webp',
      ])
      ->setDisplayOptions('form', [
        'type' => 'image_image',
        'weight' => 6,
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'image',
        'weight' => -5,
        'settings' => [
          'image_style' => 'large',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Primary address (for display purposes, detailed data in VenueLocation).
    $fields['primary_address'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Primary address'))
      ->setDescription(new TranslatableMarkup('The primary address of this venue.'))
      ->setSettings([
        'max_length' => 512,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 7,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 7,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Website URL (using string type with max_length to avoid row size issues).
    $fields['website'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Website'))
      ->setDescription(new TranslatableMarkup('The venue website URL.'))
      ->setSettings([
        'max_length' => 255,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 10,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Phone number.
    $fields['phone'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Phone'))
      ->setDescription(new TranslatableMarkup('The venue contact phone number.'))
      ->setSettings([
        'max_length' => 50,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 11,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 11,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Email (using string type to avoid email field's overhead).
    $fields['email'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Email'))
      ->setDescription(new TranslatableMarkup('The venue contact email address.'))
      ->setSettings([
        'max_length' => 255,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 12,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 12,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Facebook URL.
    $fields['facebook'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Facebook'))
      ->setDescription(new TranslatableMarkup('Facebook page URL.'))
      ->setSettings([
        'max_length' => 255,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 13,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Instagram URL.
    $fields['instagram'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Instagram'))
      ->setDescription(new TranslatableMarkup('Instagram profile URL.'))
      ->setSettings([
        'max_length' => 255,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 14,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // X (Twitter) URL.
    $fields['twitter'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('X (Twitter)'))
      ->setDescription(new TranslatableMarkup('X (Twitter) profile URL.'))
      ->setSettings([
        'max_length' => 255,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // LinkedIn URL.
    $fields['linkedin'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('LinkedIn'))
      ->setDescription(new TranslatableMarkup('LinkedIn page URL.'))
      ->setSettings([
        'max_length' => 255,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 16,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // YouTube URL.
    $fields['youtube'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('YouTube'))
      ->setDescription(new TranslatableMarkup('YouTube channel URL.'))
      ->setSettings([
        'max_length' => 255,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 17,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // TikTok URL.
    $fields['tiktok'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('TikTok'))
      ->setDescription(new TranslatableMarkup('TikTok profile URL.'))
      ->setSettings([
        'max_length' => 255,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 18,
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
   * Gets the visibility.
   *
   * @return string
   *   The visibility value.
   */
  public function getVisibility(): string {
    return $this->get('visibility')->value ?? self::VISIBILITY_SHARED;
  }

  /**
   * Checks if the venue is public.
   *
   * @return bool
   *   TRUE if public, FALSE otherwise.
   */
  public function isPublic(): bool {
    return $this->getVisibility() === self::VISIBILITY_PUBLIC;
  }

  /**
   * Gets the share token.
   *
   * @return string|null
   *   The share token, or NULL if not set.
   */
  public function getShareToken(): ?string {
    $value = $this->get('share_token')->value;
    return $value ? (string) $value : NULL;
  }

  /**
   * Gets the description.
   *
   * @return string
   *   The description.
   */
  public function getDescription(): string {
    return $this->get('description')->value ?? '';
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
   * Gets the primary address.
   *
   * @return string
   *   The primary address.
   */
  public function getPrimaryAddress(): string {
    return $this->get('primary_address')->value ?? '';
  }

  /**
   * Checks if the venue has an image.
   *
   * @return bool
   *   TRUE if an image is set.
   */
  public function hasImage(): bool {
    return !$this->get('image')->isEmpty();
  }

  /**
   * Gets the venue image file entity.
   *
   * @return \Drupal\file\FileInterface|null
   *   The file entity or NULL.
   */
  public function getImageFile(): ?\Drupal\file\FileInterface {
    if ($this->hasImage()) {
      return $this->get('image')->entity;
    }
    return NULL;
  }

  /**
   * Gets the venue image URL.
   *
   * @param string $image_style
   *   Optional image style name.
   *
   * @return string|null
   *   The image URL or NULL.
   */
  public function getImageUrl(string $image_style = ''): ?string {
    $file = $this->getImageFile();
    if (!$file) {
      return NULL;
    }

    if ($image_style && \Drupal::hasService('entity_type.manager')) {
      $style = \Drupal::entityTypeManager()
        ->getStorage('image_style')
        ->load($image_style);
      if ($style) {
        return $style->buildUrl($file->getFileUri());
      }
    }

    return \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
  }

  /**
   * Gets the website URL.
   *
   * @return string
   *   The website URL.
   */
  public function getWebsite(): string {
    return $this->get('website')->value ?? '';
  }

  /**
   * Gets the phone number.
   *
   * @return string
   *   The phone number.
   */
  public function getPhone(): string {
    return $this->get('phone')->value ?? '';
  }

  /**
   * Gets the email address.
   *
   * @return string
   *   The email address.
   */
  public function getEmail(): string {
    return $this->get('email')->value ?? '';
  }

  /**
   * Gets the Facebook URL.
   *
   * @return string
   *   The Facebook URL.
   */
  public function getFacebook(): string {
    return $this->get('facebook')->value ?? '';
  }

  /**
   * Gets the Instagram URL.
   *
   * @return string
   *   The Instagram URL.
   */
  public function getInstagram(): string {
    return $this->get('instagram')->value ?? '';
  }

  /**
   * Gets the Twitter/X URL.
   *
   * @return string
   *   The Twitter URL.
   */
  public function getTwitter(): string {
    return $this->get('twitter')->value ?? '';
  }

  /**
   * Gets the LinkedIn URL.
   *
   * @return string
   *   The LinkedIn URL.
   */
  public function getLinkedin(): string {
    return $this->get('linkedin')->value ?? '';
  }

  /**
   * Gets the YouTube URL.
   *
   * @return string
   *   The YouTube URL.
   */
  public function getYoutube(): string {
    return $this->get('youtube')->value ?? '';
  }

  /**
   * Gets the TikTok URL.
   *
   * @return string
   *   The TikTok URL.
   */
  public function getTiktok(): string {
    return $this->get('tiktok')->value ?? '';
  }

  /**
   * Checks if any social media links are set.
   *
   * @return bool
   *   TRUE if any social links exist.
   */
  public function hasSocialLinks(): bool {
    return !empty($this->getFacebook())
      || !empty($this->getInstagram())
      || !empty($this->getTwitter())
      || !empty($this->getLinkedin())
      || !empty($this->getYoutube())
      || !empty($this->getTiktok());
  }

}
