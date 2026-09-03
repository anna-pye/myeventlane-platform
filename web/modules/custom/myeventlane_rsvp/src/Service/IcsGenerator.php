<?php

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\myeventlane_core\Service\EventDateTimeResolver;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds a single-event .ics payload from an event node.
 */
final class IcsGenerator {

  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly EventDateTimeResolver $eventDateTime,
  ) {}

  /**
   * Generates ICS calendar content for an event node.
   *
   * @throws \InvalidArgumentException
   *   When required dates are missing or not parseable.
   */
  public function generate(NodeInterface $event): string {
    $title = $event->label();
    $startRaw = $event->get('field_event_start')->value;
    if ($startRaw === NULL || $startRaw === '') {
      $this->logger->error('ICS generation refused: event @nid has empty field_event_start.', [
        '@nid' => (string) $event->id(),
      ]);
      throw new \InvalidArgumentException('Event has no start date.');
    }

    $start = $this->eventDateTime->formatFieldForIcalendar($event, 'field_event_start');
    $end = $event->hasField('field_event_end') && !$event->get('field_event_end')->isEmpty()
      ? $this->eventDateTime->formatFieldForIcalendar($event, 'field_event_end')
      : $start;
    if ($start === NULL || $end === NULL) {
      $this->logger->error('ICS generation refused: unparseable date for event @nid.', [
        '@nid' => (string) $event->id(),
      ]);
      throw new \InvalidArgumentException('Invalid event date value.');
    }

    $location = $event->get('field_location')->value ?? '';
    $desc = strip_tags($event->get('body')->summary ?? $event->get('body')->value ?? '');

    $lines = [
      'BEGIN:VCALENDAR',
      'VERSION:2.0',
      'PRODID:-//MyEventLane//EN',
      'CALSCALE:GREGORIAN',
      'METHOD:PUBLISH',
      'BEGIN:VEVENT',
      "UID:event-{$event->id()}@myeventlane",
      "DTSTAMP:" . gmdate('Ymd\THis\Z'),
      "DTSTART:{$start}",
      "DTEND:{$end}",
      "SUMMARY:" . $this->escape($title),
      "DESCRIPTION:" . $this->escape($desc),
      "LOCATION:" . $this->escape($location),
      'END:VEVENT',
      'END:VCALENDAR',
    ];

    return implode("\r\n", $lines);
  }

  /**
   * Escapes text for ICS line folding / special characters.
   */
  private function escape(string $value): string {
    return preg_replace('/([,;])/', '\\\$1', $value);
  }

}
