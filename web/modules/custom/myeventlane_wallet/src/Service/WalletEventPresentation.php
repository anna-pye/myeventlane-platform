<?php

declare(strict_types=1);

namespace Drupal\myeventlane_wallet\Service;

use Drupal\myeventlane_event\Service\EventGeoResolver;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\node\NodeInterface;

/**
 * Builds native Apple Wallet event-ticket presentation data.
 *
 * This service owns attendee-facing event, venue, address, date, and location
 * presentation. PkPassBuilder retains archive assembly and signing only.
 */
final class WalletEventPresentation {

  public function __construct(
    private readonly ?EventGeoResolver $eventGeoResolver = NULL,
  ) {}

  /**
   * Builds the compatible eventTicket fields and supported semantic metadata.
   *
   * @param array<string, mixed> $model
   *   The canonical issued-ticket view model.
   *
   * @return array{
   *   event_label: string,
   *   event_ticket: array<string, mixed>,
   *   relevant_date: string|null,
   *   expiration_date: string|null,
   *   locations: list<array{latitude: float, longitude: float, relevantText: string}>,
   *   semantics: array<string, mixed>
   * }
   *   Confirmed Apple Wallet presentation values.
   */
  public function build(Ticket $ticket, array $model, string $organisation): array {
    $event_label = trim((string) ($model['event']['label'] ?? ''));
    $has_event_label = $event_label !== '';
    if ($event_label === '') {
      $event_label = 'Event';
    }
    $holder = trim((string) ($model['holder']['name'] ?? ''));
    $entitlement = (string) ($model['ticket']['entitlement_label'] ?? $model['ticket']['entitlement_type'] ?? 'Ticket');
    $ticket_code = (string) ($model['ticket']['code'] ?? '');
    $organiser = trim((string) ($model['vendor']['label'] ?? ''));
    $venue = $this->venuePresentation($ticket, $model);
    $event_start = $this->eventDateIso($model, 'start');
    $event_end = $this->eventDateIso($model, 'end');

    $event_ticket = [
      'primaryFields' => [
        [
          'key' => 'event',
          'label' => 'EVENT',
          'value' => $event_label,
        ],
      ],
      'secondaryFields' => array_values(array_filter([
        $event_start !== NULL ? [
          'key' => 'event_date',
          'label' => 'DATE',
          'value' => $event_start,
          'dateStyle' => 'PKDateStyleMedium',
          'timeStyle' => 'PKDateStyleNone',
          'isRelative' => FALSE,
        ] : NULL,
        $event_start !== NULL ? [
          'key' => 'event_time',
          'label' => 'TIME',
          'value' => $event_start,
          'dateStyle' => 'PKDateStyleNone',
          'timeStyle' => 'PKDateStyleShort',
          'isRelative' => FALSE,
        ] : NULL,
      ])),
      'auxiliaryFields' => array_values(array_filter([
        $venue['name'] !== '' ? [
          'key' => 'venue_name',
          'label' => 'VENUE',
          'value' => $venue['name'],
        ] : NULL,
        $venue['name'] === '' && $venue['locality_region'] !== '' ? [
          'key' => 'venue_locality',
          'label' => 'VENUE',
          'value' => $venue['locality_region'],
        ] : NULL,
        $holder !== '' ? [
          'key' => 'holder',
          'label' => 'NAME',
          'value' => $holder,
        ] : NULL,
      ])),
      'backFields' => array_values(array_filter([
        $venue['address'] !== '' ? [
          'key' => 'venue_address_detail',
          'label' => 'Venue address',
          'value' => $venue['address'],
        ] : NULL,
        $holder !== '' ? [
          'key' => 'ticket_holder',
          'label' => 'Ticket holder',
          'value' => $holder,
        ] : NULL,
        [
          'key' => 'admission',
          'label' => 'Admission',
          'value' => $entitlement,
        ],
        [
          'key' => 'booking',
          'label' => 'Booking reference',
          'value' => $ticket_code,
        ],
        [
          'key' => 'ticket_code',
          'label' => 'Ticket code',
          'value' => $ticket_code,
        ],
        $organiser !== '' ? [
          'key' => 'organiser',
          'label' => 'Organiser',
          'value' => $organiser,
        ] : NULL,
        [
          'key' => 'issued_by',
          'label' => 'Issued by',
          'value' => $organisation,
        ],
      ])),
    ];

    $semantics = [];
    if ($has_event_label) {
      $semantics['eventName'] = $event_label;
    }
    if ($event_start !== NULL) {
      $semantics['eventStartDate'] = $event_start;
    }
    if ($event_end !== NULL) {
      $semantics['eventEndDate'] = $event_end;
    }
    if ($venue['name'] !== '') {
      $semantics['venueName'] = $venue['name'];
    }
    $locations = $this->resolveLocations($ticket, $venue['name'] ?: $venue['address']);
    if ($locations !== []) {
      $semantics['venueLocation'] = [
        'latitude' => $locations[0]['latitude'],
        'longitude' => $locations[0]['longitude'],
      ];
    }

    return [
      'event_label' => $event_label,
      'event_ticket' => $event_ticket,
      'relevant_date' => $event_start,
      'expiration_date' => $event_end,
      'locations' => $locations,
      'semantics' => $semantics,
    ];
  }

