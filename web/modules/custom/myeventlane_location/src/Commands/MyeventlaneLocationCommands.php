<?php

declare(strict_types=1);

namespace Drupal\myeventlane_location\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
use Drupal\node\NodeInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for MyEventLane location utilities.
 */
final class MyeventlaneLocationCommands extends DrushCommands {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ?EventStudioSaveService $eventStudioSave = NULL,
  ) {
    parent::__construct();
  }

  /**
   * Backfill latitude/longitude for existing Event nodes.
   *
   * Uses EventStudioSaveService as the only writer for event coordinates.
   *
   * @command myeventlane:location-backfill
   * @aliases mel-loc-backfill
   *
   * @usage drush myeventlane:location-backfill
   */
  public function backfill(): void {
    if (!$this->eventStudioSave instanceof EventStudioSaveService) {
      $this->logger()->error('Enable myeventlane_event_studio so event coordinates can be written via EventStudioSaveService.');
      return;
    }

    $config = \Drupal::config('myeventlane_location.settings');
    $apiKey = $config->get('google_maps_api_key');

    if (empty($apiKey)) {
      $this->logger()->error('Google Maps API key not found in myeventlane_location.settings.');
      return;
    }

    $storage = $this->entityTypeManager->getStorage('node');

    $nids = $storage->getQuery()
      ->condition('type', 'event')
      ->exists('field_location')
      ->notExists('field_location_latitude')
      ->accessCheck(FALSE)
      ->execute();

    if (empty($nids)) {
      $this->logger()->notice('No events found requiring backfill.');
      return;
    }

    $nodes = $storage->loadMultiple($nids);
    $count = 0;

    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }

      if (
        !$node->hasField('field_location_latitude') ||
        !$node->hasField('field_location_longitude')
      ) {
        continue;
      }

      if (
        !$node->get('field_location_latitude')->isEmpty() ||
        !$node->get('field_location_longitude')->isEmpty()
      ) {
        continue;
      }

      $locationItem = $node->get('field_location')->first();
      if (!$locationItem) {
        continue;
      }

      $location = $locationItem->getValue();

      $parts = array_filter([
        $location['address_line1'] ?? '',
        $location['locality'] ?? '',
        $location['administrative_area'] ?? '',
        $location['postal_code'] ?? '',
        $location['country_code'] ?? '',
      ]);

      if (empty($parts)) {
        continue;
      }

      $query = urlencode(implode(', ', $parts));
      $url = sprintf(
        'https://maps.googleapis.com/maps/api/geocode/json?address=%s&key=%s',
        $query,
        $apiKey
      );

      $response = @file_get_contents($url);
      if (!$response) {
        $this->logger()->warning('Failed to geocode node @nid.', ['@nid' => $node->id()]);
        continue;
      }

      $data = json_decode($response, TRUE);
      if (empty($data['results'][0]['geometry']['location'])) {
        $this->logger()->warning('No geocode results for node @nid.', ['@nid' => $node->id()]);
        continue;
      }

      $coords = $data['results'][0]['geometry']['location'];
      $account = $node->getOwner();
      $has_venue = $node->hasField('field_venue') && !$node->get('field_venue')->isEmpty();
      $venue_id = NULL;
      if ($has_venue) {
        $ref = $node->get('field_venue')->first();
        $venue_id = $ref ? (int) $ref->target_id : NULL;
      }

      $payload = [
        'title' => $node->label(),
        'summary' => $node->hasField('field_event_summary') && !$node->get('field_event_summary')->isEmpty()
          ? (string) $node->get('field_event_summary')->value
          : '',
        'venue_choice' => $has_venue ? 'saved' : 'one_off',
        'venue_id' => $venue_id,
        'new_venue_name' => '',
        'field_location' => $has_venue ? [] : [$location],
        'field_event_start' => $node->hasField('field_event_start') && !$node->get('field_event_start')->isEmpty()
          ? (string) $node->get('field_event_start')->value
          : NULL,
        'field_event_end' => $node->hasField('field_event_end') && !$node->get('field_event_end')->isEmpty()
          ? (string) $node->get('field_event_end')->value
          : NULL,
        'field_event_type' => $node->hasField('field_event_type') ? (string) $node->get('field_event_type')->value : 'rsvp',
        'status' => $node->isPublished(),
        'field_location_latitude' => $coords['lat'],
        'field_location_longitude' => $coords['lng'],
      ];

      $result = $this->eventStudioSave->save($payload, $node, $account, FALSE);
      if ($result['errors'] !== []) {
        $this->logger()->error('Studio save failed for node @nid: @e', [
          '@nid' => $node->id(),
          '@e' => implode('; ', $result['errors']),
        ]);
        continue;
      }

      $this->logger()->notice(
        'Backfilled node @nid → @lat,@lng',
        [
          '@nid' => $node->id(),
          '@lat' => $coords['lat'],
          '@lng' => $coords['lng'],
        ]
      );

      $count++;
    }

    $this->logger()->success("Backfill complete. Updated {$count} events.");
  }

}
