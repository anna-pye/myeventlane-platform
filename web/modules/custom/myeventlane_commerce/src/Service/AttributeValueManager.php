<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_product\Entity\ProductAttributeInterface;
use Drupal\commerce_product\Entity\ProductAttributeValueInterface;

/**
 * Ensures Commerce attribute values are reused or created exactly once.
 */
final class AttributeValueManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
    private readonly LockBackendInterface $lock,
    private readonly Connection $db,
  ) {}

  /**
   * Get or create an attribute value for the given attribute + label.
   *
   * @param \Drupal\commerce_product\Entity\ProductAttributeInterface|string $attribute
   *   Attribute entity or its ID (e.g. 'ticket_type').
   * @param string $label
   *   Human label (e.g. 'General', 'VIP').
   * @param string $langcode
   *   Langcode for label, default 'en'.
   */
  public function getOrCreate(ProductAttributeInterface|string $attribute, string $label, string $langcode = 'en'): ProductAttributeValueInterface {
    $attribute_id = $attribute instanceof ProductAttributeInterface ? $attribute->id() : $attribute;
    $normalized = $this->normalizeLabel($label);

    $storage = $this->etm->getStorage('commerce_product_attribute_value');

    // Fast path: if it exists, return it.
    if ($existing = $this->loadByLabel($storage, $attribute_id, $normalized)) {
      return $existing;
    }

    // Avoid two requests creating the same value at once.
    $lock_name = sprintf('mel_attr_value:%s:%s', $attribute_id, mb_strtolower($normalized));
    $got_lock = $this->lock->acquire($lock_name, 10.0);

    try {
      // Re-check under lock.
      if ($existing = $this->loadByLabel($storage, $attribute_id, $normalized)) {
        return $existing;
      }

      // Optional but helpful: a short transaction so either the row appears or we can retry-load after a constraint error.
      $tx = $this->db->startTransaction();

      /** @var \Drupal\commerce_product\Entity\ProductAttributeValueInterface $value */
      $value = $storage->create([
        'attribute' => $attribute_id,
        'langcode' => $langcode,
      // Translatable label field.
        'name' => $normalized,
        // No 'uuid' provided — let Drupal assign it.
      ]);

      $value->save();
      return $value;
    }
    catch (\Throwable $e) {
      // If another request sneaked in and created the same value, we can now load it.
      if ($existing = $this->loadByLabel($storage, $attribute_id, $normalized)) {
        return $existing;
      }
      throw $e;
    }
    finally {
      if ($got_lock) {
        $this->lock->release($lock_name);
      }
    }
  }

  /**
   *
   */
  private function normalizeLabel(string $label): string {
    $label = trim(preg_replace('/\s+/', ' ', $label));
    return $label !== '' ? $label : 'Unnamed';
  }

  /**
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   */
  private function loadByLabel($storage, string $attribute_id, string $normalized): ?ProductAttributeValueInterface {
    // Note: 'name' is the label field on attribute values.
    $ids = $storage->getQuery()
      ->condition('attribute', $attribute_id)
      ->condition('name', $normalized)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();

    if ($ids) {
      /** @var \Drupal\commerce_product\Entity\ProductAttributeValueInterface $entity */
      $entity = $storage->load(reset($ids));
      return $entity;
    }
    return NULL;
  }

}
