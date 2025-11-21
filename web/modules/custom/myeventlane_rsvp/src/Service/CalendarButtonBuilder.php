<?php

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\Core\Url;
use Drupal\node\NodeInterface;

final class CalendarButtonBuilder {

  public function build(NodeInterface $event): array {
    $google = $this->googleUrl($event);
    $outlook = $this->outlookUrl($event);
    $apple = Url::fromRoute('myeventlane_rsvp.ics_download', ['node' => $event->id()])
      ->toString();

    return [
      'google' => [
        '#type' => 'link',
        '#title' => t('Google Calendar'),
        '#url' => Url::fromUri($google),
        '#attributes' => ['class' => ['mel-btn', 'mel-btn-google']],
      ],
      'outlook' => [
        '#type' => 'link',
        '#title' => t('Outlook'),
        '#url' => Url::fromUri($outlook),
        '#attributes' => ['class' => ['mel-btn', 'mel-btn-outlook']],
      ],
      'apple' => [
        '#type' => 'link',
        '#title' => t('Apple Calendar'),
        '#url' => Url::fromUri('internal:' . $apple),
        '#attributes' => ['class' => ['mel-btn', 'mel-btn-apple']],
      ],
    ];
  }

  private function googleUrl(NodeInterface $event): string {
    $title = rawurlencode($event->label());
    $start = gmdate('Ymd\THis\Z', strtotime($event->get('field_event_start')->value));
    $end   = gmdate('Ymd\THis\Z', strtotime($event->get('field_event_end')->value ?? $event->get('field_event_start')->value));

    $details = rawurlencode(strip_tags($event->get('body')->value ?? ''));
    $location = rawurlencode($event->get('field_location')->value ?? '');

    return "https://calendar.google.com/calendar/render?action=TEMPLATE" .
      "&text=$title&dates={$start}/{$end}&details=$details&location=$location";
  }

  private function outlookUrl(NodeInterface $event): string {
    $title = rawurlencode($event->label());
    $start = gmdate('Y-m-d\TH:i:s\Z', strtotime($event->get('field_event_start')->value));
    $end   = gmdate('Y-m-d\TH:i:s\Z', strtotime($event->get('field_event_end')->value ?? $event->get('field_event_start')->value));

    return "https://outlook.live.com/calendar/0/deeplink/compose?subject={$title}&startdt={$start}&enddt={$end}";
  }

}