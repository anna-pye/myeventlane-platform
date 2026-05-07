<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Immutable observability contract (metadata + governance boundaries only).
 *
 * @param list<string> $sourceSystems
 *   Human-readable owning system names (e.g. "MELStateSystem").
 * @param list<MelSurfaceId> $surfaceVisibility
 *   Surfaces where this contract may contribute explainability rows.
 */
final readonly class ObservabilityDefinition {

  /**
   * @param list<string> $sourceSystems
   * @param list<MelSurfaceId> $surfaceVisibility
   */
  public function __construct(
    public string $id,
    public MelObservabilityTraceCategory $category,
    public array $sourceSystems,
    public MelObservabilityVisibilityLevel $minimumVisibility,
    public string $explainabilityBoundaries,
    public string $privacyBoundaries,
    public array $surfaceVisibility,
    public string $accessibilityNotes,
    public int $sortOrder,
  ) {}

}
