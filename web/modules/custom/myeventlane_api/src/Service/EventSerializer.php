<?php

declare(strict_types=1);

namespace Drupal\myeventlane_api\Service;

use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\EventDateTimeResolver;
use Drupal\myeventlane_core\Service\EventStateResolver;
use Drupal\myeventlane_event_state\Service\EventStateResolverInterface;
use Drupal\myeventlane_metrics\Service\EventMetricsServiceInterface;
use Drupal\node\NodeInterface;

/**
 * Service for serializing event nodes for API responses.
 */
final class EventSerializer {

  /**
   * Constructs EventSerializer.
   */
  public function __construct(
    private readonly EventStateResolverInterface $eventStateResolver,
    private readonly EventStateResolver $eventDomainStateResolver,
    private readonly EventMetricsServiceInterface $eventMetricsService,
    private readonly EventDateTimeResolver $eventDateTime,
  ) {}

  /**
   * Serializes an event for public API.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Serialized event data.
   */
  public function serializePublic(NodeInterface $event): array {
    if ($event->bundle() !== 'event') {
      throw new \InvalidArgumentException('Node must be an event.');
    }

    $state = $this->eventStateResolver->resolveState($event);
    $capacity_total = $this->eventMetricsService->getCapacityTotal($event);
    $remaining = $this->eventMetricsService->getRemainingCapacity($event);

    // Get event image URL if available.
    $image_url = NULL;
    if ($event->hasField('field_event_image') && !$event->get('field_event_image')->isEmpty()) {
      $image = $event->get('field_event_image')->entity;
      if ($image) {
        $image_url = $image->createFileUrl();
      }
    }

    // Get location data (safe subset).
    $location = NULL;
    if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      $address = $event->get('field_location')->first();
      $location = [
        'venue_name' => $event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()
          ? $event->get('field_venue_name')->value
          : NULL,
        'address' => $address ? [
          'address_line1' => $address->get('address_line1')->value ?? NULL,
          'address_line2' => $address->get('address_line2')->value ?? NULL,
          'locality' => $address->get('locality')->value ?? NULL,
          'administrative_area' => $address->get('administrative_area')->value ?? NULL,
          'postal_code' => $address->get('postal_code')->value ?? NULL,
          'country_code' => $address->get('country_code')->value ?? NULL,
        ] : NULL,
        'coordinates' => NULL,
      ];

      // Add coordinates if available.
      if ($event->hasField('field_location_latitude') && !$event->get('field_location_latitude')->isEmpty() &&
          $event->hasField('field_location_longitude') && !$event->get('field_location_longitude')->isEmpty()) {
        $location['coordinates'] = [
          'latitude' => (float) $event->get('field_location_latitude')->value,
          'longitude' => (float) $event->get('field_location_longitude')->value,
        ];
      }
    }

    // Get categories.
    $categories = [];
    if ($event->hasField('field_category') && !$event->get('field_category')->isEmpty()) {
      foreach ($event->get('field_category') as $item) {
        $term = $item->entity;
        if ($term) {
          $categories[] = [
            'id' => (int) $term->id(),
            'name' => $term->getName(),
            'slug' => \Drupal::service('pathauto.alias_cleaner')->cleanString($term->getName()),
          ];
        }
      }
    }

    // Determine CTA mode.
    $event_type = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? $event->get('field_event_type')->value
      : NULL;

    $has_product = $this->eventDomainStateResolver->getEventDomainState($event)['has_product'];
    $has_external_url = $event->hasField('field_external_url') && !$event->get('field_external_url')->isEmpty();

    $cta_mode = 'none';
    if ($event_type === 'external' && $has_external_url) {
      $cta_mode = 'external';
    }
    elseif ($has_product && in_array($event_type, ['paid', 'both'])) {
      $cta_mode = 'tickets';
    }
    elseif ($event_type === 'rsvp' || ($event_type === 'both' && !$has_product)) {
      $cta_mode = 'rsvp';
    }

    // Get sales window.
    $sales_start = $this->eventStateResolver->getSalesStart($event);
    $sales_end = $this->eventStateResolver->getSalesEnd($event);

    // Calendar links.
    $calendar_links = $this->getCalendarLinks($event);

