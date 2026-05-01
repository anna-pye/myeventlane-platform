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

/**
 * Defines a vendor follow relationship.
 *
 * @ContentEntityType(
 *   id = "myeventlane_vendor_follow",
 *   label = @Translation("Vendor follow"),
 *   label_collection = @Translation("Vendor follows"),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage"
 *   },
 *   base_table = "myeventlane_vendor_follow",
 *   admin_permission = "administer myeventlane",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid"
 *   }
 * )
 */
final class VendorFollow extends ContentEntityBase {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('User'))
      ->setDescription(new TranslatableMarkup('The user following the vendor.'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE);

    $fields['vendor_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Vendor'))
      ->setDescription(new TranslatableMarkup('The followed vendor.'))
      ->setSetting('target_type', 'myeventlane_vendor')
      ->setRequired(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('When this follow was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('When this follow was last changed.'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    $user_id = (int) ($this->get('user_id')->target_id ?? 0);
    $vendor_id = (int) ($this->get('vendor_id')->target_id ?? 0);
    if ($user_id <= 0 || $vendor_id <= 0) {
      throw new EntityStorageException('Vendor follows require a valid user and vendor.');
    }

    if ($this->isNew()) {
      $existing_ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('user_id', $user_id)
        ->condition('vendor_id', $vendor_id)
        ->range(0, 1)
        ->execute();
      if (!empty($existing_ids)) {
        throw new EntityStorageException('This user already follows this vendor.');
      }
    }
  }

}
