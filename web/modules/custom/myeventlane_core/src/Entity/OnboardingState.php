<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerInterface;

/**
 * Defines the Onboarding state entity.
 *
 * This entity is intentionally "foundation-only" and inert: it provides a
 * server-side state record that will be used by onboarding flows later, but it
 * does not add any onboarding UI or modify existing routes/controllers.
 *
 * @ContentEntityType(
 *   id = "myeventlane_onboarding_state",
 *   label = @Translation("Onboarding state"),
 *   label_collection = @Translation("Onboarding states"),
 *   handlers = {
 *     "storage" = "Drupal\myeventlane_core\OnboardingStateStorage",
 *     "access" = "Drupal\myeventlane_core\OnboardingStateAccessControlHandler",
 *     "list_builder" = "Drupal\myeventlane_core\OnboardingStateListBuilder"
 *   },
 *   base_table = "myeventlane_onboarding_state",
 *   admin_permission = "administer myeventlane onboarding",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "owner" = "uid"
 *   },
 *   links = {
 *     "collection" = "/admin/config/myeventlane/onboarding"
 *   }
 * )
 */
final class OnboardingState extends ContentEntityBase implements OnboardingStateInterface, EntityOwnerInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public function getOwner(): ?\Drupal\user\UserInterface {
    return $this->get('uid')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwnerId(): ?int {
    return $this->get('uid')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwnerId($uid): static {
    $this->set('uid', $uid);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwner(\Drupal\user\UserInterface $account): static {
    $this->set('uid', $account->id());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getTrack(): string {
    return (string) ($this->get('track')->value ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function setTrack(string $track): static {
    $this->set('track', $track);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getStage(): string {
    return (string) ($this->get('stage')->value ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function setStage(string $stage): static {
    $this->set('stage', $stage);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isCompleted(): bool {
    return (bool) ($this->get('completed')->value ?? FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function setCompleted(bool $completed): static {
    $this->set('completed', $completed ? 1 : 0);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getVendorId(): ?int {
    $value = $this->get('vendor_id')->target_id ?? NULL;
    return $value !== NULL ? (int) $value : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setVendorId(?int $vendor_id): static {
    $this->set('vendor_id', $vendor_id);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getStoreId(): ?int {
    $value = $this->get('store_id')->target_id ?? NULL;
    return $value !== NULL ? (int) $value : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setStoreId(?int $store_id): static {
    $this->set('store_id', $store_id);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFlags(): array {
    $item = $this->get('flags')->first();
    if ($item === NULL) {
      return [];
    }

    $value = $item->getValue();
    return is_array($value) ? $value : [];
  }

  /**
   * {@inheritdoc}
   */
  public function setFlags(array $flags): static {
    $this->set('flags', $flags);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Label (optional, may be auto-populated by manager).
    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setDescription(new TranslatableMarkup('Optional human-friendly label for this onboarding state.'))
      ->setRequired(FALSE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ]);

    // Owner (required).
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Owner'))
      ->setDescription(new TranslatableMarkup('The user account this onboarding state belongs to.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default:user')
      ->setRequired(TRUE);

    // Track (required, immutable after creation).
    $fields['track'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Track'))
      ->setDescription(new TranslatableMarkup('Onboarding track: customer or vendor. Immutable after creation.'))
      ->setRequired(TRUE)
      ->setSettings([
        'allowed_values' => [
          self::TRACK_CUSTOMER => new TranslatableMarkup('Customer'),
          self::TRACK_VENDOR => new TranslatableMarkup('Vendor'),
        ],
      ]);

    // Stage (required).
    $fields['stage'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Stage'))
      ->setDescription(new TranslatableMarkup('Current onboarding stage.'))
      ->setRequired(TRUE)
      ->setSettings([
        'allowed_values' => [
          'probe' => new TranslatableMarkup('Probe'),
          'present' => new TranslatableMarkup('Present'),
          'listen' => new TranslatableMarkup('Listen'),
          'ask' => new TranslatableMarkup('Ask'),
          'invite' => new TranslatableMarkup('Invite'),
          'complete' => new TranslatableMarkup('Complete'),
        ],
      ])
      ->setDefaultValue('probe');

    // Completed flag.
    $fields['completed'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Completed'))
      ->setDescription(new TranslatableMarkup('Whether onboarding is complete.'))
      ->setDefaultValue(FALSE);

    // Flags map (JSON-like data).
    $fields['flags'] = BaseFieldDefinition::create('map')
      ->setLabel(new TranslatableMarkup('Flags'))
      ->setDescription(new TranslatableMarkup('Additional onboarding flags and computed status.'))
      ->setRequired(FALSE)
      ->setDefaultValue([]);

    // Vendor-only: vendor entity reference.
    $fields['vendor_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Vendor'))
      ->setDescription(new TranslatableMarkup('Vendor entity reference (vendor track only).'))
      ->setSetting('target_type', 'myeventlane_vendor')
      ->setRequired(FALSE);

    // Vendor-only: commerce store reference.
    $fields['store_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Store'))
      ->setDescription(new TranslatableMarkup('Commerce store reference (vendor track only).'))
      ->setSetting('target_type', 'commerce_store')
      ->setRequired(FALSE);

    // Created timestamp.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('When this onboarding state was created.'));

    // Changed timestamp.
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('When this onboarding state was last updated.'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    $track = $this->getTrack();
    $stage = $this->getStage();
    $vendor_id = $this->getVendorId();
    $store_id = $this->getStoreId();

    if ($track === '' || !in_array($track, [self::TRACK_CUSTOMER, self::TRACK_VENDOR], TRUE)) {
      throw new EntityStorageException('Invalid onboarding track.');
    }

    if ($stage === '' || !in_array($stage, self::STAGE_ORDER, TRUE)) {
      throw new EntityStorageException('Invalid onboarding stage.');
    }

    // Track-specific guards.
    if ($track === self::TRACK_CUSTOMER) {
      if ($vendor_id !== NULL || $store_id !== NULL) {
        throw new EntityStorageException('Customer onboarding states cannot reference vendor_id or store_id.');
      }
    }
    else {
      // Vendor track:
      // - Allow vendor_id NULL for pre-vendor onboarding (in-progress).
      // - REQUIRE vendor_id once onboarding completes.
      if (($stage === 'complete' || $this->isCompleted()) && $vendor_id === NULL) {
        throw new EntityStorageException('Vendor onboarding states must reference vendor_id once onboarding is complete.');
      }
    }

    // completed can only be TRUE if stage == complete.
    if ($this->isCompleted() && $stage !== 'complete') {
      throw new EntityStorageException('Onboarding state cannot be completed unless stage is "complete".');
    }

    // Prevent track changes and stage regression on existing entities.
    if (!$this->isNew()) {
      /** @var \Drupal\myeventlane_core\Entity\OnboardingStateInterface|null $original */
      $original = $this->original instanceof OnboardingStateInterface ? $this->original : NULL;
      if ($original === NULL) {
        /** @var \Drupal\myeventlane_core\Entity\OnboardingStateInterface|null $original */
        $original = $storage->loadUnchanged($this->id());
      }

      if ($original !== NULL) {
        if ($original->getTrack() !== $track) {
          throw new EntityStorageException('Onboarding track cannot be changed after creation.');
        }

        $old_stage = $original->getStage();
        if ($old_stage !== '' && in_array($old_stage, self::STAGE_ORDER, TRUE)) {
          $old_index = array_search($old_stage, self::STAGE_ORDER, TRUE);
          $new_index = array_search($stage, self::STAGE_ORDER, TRUE);
          if ($old_index !== FALSE && $new_index !== FALSE && $new_index < $old_index) {
            throw new EntityStorageException('Onboarding stage cannot regress.');
          }
        }
      }
    }
  }

}