    $data = [
      'id' => (int) $event->id(),
      'title' => $event->label(),
      'summary' => $event->hasField('body') && !$event->get('body')->isEmpty()
        ? strip_tags($event->get('body')->summary ?? '')
        : NULL,
      'description' => $event->hasField('body') && !$event->get('body')->isEmpty()
        ? strip_tags($event->get('body')->value ?? '')
        : NULL,
      'start' => $event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()
        ? $this->eventDateTime->getFieldDateTime($event, 'field_event_start')?->format(\DateTimeInterface::ATOM)
        : NULL,
      'end' => $event->hasField('field_event_end') && !$event->get('field_event_end')->isEmpty()
        ? $this->eventDateTime->getFieldDateTime($event, 'field_event_end')?->format(\DateTimeInterface::ATOM)
        : NULL,
      'location' => $location,
      'categories' => $categories,
      'image_url' => $image_url,
      'state' => $state,
      'sales_window' => [
        'start' => $sales_start ? date('c', $sales_start) : NULL,
        'end' => $sales_end ? date('c', $sales_end) : NULL,
      ],
      'capacity' => [
        'total' => $capacity_total,
        'remaining' => $remaining,
        'unlimited' => $capacity_total === NULL,
      ],
      'cta_mode' => $cta_mode,
      'external_url' => $has_external_url && $event->get('field_external_url')->uri
        ? $event->get('field_external_url')->uri
        : NULL,
      'urls' => [
        'canonical' => $event->toUrl('canonical', ['absolute' => TRUE])->toString(),
        'calendar' => $calendar_links,
      ],
    ];

    return $data;
  }

  /**
   * Gets calendar links for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array of calendar link URLs.
   */
  private function getCalendarLinks(NodeInterface $event): array {
    $links = [];

    // ICS download link.
    $ics_url = Url::fromRoute('myeventlane_rsvp.ics_download', ['node' => $event->id()], ['absolute' => TRUE]);
    $links['ics'] = $ics_url->toString();

    // Google Calendar URL.
    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $title = rawurlencode($event->label());
      $start = $this->eventDateTime->formatFieldForIcalendar($event, 'field_event_start') ?? '';
      $end = $event->hasField('field_event_end') && !$event->get('field_event_end')->isEmpty()
        ? ($this->eventDateTime->formatFieldForIcalendar($event, 'field_event_end') ?? $start)
        : $start;

      $details = '';
      if ($event->hasField('body') && !$event->get('body')->isEmpty()) {
        $details = rawurlencode(strip_tags($event->get('body')->summary ?? $event->get('body')->value ?? ''));
      }

      $location = '';
      if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
        $address = $event->get('field_location')->first();
        if ($address) {
          $parts = array_filter([
            $address->get('address_line1')->value ?? NULL,
            $address->get('locality')->value ?? NULL,
            $address->get('administrative_area')->value ?? NULL,
            $address->get('postal_code')->value ?? NULL,
          ]);
          $location = rawurlencode(implode(', ', $parts));
        }
      }

      $links['google'] = "https://calendar.google.com/calendar/render?action=TEMPLATE" .
        "&text=$title&dates={$start}/{$end}&details=$details&location=$location";

      // Outlook Calendar URL.
      $start_outlook = $this->formatOutlookDate($event, 'field_event_start') ?? '';
      $end_outlook = $event->hasField('field_event_end') && !$event->get('field_event_end')->isEmpty()
        ? ($this->formatOutlookDate($event, 'field_event_end') ?? $start_outlook)
        : $start_outlook;

      $links['outlook'] = "https://outlook.live.com/calendar/0/deeplink/compose?subject={$title}&startdt={$start_outlook}&enddt={$end_outlook}";

      // Apple Calendar uses ICS.
      $links['apple'] = $links['ics'];
    }

    return $links;
  }

  /**
   * Formats an event field as an Outlook-compatible UTC value.
   */
  private function formatOutlookDate(NodeInterface $event, string $fieldName): ?string {
    return $this->eventDateTime
      ->getFieldDateTime($event, $fieldName)
      ?->setTimezone(new \DateTimeZone('UTC'))
      ?->format('Y-m-d\TH:i:s\Z');
  }

}
