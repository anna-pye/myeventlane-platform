<?php

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Generates a multi-event ICS file for user RSVPs.
 */
class IcsBundleGenerator {

  protected $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $etm) {
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
        'start' => $event->get('field_event_start')->value,
        'end'   => $event->get('field_event_end')->value,
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
      $uid = md5($event['title'] . $event['start']);

      $lines[] = 'BEGIN:VEVENT';
      $lines[] = 'UID:' . $uid;
      $lines[] = 'SUMMARY:' . $this->escape($event['title']);
      $lines[] = 'DTSTART:' . gmdate('Ymd\THis\Z', strtotime($event['start']));
      $lines[] = 'DTEND:' . gmdate('Ymd\THis\Z', strtotime($event['end']));
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
   *
   */
  protected function escape($text) {
    return str_replace(',', '\,', $text);
  }

}
