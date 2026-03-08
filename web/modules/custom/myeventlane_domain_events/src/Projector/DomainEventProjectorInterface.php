<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\Projector;

/**
 * Contract for all domain event projectors.
 */
interface DomainEventProjectorInterface {

  /**
   * Stable projector ID used in projection state tracking.
   */
  public function id(): string;

  /**
   * Returns TRUE when this projector supports the given event type.
   */
  public function supports(string $eventType): bool;

  /**
   * Applies projection updates for one event payload.
   *
   * @param array<string, mixed> $event
   *   Event row from myeventlane_domain_event table.
   */
  public function project(array $event): void;

}

