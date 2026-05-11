<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Readiness;

use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Future contract for domain-owned Event Studio readiness providers.
 *
 * Implementations are intentionally not wired into EventReadinessService yet.
 * The production readiness service remains the source of truth until provider
 * migration is scheduled with matching domain tests.
 */
interface EventReadinessProviderInterface {

  /**
   * Returns the stable provider identifier.
   */
  public function id(): string;

  /**
   * Returns the owning operational domain.
   */
  public function domain(): string;

  /**
   * Returns the section id this provider primarily supports, if any.
   */
  public function sectionId(): ?string;

  /**
   * Evaluates this provider's readiness signals for an event.
   */
  public function evaluate(NodeInterface $event, AccountInterface $account): EventReadinessProviderResult;

}
