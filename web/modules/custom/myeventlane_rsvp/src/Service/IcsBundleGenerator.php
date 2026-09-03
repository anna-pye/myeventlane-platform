<?php

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_core\Service\EventDateTimeResolver;

/**
 * Generates a multi-event ICS file for user RSVPs.
 */
class IcsBundleGenerator {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  public function __construct(
    EntityTypeManagerInterface $etm,
    private readonly EventDateTimeResolver $eventDateTime,
  ) {
    $this->entityTypeManager = $etm;
  }

  /**
   * Build one ICS file for all events the user has RSVP'd for.
   */
  public function generateForUser(AccountInterface $account) {
    $storage = $this->entityTypeManager->getStorage('node');

    $rsvp_nodes = $storage->loadByProperties([
      'type' => 'rsvp_submission',
      'uid'  => $account->id(),
    ]);

    $events = [];

    foreach ($rsvp_nodes as $rsvp) {
      $event = $rsvp->get('field_event')->entity;
      if (!$event) {
        continue;
      }

      $events[] = [
        'title' => $event->label(),
        'start' => $this->eventDateTime->formatFieldForIcalendar($event, 'field_event_start'),
        'end'   => $this->eventDateTime->formatFieldForIcalendar($event, 'field_event_end'),
        'url'   => $event->toUrl('canonical', ['absolute' => TRUE])->toString(),
        'location' => $event->get('field_location')->value ?? '',
      ];
    }

    return $this->buildIcs($events);
  }

  /**
   * ICS calendar assembly.
   */
  protected function buildIcs(array $events) {
    $lines = [];
    $lines[] = 'BEGIN:VCALENDAR';
    $lines[] = 'VERSION:2.0';
    $lines[] = 'PRODID:-//MyEventLane//RSVP Bundle//EN';

    foreach ($events as $event) {
      if (empty($event['start'])) {
        continue;
      }
      $uid = md5($event['title'] . $event['start']);

      $lines[] = 'BEGIN:VEVENT';
      $lines[] = 'UID:' . $uid;
      $lines[] = 'SUMMARY:' . $this->escape($event['title']);
      $lines[] = 'DTSTART:' . $event['start'];
      $lines[] = 'DTEND:' . (!empty($event['end']) ? $event['end'] : $event['start']);
      if (!empty($event['location'])) {
        $lines[] = 'LOCATION:' . $this->escape($event['location']);
      }
      $lines[] = 'URL:' . $event['url'];
      $lines[] = 'END:VEVENT';
    }

    $lines[] = 'END:VCALENDAR';

    return implode("\r\n", $lines);
  }

  /**
   * Escapes text for iCalendar output.
   */
  protected function escape($text) {
    return str_replace(',', '\,', $text);
  }

}
