<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Documentation contract for a governed MEL UI component.
 */
final readonly class ComponentDefinition {

  /**
   * @param list<string> $surfaceAffinity
   *   Surfaces where the component is commonly composed (hints only).
   */
  public function __construct(
    public string $machineName,
    public string $category,
    public string $description,
    public array $surfaceAffinity = [],
  ) {}

}
