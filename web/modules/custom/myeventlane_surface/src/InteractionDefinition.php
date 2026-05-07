<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Canonical contract for a governed MEL interaction (behavioural, not domain).
 */
final readonly class InteractionDefinition {

  /**
   * @param list<string> $surfaceAffinity
   *   Surfaces where the interaction is commonly composed (hints only).
   * @param list<string> $tags
   *   Optional tags (e.g. checkout_safe, progressive_disclosure).
   */
  public function __construct(
    public string $machineName,
    public string $category,
    public string $description,
    public array $surfaceAffinity = [],
    public array $tags = [],
  ) {}

}
