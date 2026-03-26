<?php

declare(strict_types=1);

namespace Drupal\myeventlane_docs_importer\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_docs_importer\Service\DocsImportService;
use Drupal\user\UserInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for MEL documentation CSV import.
 */
final class DocsImportCommands extends DrushCommands {

  public function __construct(
    private readonly DocsImportService $docsImportService,
    private readonly string $drupalRoot,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Import MEL documentation from a CSV file into help_article / staff_playbook nodes.
   *
   * CSV columns (header row):
   * ID, Title, Audience, Type, Product Area, Surface, Owner, Status, Review Cycle,
   * AI Eligible, Summary, Body, Keywords, CTA URL, CTA Title, Related IDs, Notes
   */
  #[CLI\Command(name: 'mel:docs-import', aliases: ['mel-docs-import'])]
  #[CLI\Argument(name: 'csv', description: 'CSV path, project-relative path, or stream URI (e.g. private://mel_docs_import.csv)')]
  #[CLI\Option(name: 'update-only', description: 'Only update existing nodes matched by MEL ID or title; skip new rows')]
  #[CLI\Option(name: 'create-missing-topics', description: 'Create missing help_topic and help_categories terms when Product Area / Surface are unknown')]
  #[CLI\Option(name: 'dry-run', description: 'Validate and log planned actions without saving')]
  #[CLI\Option(name: 'no-publish', description: 'Keep CSV Status (draft/review/etc.); by default imported nodes are set to published')]
  #[CLI\Option(name: 'uid', description: 'User ID used for the import (default 1), for workflow permissions')]
  #[CLI\Usage(name: "drush mel:docs-import private://mel_docs_import.csv", description: 'Import from the private files stream')]
  #[CLI\Usage(name: "drush mel:docs-import /var/www/html/private/mel_docs_import.csv --dry-run", description: 'Dry run with an absolute path')]
  #[CLI\Usage(name: "drush mel:docs-import private://mel_docs_import.csv --update-only", description: 'Update matched nodes only')]
  #[CLI\Usage(name: "drush mel:docs-import private://mel_docs_import.csv --no-publish", description: 'Import without forcing published state')]
  public function importDocs($csv, array $options = [
    'update-only' => FALSE,
    'create-missing-topics' => FALSE,
    'dry-run' => FALSE,
    'no-publish' => FALSE,
    'uid' => 1,
  ]): int {
    $path = $this->docsImportService->resolveReadablePath((string) $csv, $this->drupalRoot);
    if ($path === NULL) {
      $this->io()->error(sprintf('Cannot read CSV: %s', $csv));
      return DrushCommands::EXIT_FAILURE;
    }

    $uid = (int) ($options['uid'] ?? 1);
    $actor = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$actor instanceof UserInterface || $actor->id() < 1) {
      $this->io()->error(sprintf('User %d not found. Use a valid account (default 1).', $uid));
      return DrushCommands::EXIT_FAILURE;
    }

    $updateOnly = (bool) ($options['update-only'] ?? FALSE);
    $createMissing = (bool) ($options['create-missing-topics'] ?? FALSE);
    $dryRun = (bool) ($options['dry-run'] ?? FALSE);
    $noPublishRaw = $options['no-publish'] ?? FALSE;
    $noPublish = $noPublishRaw === TRUE || $noPublishRaw === 1
      || (is_string($noPublishRaw) && in_array(strtolower($noPublishRaw), ['1', 'true', 'yes'], TRUE));
    $publishOnImport = !$noPublish;

    if ($dryRun) {
      $this->io()->note('Dry run: no database writes; related-article linking is skipped until a real import runs.');
    }
    if (!$publishOnImport) {
      $this->io()->note('Import will respect CSV Status column (not forcing published).');
    }

    $summary = $this->docsImportService->import(
      $path,
      $uid,
      $updateOnly,
      $createMissing,
      $dryRun,
      $publishOnImport,
    );

    foreach ($summary->dryRunPlanned as $line) {
      $this->io()->writeln($line);
    }

    $this->io()->success(sprintf(
      'Import finished: %d created, %d updated, %d skipped, %d errored.',
      $summary->created,
      $summary->updated,
      $summary->skipped,
      $summary->errored,
    ));

    return $summary->errored > 0 ? DrushCommands::EXIT_FAILURE : DrushCommands::EXIT_SUCCESS;
  }

}
