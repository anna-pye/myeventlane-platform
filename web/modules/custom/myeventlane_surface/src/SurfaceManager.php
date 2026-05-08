<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;

/**
 * Provides access to registered surface definitions.
 */
final class SurfaceManager {

  /**
   * @var array<string, \Drupal\myeventlane_surface\SurfaceDefinition>
   */
  private array $definitions;

  public function __construct(
    private readonly SurfaceRegistry $registry,
  ) {
    $this->definitions = $this->registry->all();
  }

  public function get(MelSurfaceId $surface): SurfaceDefinition {
    return $this->definitions[$surface->value];
  }

  /**
   * @return array<string, \Drupal\myeventlane_surface\SurfaceDefinition>
   */
  public function all(): array {
    return $this->definitions;
  }

}
