<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Defines the EventReview storage schema.
 *
 * Adds DB-level unique index on (uid, event_id) to prevent race-condition
 * duplicates. The UniqueUserEventConstraint validator provides friendly
 * error messages; this index enforces at the database level.
 */
class EventReviewStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $base_table = $entity_type->getBaseTable();

    if (isset($schema[$base_table])) {
      $schema[$base_table]['unique keys']['event_review_uid_event'] = [
        'uid',
        'event_id',
      ];
    }

    return $schema;
  }

}
