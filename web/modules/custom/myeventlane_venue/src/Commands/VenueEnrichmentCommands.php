<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Commands;

use Drupal\myeventlane_venue\Service\OverturePlaceImporter;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Imports a reviewed Australian Overture Places extract.
 */
final class VenueEnrichmentCommands extends DrushCommands {

  public function __construct(
    private readonly OverturePlaceImporter $importer,
  ) {
    parent::__construct();
  }

  #[CLI\Command(name: 'mel:venue-import-overture')]
  #[CLI\Argument(name: 'csv', description: 'Path to the reviewed Australian Overture Places CSV extract')]
  #[CLI\Option(name: 'dry-run', description: 'Validate the extract without writing catalogue rows')]
  #[CLI\Usage(name: 'drush mel:venue-import-overture /tmp/au-venues.csv --dry-run', description: 'Validate an extract')]
  #[CLI\Usage(name: 'drush mel:venue-import-overture /tmp/au-venues.csv --yes', description: 'Import or update catalogue rows')]
  /**
   * Imports or validates a reviewed Overture Places CSV extract.
   */
  public function importOverture(string $csv, array $options = ['dry-run' => FALSE]): int {
    $dry_run = $this->enabled($options['dry-run'] ?? FALSE);
    $yes = $this->enabled($this->input()->getOption('yes'));
    if ($dry_run && $yes) {
      $this->io()->error('Use either --dry-run or --yes, not both.');
      return self::EXIT_FAILURE;
    }
    if (!$dry_run && !$yes) {
      $dry_run = TRUE;
      $this->io()->note('Dry-run mode. Pass --yes to import after reviewing the report.');
    }

    try {
      $report = $this->importer->import($csv, $dry_run);
    }
    catch (\Throwable $exception) {
      $this->io()->error($exception->getMessage());
      return self::EXIT_FAILURE;
    }

    foreach ($report['errors'] as $error) {
      $this->io()->warning($error);
    }
    $verb = $dry_run ? 'validated' : 'imported';
    $this->io()->success(sprintf(
      'Overture venue catalogue %s: %d accepted, %d skipped from %d rows.',
      $verb,
      $report['imported'],
      $report['skipped'],
      $report['processed'],
    ));
    return $report['errors'] === [] ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

  /**
   * Normalises a command-line boolean option.
   */
  private function enabled(mixed $value): bool {
    if ($value === TRUE || $value === 1) {
      return TRUE;
    }
    return is_string($value) && in_array(strtolower($value), ['1', 'true', 'yes', 'y', 'on'], TRUE);
  }

}
