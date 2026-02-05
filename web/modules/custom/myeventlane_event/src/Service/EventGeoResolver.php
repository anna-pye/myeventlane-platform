<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\node\NodeInterface;

/**
 * Resolves canonical event geo coordinates with legacy fallbacks.
 *
 * Precedence (no deviation):
 * 1) field_event_geo (Geofield POINT)
 * 2) Legacy event lat/lng fields (field_event_lat / field_event_lng)
 * 3) field_location derived coordinates (including legacy field_location_latitude/longitude)
 * 4) field_venue entity coordinates (if available)
 * 5) null
 *
 * No side effects: no writes, no storage, no exceptions thrown.
 */
final class EventGeoResolver {

  /**
   * Resolve latitude/longitude for an event node.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array{lat: ?float, lng: ?float, source: 'event_geo'|'legacy_event'|'location'|'venue'|'none'}
   *   Resolved geo data.
   */
  public function resolve(NodeInterface $event): array {
    if ($event->bundle() !== 'event') {
      return [
        'lat' => NULL,
        'lng' => NULL,
        'source' => 'none',
      ];
    }

    // 1) field_event_geo (Geofield POINT).
    $from_event_geo = $this->resolveFromEventGeo($event);
    if ($from_event_geo['lat'] !== NULL && $from_event_geo['lng'] !== NULL) {
      return $from_event_geo;
    }

    // 2) Legacy event lat/lng fields.
    $from_legacy_event = $this->resolveFromLegacyEventLatLng($event);
    if ($from_legacy_event['lat'] !== NULL && $from_legacy_event['lng'] !== NULL) {
      return $from_legacy_event;
    }

    // 3) field_location derived coordinates.
    $from_location = $this->resolveFromLocation($event);
    if ($from_location['lat'] !== NULL && $from_location['lng'] !== NULL) {
      return $from_location;
    }

    // 4) field_venue entity coordinates.
    $from_venue = $this->resolveFromVenue($event);
    if ($from_venue['lat'] !== NULL && $from_venue['lng'] !== NULL) {
      return $from_venue;
    }

    // 5) null.
    return [
      'lat' => NULL,
      'lng' => NULL,
      'source' => 'none',
    ];
  }

  /**
   * Resolve from canonical Geofield point.
   */
  private function resolveFromEventGeo(NodeInterface $event): array {
    if (!$event->hasField('field_event_geo') || $event->get('field_event_geo')->isEmpty()) {
      return [
        'lat' => NULL,
        'lng' => NULL,
        'source' => 'none',
      ];
    }

    $item = $event->get('field_event_geo')->first();
    if (!$item) {
      return [
        'lat' => NULL,
        'lng' => NULL,
        'source' => 'none',
      ];
    }

    $value = NULL;
    $raw = $item->getValue();
    if (is_array($raw) && isset($raw['value']) && is_string($raw['value']) && $raw['value'] !== '') {
      $value = $raw['value'];
    }

    // Prefer parsing WKT 'POINT (lng lat)'.
    if (is_string($value)) {
      $parsed = $this->parsePointWkt($value);
      if ($parsed) {
        return [
          'lat' => $parsed['lat'],
          'lng' => $parsed['lng'],
          'source' => 'event_geo',
        ];
      }
    }

    // Fallback: try computed properties on the Geofield item (lat/lon).
    $lat = isset($item->lat) && is_numeric($item->lat) ? (float) $item->lat : NULL;
    $lng = isset($item->lon) && is_numeric($item->lon) ? (float) $item->lon : NULL;

    return [
      'lat' => $lat,
      'lng' => $lng,
      'source' => ($lat !== NULL && $lng !== NULL) ? 'event_geo' : 'none',
    ];
  }

  /**
   * Resolve from legacy event lat/lng fields.
   */
  private function resolveFromLegacyEventLatLng(NodeInterface $event): array {
    $lat = NULL;
    $lng = NULL;

    if ($event->hasField('field_event_lat') && !$event->get('field_event_lat')->isEmpty()) {
      $raw_lat = $event->get('field_event_lat')->value;
      if (is_numeric($raw_lat)) {
        $lat = (float) $raw_lat;
      }
    }

    if ($event->hasField('field_event_lng') && !$event->get('field_event_lng')->isEmpty()) {
      $raw_lng = $event->get('field_event_lng')->value;
      if (is_numeric($raw_lng)) {
        $lng = (float) $raw_lng;
      }
    }

    return [
      'lat' => $lat,
      'lng' => $lng,
      'source' => ($lat !== NULL && $lng !== NULL) ? 'legacy_event' : 'none',
    ];
  }

