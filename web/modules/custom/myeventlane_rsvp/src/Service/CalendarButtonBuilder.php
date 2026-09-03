<?php

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\Core\Url;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\myeventlane_core\Service\EventDateTimeResolver;
use Drupal\node\NodeInterface;

/**
 * Builds event calendar links.
 */
final class CalendarButtonBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly EventDateTimeResolver $eventDateTime,
  ) {}

  /**
   * Builds Google, Outlook, and Apple calendar links.
   */
  public function build(NodeInterface $event): array {
    $google = $this->googleUrl($event);
    $outlook = $this->outlookUrl($event);
    $apple = Url::fromRoute('myeventlane_rsvp.ics_download', ['node' => $event->id()])
      ->toString();

    return [
      'google' => [
        '#type' => 'link',
        '#title' => $this->t('Google Calendar'),
        '#url' => Url::fromUri($google),
        '#attributes' => ['class' => ['mel-btn', 'mel-btn-google']],
      ],
      'outlook' => [
        '#type' => 'link',
        '#title' => $this->t('Outlook'),
        '#url' => Url::fromUri($outlook),
        '#attributes' => ['class' => ['mel-btn', 'mel-btn-outlook']],
      ],
      'apple' => [
        '#type' => 'link',
        '#title' => $this->t('Apple Calendar'),
        '#url' => Url::fromUri('internal:' . $apple),
        '#attributes' => ['class' => ['mel-btn', 'mel-btn-apple']],
      ],
    ];
  }

  /**
   * Builds a Google Calendar URL.
   */
  private function googleUrl(NodeInterface $event): string {
    $title = rawurlencode($event->label());
    $start = $this->eventDateTime->formatFieldForIcalendar($event, 'field_event_start') ?? '';
    $end = $this->eventDateTime->formatFieldForIcalendar($event, 'field_event_end') ?? $start;

    $details = rawurlencode(strip_tags($event->get('body')->value ?? ''));
    $location = rawurlencode($event->get('field_location')->value ?? '');

    return "https://calendar.google.com/calendar/render?action=TEMPLATE" .
      "&text=$title&dates={$start}/{$end}&details=$details&location=$location";
  }

  /**
   * Builds an Outlook Calendar URL.
   */
  private function outlookUrl(NodeInterface $event): string {
    $title = rawurlencode($event->label());
    $start = $this->formatOutlookDate($event, 'field_event_start') ?? '';
    $end = $this->formatOutlookDate($event, 'field_event_end') ?? $start;

    return "https://outlook.live.com/calendar/0/deeplink/compose?subject={$title}&startdt={$start}&enddt={$end}";
  }

  /**
   * Formats an event field for the Outlook URL.
   */
  private function formatOutlookDate(NodeInterface $event, string $fieldName): ?string {
    return $this->eventDateTime
      ->getFieldDateTime($event, $fieldName)
      ?->setTimezone(new \DateTimeZone('UTC'))
      ?->format('Y-m-d\TH:i:s\Z');
  }

}
