<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core;

use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/**
 * Storage handler for onboarding state entities.
 *
 * Application-level uniqueness:
 * - Customer: newest by (uid, track=customer).
 * - Vendor: newest by (vendor_id, track=vendor).
 */
class OnboardingStateStorage extends SqlContentEntityStorage {

  /**
   * {@inheritdoc}
   */
  public function loadByProperties(array $values = []): array {
    $query = $this->getQuery()->accessCheck(FALSE);
    foreach ($values as $name => $value) {
      $query->condition($name, $value);
    }
    $query->sort('id', 'DESC');
    $ids = $query->range(0, 1)->execute();
    if (empty($ids)) {
      return [];
    }
    $entities = $this->loadMultiple($ids);
    return $entities ?: [];
  }

}
