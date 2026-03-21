<?php

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\node\NodeInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Builds a single-event .ics payload from an event node.
 */
final class IcsGenerator {

  public function __construct(
    private readonly LoggerInterface $logger,
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
      throw new InvalidArgumentException('Event has no start date.');
    }

    $endRaw = $event->get('field_event_end')->value;
    if ($endRaw === NULL || $endRaw === '') {
      $endRaw = $startRaw;
    }

    $start = $this->formatDate($startRaw, $event->id());
    $end = $this->formatDate($endRaw, $event->id());

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

  /**
   * Formats a stored datetime string as UTC ICS datetime.
   */
  private function formatDate(string $date, int|string $eventId): string {
    $timestamp = strtotime($date);
    if ($timestamp === FALSE) {
      $this->logger->error('ICS generation refused: unparseable date "@date" for event @nid.', [
        '@date' => $date,
        '@nid' => (string) $eventId,
      ]);
      throw new InvalidArgumentException('Invalid event date value.');
    }

    return gmdate('Ymd\THis\Z', $timestamp);
  }

}
