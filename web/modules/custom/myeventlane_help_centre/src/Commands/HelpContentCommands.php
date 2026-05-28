<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Commands;

use Drupal\myeventlane_help_centre\Service\PriorityHelpArticleImporter;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Help Centre content import.
 */
final class HelpContentCommands extends DrushCommands {

  public function __construct(
    private readonly PriorityHelpArticleImporter $priorityHelpArticleImporter,
  ) {
    parent::__construct();
  }

  /**
   * Import or update priority Help Centre articles from YAML export.
   */
  #[CLI\Command(name: 'mel:help-import-priority', aliases: ['mel-help-import-priority'])]
  #[CLI\Argument(name: 'yaml', description: 'Optional YAML path (defaults to docs/content-audit/help-article-exports/help-articles-priority-2026-05.yml)')]
  #[CLI\Option(name: 'dry-run', description: 'Validate YAML and report planned actions without saving nodes or aliases')]
  #[CLI\Usage(name: 'drush mel:help-import-priority', description: 'Dry-run preview (default; no writes)')]
  #[CLI\Usage(name: 'drush mel:help-import-priority --dry-run', description: 'Explicit dry-run preview')]
  #[CLI\Usage(name: 'drush mel:help-import-priority --yes', description: 'Import or update articles (Drush global -y/--yes)')]
  public function importPriority(?string $yaml = NULL, array $options = [
    'dry-run' => FALSE,
  ]): int {
    $dryRun = $this->optionIsEnabled($options['dry-run'] ?? FALSE);
    $yes = $this->optionIsEnabled($this->input()->getOption('yes'));

    if ($dryRun && $yes) {
      $this->io()->error('Use either --dry-run or --yes, not both.');
      return DrushCommands::EXIT_FAILURE;
    }

    if (!$dryRun && !$yes) {
      $dryRun = TRUE;
      $this->io()->note('Dry-run mode (no writes). Pass --yes (or -y) to import, or --dry-run to preview explicitly.');
    }

    $report = $this->priorityHelpArticleImporter->import($yaml, $dryRun);

    foreach ($report['articles'] as $stableKey => $article) {
      $this->io()->writeln(sprintf(
        '- %s: %s (matched_by=%s, nid=%s%s)',
        $stableKey,
        (string) ($article['action'] ?? 'unknown'),
        (string) ($article['matched_by'] ?? ''),
        $article['nid'] === NULL ? 'n/a' : (string) $article['nid'],
        ($article['message'] ?? '') !== '' ? ', ' . $article['message'] : '',
      ));
    }

    if ($report['errors'] !== []) {
      $this->io()->warning('Errors:');
      foreach ($report['errors'] as $error) {
        $this->io()->writeln('  - ' . $error);
      }
    }

    if ($dryRun) {
      $this->io()->note('Dry run complete: no nodes or aliases were saved.');
    }

    $this->io()->success(sprintf(
      'Priority help import finished: %d created, %d updated, %d skipped, %d errors.',
      $report['created'],
      $report['updated'],
      $report['skipped'],
      count($report['errors']),
    ));

    return $report['errors'] !== [] ? DrushCommands::EXIT_FAILURE : DrushCommands::EXIT_SUCCESS;
  }

  private function optionIsEnabled(mixed $value): bool {
    if ($value === TRUE || $value === 1) {
      return TRUE;
    }
    if (!is_string($value)) {
      return FALSE;
    }
    return in_array(strtolower($value), ['1', 'true', 'yes', 'y', 'on'], TRUE);
  }

}
