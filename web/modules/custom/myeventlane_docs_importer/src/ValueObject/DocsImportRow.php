<?php

declare(strict_types=1);

namespace Drupal\myeventlane_docs_importer\ValueObject;

/**
 * One normalized CSV row for MEL documentation import.
 */
final readonly class DocsImportRow {

  public function __construct(
    public int $lineNumber,
    public string $melId,
    public string $title,
    public string $audience,
    public string $type,
    public string $productArea,
    public string $surface,
    public string $owner,
    public string $status,
    public string $reviewCycle,
    public string $aiEligible,
    public string $summary,
    public string $body,
    public string $keywords,
    public string $ctaUrl,
    public string $ctaTitle,
    public string $relatedIds,
    public string $notes,
  ) {}

}
