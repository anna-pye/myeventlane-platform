<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Component\Utility\Unicode;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\NodeInterface;

/**
 * Builds RFC 5545 iCalendar payloads for published event nodes.
 */
final class EventIcalendarBuilder {

  /**
   * Builds VCALENDAR text for downloading, or NULL if no usable start date.
   */
  public function buildCalendar(NodeInterface $node): ?string {
    if ($node->bundle() !== 'event') {
      return NULL;
    }

    $startUtc = $this->getUtcFromDatetimeField($node, 'field_event_start');
    if ($startUtc === NULL) {
      return NULL;
    }

    $endUtc = $this->getUtcFromDatetimeField($node, 'field_event_end');
    if ($endUtc === NULL) {
      $endUtc = $startUtc->modify('+2 hours');
    }
    elseif ($endUtc <= $startUtc) {
      $endUtc = $startUtc->modify('+2 hours');
    }

    $title = Unicode::truncate($node->label(), 160, TRUE, TRUE);
    $location = Unicode::truncate($this->resolveLocationLine($node), 200, TRUE, TRUE);
    $detail = Unicode::truncate($this->resolveDescriptionBody($node), 700, TRUE, TRUE);
    $canonical = $node->toUrl()->setAbsolute()->toString();

    $host = $_SERVER['HTTP_HOST'] ?? 'myeventlane';
    $uid = sprintf(
      'mel-event-%d@%s',
      (int) $node->id(),
      preg_replace('/[^a-zA-Z0-9.-]/', '-', $host),
    );

    $lines = [
      'BEGIN:VCALENDAR',
      'VERSION:2.0',
      'CALSCALE:GREGORIAN',
      'PRODID:-//MyEventLane//Event Calendar//EN',
      'METHOD:PUBLISH',
      'BEGIN:VEVENT',
      'UID:' . $this->escapeStructuredValue($uid),
      'DTSTAMP:' . gmdate('Ymd\THis\Z'),
      'DTSTART:' . $startUtc->format('Ymd\THis\Z'),
      'DTEND:' . $endUtc->format('Ymd\THis\Z'),
      'SUMMARY:' . $this->escapeTextValue($title),
      'URL:' . $this->escapeStructuredValue($canonical),
    ];

    $descPieces = trim($detail !== '' ? trim($detail) . '\n\n' . $canonical : $canonical, "\n\r\t ");
    $lines[] = 'DESCRIPTION:' . $this->escapeTextValue($descPieces !== '' ? $descPieces : $canonical);

    if ($location !== '') {
      $lines[] = 'LOCATION:' . $this->escapeTextValue($location);
    }

    $lines[] = 'STATUS:CONFIRMED';
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';

    return implode("\r\n", $lines) . "\r\n";
  }

  /**
   * Sanitizes ASCII filename for calendar attachment.
   */
  public function downloadFilename(NodeInterface $node): string {
    $base = preg_replace('/[^a-z0-9-]+/', '-', strtolower($node->getTitle()));
    $base = trim((string) preg_replace('/-+/', '-', (string) $base), '-');
    if ($base === '') {
      $base = 'event-' . ((int) $node->id());
    }

    return Unicode::truncate($base, 60, TRUE, '') . '.ics';
  }

  private function resolveDescriptionBody(NodeInterface $node): string {
    if ($node->hasField('field_event_summary') && !$node->get('field_event_summary')->isEmpty()) {
      $item = $node->get('field_event_summary')->first();
      if ($item !== NULL && isset($item->value)) {
        $text = strip_tags(trim((string) $item->value));
        if ($text !== '') {
          return $text;
        }
      }
    }

    foreach (['body', 'field_event_intro'] as $field_name) {
      if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
        continue;
      }
      foreach ($node->get($field_name) as $item) {
        if (isset($item->value)) {
          $inner = strip_tags(trim((string) $item->value));
          if ($inner !== '') {
            return Unicode::truncate($inner, 2000, TRUE, TRUE);
          }
        }
      }
    }

    return '';
  }

  private function resolveLocationLine(NodeInterface $node): string {
    $parts = [];
    if ($node->hasField('field_venue_name') && !$node->get('field_venue_name')->isEmpty()) {
      $parts[] = trim((string) $node->get('field_venue_name')->value);
    }
    if ($node->hasField('field_location') && !$node->get('field_location')->isEmpty()) {
      $address_item = $node->get('field_location')->first();
      if ($address_item !== NULL) {
        $address = $address_item->getValue();
        foreach (['address_line1', 'address_line2', 'locality', 'administrative_area', 'postal_code'] as $key) {
          if (!empty($address[$key]) && is_string($address[$key])) {
            $parts[] = trim($address[$key]);
          }
        }
      }
    }

    return implode(', ', array_values(array_unique(array_filter($parts, static fn(string $p): bool => $p !== ''))));
  }

  private function getUtcFromDatetimeField(NodeInterface $node, string $field_name): ?\DateTimeImmutable {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return NULL;
    }
    $value = trim((string) $node->get($field_name)->value);
    if ($value === '') {
      return NULL;
    }

    $parsed = DrupalDateTime::createFromFormat(
      DateTimeItemInterface::DATETIME_STORAGE_FORMAT,
      $value,
      DateTimeItemInterface::STORAGE_TIMEZONE,
    );
    if (!$parsed instanceof DrupalDateTime) {
      return NULL;
    }

    try {
      $immutable = \DateTimeImmutable::createFromInterface($parsed);
    }
    catch (\Throwable) {
      return NULL;
    }

    return $immutable->setTimezone(new \DateTimeZone('UTC'));
  }

  /**
   * Escapes ICS TEXT value (comma, newline, slash).
   */
  private function escapeTextValue(string $text): string {
    return str_replace(["\\", "\r\n", "\n", "\r", ';', ','], ["\\\\", '\n', '\n', '\n', '\\;', '\\,'], trim($text));
  }

  private function escapeStructuredValue(string $value): string {
    return str_replace(["\\", ',', ';'], ['\\\\', '\\,', '\\;'], trim($value));
  }

}
