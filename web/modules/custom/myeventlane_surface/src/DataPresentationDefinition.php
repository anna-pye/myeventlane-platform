<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Canonical presentation contract for operational/analytics UI (not data layer).
 */
final class DataPresentationDefinition {

  /**
   * @param list<string> $surfaceAffinity
   *   Surface machine names this contract is intended for.
   */
  public function __construct(
    public readonly string $machineName,
    public readonly string $category,
    public readonly string $description,
    public readonly array $surfaceAffinity,
  ) {}

}
