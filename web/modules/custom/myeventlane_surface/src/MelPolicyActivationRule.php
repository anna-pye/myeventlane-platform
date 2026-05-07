<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Single activation clause for an operational policy (all parts AND together).
 *
 * Empty arrays for a clause mean that clause is not required (vacuous).
 */
final readonly class MelPolicyActivationRule {

  /**
   * @param list<string> $surfacesAny
   *   MelSurfaceId enum values; empty = any surface.
   * @param list<string> $anyOfStateSatisfied
   * @param list<string> $anyOfStateUnsatisfied
   * @param list<string> $allOfStateSatisfied
   * @param list<string> $anyOfMergedTruthy
   * @param list<string> $noneOfStateSatisfied
   *   If any listed state is Satisfied, the rule does not match.
   */
  public function __construct(
    public bool $requireAuthentication = FALSE,
    public array $surfacesAny = [],
    public array $anyOfStateSatisfied = [],
    public array $anyOfStateUnsatisfied = [],
    public array $allOfStateSatisfied = [],
    public array $anyOfMergedTruthy = [],
    public bool $requireCheckoutSensitive = FALSE,
    public array $noneOfStateSatisfied = [],
  ) {}

}
