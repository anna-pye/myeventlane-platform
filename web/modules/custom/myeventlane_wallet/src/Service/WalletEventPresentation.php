<?php

declare(strict_types=1);

namespace Drupal\myeventlane_wallet\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\crop\Entity\Crop;
use Drupal\crop\CropInterface;
use Drupal\file\FileInterface;
use Drupal\myeventlane_event\Service\EventGeoResolver;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds confirmed Apple Wallet event-ticket presentation data.
 *
 * This service owns attendee-facing event, venue, date, and location
 * presentation. PkPassBuilder retains archive assembly and signing only.
 */
final class WalletEventPresentation {

  /**
   * Apple PassKit event-ticket strip size at @2x.
   */
  private const STRIP_WIDTH = 750;

  private const STRIP_HEIGHT = 196;

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerInterface $logger,
    private readonly ?EventGeoResolver $eventGeoResolver = NULL,
  ) {}

  /**
   * Builds the legacy eventTicket fields and supported semantic metadata.
   *
   * @param array<string, mixed> $model
   *   The canonical issued-ticket view model.
   *
   * @return array{
   *   event_label: string,
   *   event_ticket: array<string, mixed>,
   *   relevant_date: string|null,
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
        $venue['address'] !== '' ? [
          'key' => 'venue_address',
          'label' => 'ADDRESS',
          'value' => $venue['address'],
        ] : NULL,
        [
          'key' => 'ticket_type',
          'label' => 'TICKET',
          'value' => $entitlement,
        ],
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
      'locations' => $locations,
      'semantics' => $semantics,
    ];
  }

  /**
   * @param array<string, mixed> $model
   *
   * @return array{name: string, address: string}
   *   Normalised venue details.
   */
  private function venuePresentation(Ticket $ticket, array $model): array {
    $event = $ticket->get('event_id')->entity;
    if (!$event instanceof NodeInterface) {
      return [
        'name' => '',
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

    $address = $this->eventAddressField($event, 'field_venue_address');
    if ($address === '') {
      $address = $this->eventAddressField($event, 'field_location');
    }
    if ($address === '' && $venue_entity && $venue_entity->hasField('primary_address') && !$venue_entity->get('primary_address')->isEmpty()) {
      $address = trim((string) $venue_entity->get('primary_address')->value);
    }
    if ($address === '') {
      $address = trim((string) ($model['event']['location'] ?? ''));
    }

    return [
      'name' => $name,
      'address' => $address,
    ];
  }

  private function eventStringField(NodeInterface $event, string $field_name): string {
    if (!$event->hasField($field_name) || $event->get($field_name)->isEmpty()) {
      return '';
    }
    return trim((string) ($event->get($field_name)->value ?? ''));
  }

  private function eventAddressField(NodeInterface $event, string $field_name): string {
    if (!$event->hasField($field_name) || $event->get($field_name)->isEmpty()) {
      return '';
    }
    $value = $event->get($field_name)->first()?->getValue();
    if (!is_array($value)) {
      return '';
    }
    $parts = [];
    foreach (['address_line1', 'address_line2', 'locality', 'administrative_area', 'postal_code', 'country_code'] as $key) {
      if (!empty($value[$key]) && is_scalar($value[$key])) {
        $parts[] = trim((string) $value[$key]);
      }
    }
    return implode(', ', array_filter($parts));
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

  /**
   * Writes strip.png from the event hero, or returns FALSE to omit it.
   *
   * Homepage merchandising eligibility is deliberately not consulted: a
   * purchased ticket must use its event hero whenever the image is usable.
   */
  public function writeStripImage(string $destination, Ticket $ticket): bool {
    $hero = $this->resolveEventHeroImage($ticket);
    if ($hero === NULL) {
      return FALSE;
    }
    if (!function_exists('imagecreatetruecolor')) {
      $this->logger->warning('GD unavailable; using MEL strip fallback for Apple Wallet.');
      return FALSE;
    }

    $source = @imagecreatefromstring((string) file_get_contents($hero['path']));
    if ($source === FALSE) {
      $this->logger->warning('Unable to decode event hero for Apple Wallet strip; using MEL fallback.');
      return FALSE;
    }

    $src_w = imagesx($source);
    $src_h = imagesy($source);
    if ($src_w < self::STRIP_WIDTH || $src_h < self::STRIP_HEIGHT) {
      $this->logger->warning('Event hero is too small for Apple Wallet strip; using MEL fallback.');
      return FALSE;
    }

    $target_ratio = self::STRIP_WIDTH / self::STRIP_HEIGHT;
    if (($src_w / $src_h) > $target_ratio) {
      $crop_h = $src_h;
      $crop_w = (int) round($src_h * $target_ratio);
      $crop_x = $this->cropOrigin($this->heroFocalCoordinate($hero['uri'], 'x', $src_w, $src_h), $crop_w, $src_w);
      $crop_y = 0;
    }
    else {
      $crop_w = $src_w;
      $crop_h = (int) round($src_w / $target_ratio);
      $crop_x = 0;
      $crop_y = $this->cropOrigin($this->heroFocalCoordinate($hero['uri'], 'y', $src_w, $src_h), $crop_h, $src_h);
    }

    $destination_image = imagecreatetruecolor(self::STRIP_WIDTH, self::STRIP_HEIGHT);
    if ($destination_image === FALSE) {
      return FALSE;
    }
    imagecopyresampled($destination_image, $source, 0, 0, $crop_x, $crop_y, self::STRIP_WIDTH, self::STRIP_HEIGHT, $crop_w, $crop_h);
    $ok = imagepng($destination_image, $destination);
    if (!$ok) {
      $this->logger->error('Unable to write Apple Wallet strip.png from event hero.');
    }
    return $ok;
  }

  /**
   * @return array{path: string, uri: string}|null
   *   A locally readable event hero image.
   */
  private function resolveEventHeroImage(Ticket $ticket): ?array {
    $event = $ticket->get('event_id')->entity;
    if (!$event instanceof NodeInterface || !$event->hasField('field_event_image') || $event->get('field_event_image')->isEmpty()) {
      return NULL;
    }

    $file = $event->get('field_event_image')->entity;
    if (!$file instanceof FileInterface) {
      $media = $event->get('field_event_image')->entity;
      if ($media && method_exists($media, 'hasField') && $media->hasField('field_media_image') && !$media->get('field_media_image')->isEmpty()) {
        $file = $media->get('field_media_image')->entity;
      }
    }
    if (!$file instanceof FileInterface) {
      return NULL;
    }

    $uri = $file->getFileUri();
    $path = $this->fileSystem->realpath($uri);
    if (!is_string($uri) || $uri === '' || !is_string($path) || $path === '' || !is_file($path)) {
      return NULL;
    }
    if ($file->getMimeType() !== '' && !in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], TRUE)) {
      return NULL;
    }
    return ['path' => $path, 'uri' => $uri];
  }

  private function heroFocalCoordinate(string $uri, string $axis, int $source_width, int $source_height): int {
    foreach (['event_hero', 'focal_point'] as $crop_type) {
      $crop = Crop::findCrop($uri, $crop_type);
      if (!$crop instanceof CropInterface || $crop->get($axis)->isEmpty()) {
        continue;
      }
      $coordinate = (int) $crop->get($axis)->value;
      $extent_property = $axis === 'x' ? 'width' : 'height';
      if (!$crop->get($extent_property)->isEmpty()) {
        $coordinate += (int) floor(((int) $crop->get($extent_property)->value) / 2);
      }
      return max(0, min($coordinate, $axis === 'x' ? $source_width : $source_height));
    }
    return (int) floor(($axis === 'x' ? $source_width : $source_height) / 2);
  }

  private function cropOrigin(int $focal_coordinate, int $crop_extent, int $source_extent): int {
    return max(0, min((int) round($focal_coordinate - ($crop_extent / 2)), max(0, $source_extent - $crop_extent)));
  }

}
