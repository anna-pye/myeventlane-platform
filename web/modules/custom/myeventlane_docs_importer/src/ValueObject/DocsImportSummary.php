<?php

declare(strict_types=1);

namespace Drupal\myeventlane_docs_importer\ValueObject;

/**
 * Aggregate counters for a docs import run.
 */
final class DocsImportSummary {

  public int $created = 0;

  public int $updated = 0;

  public int $skipped = 0;

  public int $errored = 0;

  /**
   * Human-readable dry-run lines (planned actions).
   *
   * @var list<string>
   */
  public array $dryRunPlanned = [];

}
