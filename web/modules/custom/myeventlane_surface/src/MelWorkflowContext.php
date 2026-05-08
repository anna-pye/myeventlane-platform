<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;

use Drupal\Core\Session\AccountInterface;

/**
 * Resolved behavioural context for workflow orchestration (no entity payloads).
 */
final readonly class MelWorkflowContext {

  /**
   * @param array<string, bool|int|string> $signals
   *   Normalised, non-sensitive booleans/strings for UX decisions only.
   * @param object|null $vendorOnboardingState
   *   Vendor-track onboarding state entity when loaded for vendor shells.
   */
  public function __construct(
    public MelSurfaceId $surface,
    public string $routeName,
    public string $path,
    public AccountInterface $account,
    public bool $checkoutSensitive,
    public array $signals,
    public ?object $vendorOnboardingState = NULL,
  ) {}

}
