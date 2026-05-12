<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\DTO;

/**
 * Readonly view model for Event Studio operational projections.
 */
final class ReadonlySectionProjection {

  /**
   * @param list<array{0: mixed, 1: mixed}> $rows
   */
  public function __construct(
    public readonly string $title,
    public readonly array $rows,
    public readonly ?string $intro = NULL,
  ) {}

}
