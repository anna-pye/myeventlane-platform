<?php

declare(strict_types=1);

namespace Drupal\myeventlane_location\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for MyEventLane location utilities.
 */
final class MyeventlaneLocationCommands extends DrushCommands {

  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct();
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Backfill latitude/longitude for existing Event nodes.
   *
   * @command myeventlane:location-backfill
   * @aliases mel-loc-backfill
   *
   * @usage drush myeventlane:location-backfill
   */
  public function backfill(): void {
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

      $node->set('field_location_latitude', (float) $coords['lat']);
      $node->set('field_location_longitude', (float) $coords['lng']);
      $node->save();

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
