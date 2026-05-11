<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\myeventlane_event\Value\EventOperationalType;
use Drupal\node\NodeInterface;

/**
 * Resolves operational event type metadata without changing event storage.
 */
final class EventOperationalTypeRegistry {

  /**
   * Returns all operational type definitions.
   *
   * @return array<string, array<string, mixed>>
   */
  public function definitions(): array {
    return EventOperationalType::definitions();
  }

  /**
   * Resolves the operational type id for an event.
   */
  public function resolve(NodeInterface $event): string {
    if ($event->bundle() !== 'event' || !$event->hasField('field_event_type') || $event->get('field_event_type')->isEmpty()) {
      return '';
    }
    return EventOperationalType::fromFieldEventType((string) $event->get('field_event_type')->value);
  }

  /**
   * Returns metadata for an operational type id.
   *
   * @return array<string, mixed>
   */
  public function definition(string $operational_type): array {
    return $this->definitions()[$operational_type] ?? [];
  }

}
