<?php

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\node\NodeInterface;
use Drupal\Component\Utility\Unicode;

final class IcsGenerator {

  public function generate(NodeInterface $event): string {
    $title = Unicode::convertToUtf8($event->label());
    $start = $this->formatDate($event->get('field_event_start')->value);
    $end   = $this->formatDate($event->get('field_event_end')->value ?? $event->get('field_event_start')->value);

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

  private function escape(string $value): string {
    return preg_replace('/([,;])/','\\\$1', $value);
  }

  private function formatDate(string $date): string {
    return gmdate('Ymd\THis\Z', strtotime($date));
  }

}