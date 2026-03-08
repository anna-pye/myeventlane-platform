<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\Service;

use Drupal\myeventlane_domain_events\Projector\DomainEventProjectorInterface;

/**
 * Registry for tagged domain event projectors.
 */
final class ProjectorRegistry {

  /**
   * @var \Drupal\myeventlane_domain_events\Projector\DomainEventProjectorInterface[]
   */
  private array $projectors = [];

  /**
   * @param iterable<\Drupal\myeventlane_domain_events\Projector\DomainEventProjectorInterface> $projectors
   *   Tagged projector services.
   */
  public function __construct(iterable $projectors) {
    foreach ($projectors as $projector) {
      $this->projectors[] = $projector;
    }
  }

  /**
   * Returns projectors supporting the given event type.
   *
   * @return \Drupal\myeventlane_domain_events\Projector\DomainEventProjectorInterface[]
   *   Supporting projector services.
   */
  public function getProjectorsForEventType(string $eventType): array {
    $matches = [];
    foreach ($this->projectors as $projector) {
      if ($projector->supports($eventType)) {
        $matches[] = $projector;
      }
    }
    return $matches;
  }

  /**
   * Returns all registered projectors.
   *
   * @return \Drupal\myeventlane_domain_events\Projector\DomainEventProjectorInterface[]
   *   Registered projector services.
   */
  public function getProjectors(): array {
    return $this->projectors;
  }

}