  /**
   * Resolve from address field / legacy location coordinate fields.
   */
  private function resolveFromLocation(NodeInterface $event): array {
    // Prefer legacy dedicated coordinate fields when present.
    $lat = NULL;
    $lng = NULL;

    if ($event->hasField('field_location_latitude') && !$event->get('field_location_latitude')->isEmpty()) {
      $raw_lat = $event->get('field_location_latitude')->value;
      if (is_numeric($raw_lat)) {
        $lat = (float) $raw_lat;
      }
    }

    if ($event->hasField('field_location_longitude') && !$event->get('field_location_longitude')->isEmpty()) {
      $raw_lng = $event->get('field_location_longitude')->value;
      if (is_numeric($raw_lng)) {
        $lng = (float) $raw_lng;
      }
    }

    if ($lat !== NULL && $lng !== NULL) {
      return [
        'lat' => $lat,
        'lng' => $lng,
        'source' => 'location',
      ];
    }

    // Otherwise attempt to derive from the address field value array.
    if (!$event->hasField('field_location') || $event->get('field_location')->isEmpty()) {
      return [
        'lat' => NULL,
        'lng' => NULL,
        'source' => 'none',
      ];
    }

    $item = $event->get('field_location')->first();
    if (!$item) {
      return [
        'lat' => NULL,
        'lng' => NULL,
        'source' => 'none',
      ];
    }

    $value = $item->getValue();
    if (is_array($value)) {
      if (isset($value['latitude']) && is_numeric($value['latitude'])) {
        $lat = (float) $value['latitude'];
      }
      if (isset($value['longitude']) && is_numeric($value['longitude'])) {
        $lng = (float) $value['longitude'];
      }
    }

    // Try typed-data properties if present.
    if ($lat === NULL && method_exists($item, 'hasProperty') && $item->hasProperty('latitude')) {
      $raw_lat = $item->get('latitude')->getValue();
      if (is_numeric($raw_lat)) {
        $lat = (float) $raw_lat;
      }
    }
    if ($lng === NULL && method_exists($item, 'hasProperty') && $item->hasProperty('longitude')) {
      $raw_lng = $item->get('longitude')->getValue();
      if (is_numeric($raw_lng)) {
        $lng = (float) $raw_lng;
      }
    }

    return [
      'lat' => $lat,
      'lng' => $lng,
      'source' => ($lat !== NULL && $lng !== NULL) ? 'location' : 'none',
    ];
  }

  /**
   * Resolve from referenced venue entity coordinates.
   */
  private function resolveFromVenue(NodeInterface $event): array {
    if (!$event->hasField('field_venue') || $event->get('field_venue')->isEmpty()) {
      return [
        'lat' => NULL,
        'lng' => NULL,
        'source' => 'none',
      ];
    }

    $venue = $event->get('field_venue')->entity;
    if (!$venue) {
      return [
        'lat' => NULL,
        'lng' => NULL,
        'source' => 'none',
      ];
    }

    $lat = NULL;
    $lng = NULL;

    if ($venue->hasField('field_latitude') && !$venue->get('field_latitude')->isEmpty()) {
      $raw_lat = $venue->get('field_latitude')->value;
      if (is_numeric($raw_lat)) {
        $lat = (float) $raw_lat;
      }
    }

    if ($venue->hasField('field_longitude') && !$venue->get('field_longitude')->isEmpty()) {
      $raw_lng = $venue->get('field_longitude')->value;
      if (is_numeric($raw_lng)) {
        $lng = (float) $raw_lng;
      }
    }

    return [
      'lat' => $lat,
      'lng' => $lng,
      'source' => ($lat !== NULL && $lng !== NULL) ? 'venue' : 'none',
    ];
  }

  /**
   * Parses a WKT POINT into lat/lng.
   *
   * Geofield WKT points are typically stored as: POINT (lng lat).
   *
   * @param string $wkt
   *   WKT string.
   *
   * @return array{lat: float, lng: float}|null
   *   Parsed coordinates or NULL if not parseable.
   */
  private function parsePointWkt(string $wkt): ?array {
    $wkt = trim($wkt);
    if ($wkt === '') {
      return NULL;
    }

    if (!preg_match('/^POINT\\s*\\(\\s*([+-]?(?:\\d+\\.?\\d*|\\.\\d+))\\s+([+-]?(?:\\d+\\.?\\d*|\\.\\d+))\\s*\\)$/i', $wkt, $matches)) {
      return NULL;
    }

    $lng = (float) $matches[1];
    $lat = (float) $matches[2];

    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
      return NULL;
    }

    return [
      'lat' => $lat,
      'lng' => $lng,
    ];
  }

}

