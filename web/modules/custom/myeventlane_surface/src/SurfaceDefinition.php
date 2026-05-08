<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;

/**
 * Immutable definition for a MEL product surface.
 */
final readonly class SurfaceDefinition {

  /**
   * @param array<string, mixed> $routeOwnership
   * @param array<string, mixed> $layoutOwnership
   * @param array<string, mixed> $navigationOwnership
   * @param array<string, mixed> $allowedComponents
   * @param array<string, mixed> $formOwnership
   * @param array<string, mixed> $blockVisibilityRules
   * @param array<string, mixed> $uxRules
   * @param array<string, mixed> $accessibilityExpectations
   */
  public function __construct(
    public MelSurfaceId $id,
    public string $label,
    public array $routeOwnership,
    public array $layoutOwnership,
    public array $navigationOwnership,
    public array $allowedComponents,
    public array $formOwnership,
    public array $blockVisibilityRules,
    public array $uxRules,
    public array $accessibilityExpectations,
  ) {}

}
