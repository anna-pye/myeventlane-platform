<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Commands;

use Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Drush\Commands\DrushCommands;

/**
 * Repairs missing dedicated SQL tables for configurable node field storages.
 */
final class RepairNodeFieldTablesCommands extends DrushCommands {

  public function __construct(
    private readonly EntityDefinitionUpdateManagerInterface $entityDefinitionUpdateManager,
  ) {
    parent::__construct();
  }

  /**
   * Ensures dedicated field data/revision tables exist for the given fields.
   *
   * Use when FieldStorageConfig is present but tables were never created or
   * were dropped, which breaks config import and schema updates.
   *
   * @param string[] $machine_names
   *   Field machine names (e.g. field_ai_summary), not including the "node."
   *   prefix.
   *
   * @command mel:repair-node-field-tables
   * @usage drush mel:repair-node-field-tables field_ai_summary
   * @usage drush mel:repair-node-field-tables field_ai_summary field_internal_only
   */
  public function repair(array $machine_names): void {
    if ($machine_names === []) {
      $machine_names = ['field_ai_summary'];
    }

    foreach ($machine_names as $name) {
      $name = trim((string) $name);
      if ($name === '') {
        continue;
      }

      $id = 'node.' . $name;
      $storage = FieldStorageConfig::load($id);
      if (!$storage instanceof FieldStorageConfig) {
        $this->logger()->error(sprintf('Field storage %s not found.', $id));
        continue;
      }

      try {
        $this->entityDefinitionUpdateManager->installFieldStorageDefinition(
          $storage->getName(),
          $storage->getTargetEntityTypeId(),
          $storage->getProvider(),
          $storage,
        );
        $this->logger()->success(sprintf('Ensured database schema for %s.', $id));
      }
      catch (\Throwable $e) {
        $this->logger()->error(sprintf('Failed to repair %s: %s', $id, $e->getMessage()));
        throw $e;
      }
    }
  }

}