  /**
   * @param array<string, mixed> $model
   *
   * @return array{name: string, locality_region: string, address: string}
   *   Normalised venue details.
   */
  private function venuePresentation(Ticket $ticket, array $model): array {
    $event = $ticket->get('event_id')->entity;
    if (!$event instanceof NodeInterface) {
      return [
        'name' => '',
        'locality_region' => '',
        'address' => trim((string) ($model['event']['location'] ?? '')),
      ];
    }

    $name = $this->eventStringField($event, 'field_venue_name');
    $venue_entity = NULL;
    if ($event->hasField('field_venue') && !$event->get('field_venue')->isEmpty()) {
      $venue_entity = $event->get('field_venue')->entity;
      if ($name === '' && $venue_entity) {
        $name = trim((string) $venue_entity->label());
      }
    }

    $address = $this->eventAddressPresentation($event, 'field_venue_address');
    if ($address['formatted'] === '') {
      $address = $this->eventAddressPresentation($event, 'field_location');
    }
    if ($address['formatted'] === '' && $venue_entity && $venue_entity->hasField('primary_address') && !$venue_entity->get('primary_address')->isEmpty()) {
      $address['formatted'] = trim((string) $venue_entity->get('primary_address')->value);
    }
    if ($address['formatted'] === '') {
      $address['formatted'] = trim((string) ($model['event']['location'] ?? ''));
    }

    return [
      'name' => $name,
      'locality_region' => $address['locality_region'],
      'address' => $address['formatted'],
    ];
  }

  private function eventStringField(NodeInterface $event, string $field_name): string {
    if (!$event->hasField($field_name) || $event->get($field_name)->isEmpty()) {
      return '';
    }
    return trim((string) ($event->get($field_name)->value ?? ''));
  }

  /**
   * Returns both the canonical complete address and its front-pass-safe locality.
   *
   * Wallet must never abbreviate a street address merely to fit the front of a
   * pass. The complete canonical value belongs on the back; locality and region
   * are only used if there is no venue name to identify the event on the front.
   *
   * @return array{formatted: string, locality_region: string}
   *   Address presentations derived from one structured address field.
   */
  private function eventAddressPresentation(NodeInterface $event, string $field_name): array {
    if (!$event->hasField($field_name) || $event->get($field_name)->isEmpty()) {
      return ['formatted' => '', 'locality_region' => ''];
    }
    $value = $event->get($field_name)->first()?->getValue();
    if (!is_array($value)) {
      return ['formatted' => '', 'locality_region' => ''];
    }
    $parts = [];
    foreach (['address_line1', 'address_line2', 'locality', 'administrative_area', 'postal_code', 'country_code'] as $key) {
      if (!empty($value[$key]) && is_scalar($value[$key])) {
        $parts[] = trim((string) $value[$key]);
      }
    }
    $locality = trim((string) ($value['locality'] ?? ''));
    $region = trim((string) ($value['administrative_area'] ?? ''));
    return [
      'formatted' => implode(', ', array_filter($parts)),
      'locality_region' => implode(', ', array_filter([$locality, $region])),
    ];
  }

  /**
   * @param array<string, mixed> $model
   */
  private function eventDateIso(array $model, string $key): ?string {
    $date = $model['event'][$key] ?? NULL;
    if (!is_array($date)) {
      return NULL;
    }
    $timestamp = $date['timestamp'] ?? NULL;
    return is_int($timestamp) && $timestamp > 0
      ? gmdate('Y-m-d\TH:i:s\Z', $timestamp)
      : NULL;
  }

  /**
   * @return list<array{latitude: float, longitude: float, relevantText: string}>
   */
  private function resolveLocations(Ticket $ticket, string $location_label): array {
    $event = $ticket->get('event_id')->entity;
    if (!$this->eventGeoResolver instanceof EventGeoResolver || !$event instanceof NodeInterface || $event->bundle() !== 'event') {
      return [];
    }

    $geo = $this->eventGeoResolver->resolve($event);
    $lat = $geo['lat'] ?? NULL;
    $lng = $geo['lng'] ?? NULL;
    if ((!is_float($lat) && !is_int($lat)) || (!is_float($lng) && !is_int($lng))) {
      return [];
    }
    $lat = (float) $lat;
    $lng = (float) $lng;
    if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0 || ($lat === 0.0 && $lng === 0.0)) {
      return [];
    }

    return [[
      'latitude' => $lat,
      'longitude' => $lng,
      'relevantText' => $location_label !== '' ? 'Nearby: ' . $location_label : 'Your event is nearby',
    ]];
  }

}
