<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Entity;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Defines the storage schema for Legal Consent Event entities.
 *
 * Adds indexes for audit queries (email, user_id, source, accepted_at).
 */
class LegalConsentEventStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $base_table = $entity_type->getBaseTable();

    if (isset($schema[$base_table])) {
      $schema[$base_table]['indexes'] += [
        'legal_consent_event__email' => ['email'],
        'legal_consent_event__user_id' => ['user_id'],
        'legal_consent_event__source' => ['source'],
        'legal_consent_event__accepted_at' => ['accepted_at'],
        'legal_consent_event__event_id' => ['event_id'],
      ];
    }

    return $schema;
  }

}
