<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_venue\Entity\Venue;
use Drupal\myeventlane_venue\Entity\VenueLocation;
use Drupal\myeventlane_venue\Service\VenueManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for venue locations AJAX.
 */
class VenueLocationsController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected VenueManager $venueManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_venue.manager'),
    );
  }

  /**
   * Gets locations for a venue (JSON).
   *
   * @param \Drupal\myeventlane_venue\Entity\Venue $myeventlane_venue
   *   The venue entity.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with locations.
   */
  public function getLocations(Venue $myeventlane_venue): JsonResponse {
    $locations = $this->venueManager->getLocations($myeventlane_venue);
    $results = [];

    foreach ($locations as $location) {
      // Get full address components for the JS to populate form fields.
      $addressComponents = $this->getAddressComponents($location);

      $results[] = [
        'id' => $location->id(),
        'title' => $location->getTitle(),
        'address' => $location->getAddressText(),
        'address_line1' => $addressComponents['address_line1'],
        'address_line2' => $addressComponents['address_line2'],
        'locality' => $addressComponents['locality'],
        'state' => $addressComponents['administrative_area'],
        'postcode' => $addressComponents['postal_code'],
        'country_code' => $addressComponents['country_code'],
        'latitude' => $location->getLatitude(),
        'longitude' => $location->getLongitude(),
        'is_primary' => $location->isPrimary(),
      ];
    }

    return new JsonResponse([
      'venue_id' => $myeventlane_venue->id(),
      'venue_name' => $myeventlane_venue->getName(),
      'locations' => $results,
    ]);
  }

  /**
   * Extracts address components from a venue location.
   *
   * @param \Drupal\myeventlane_venue\Entity\VenueLocation $location
   *   The venue location entity.
   *
   * @return array
   *   Address components array.
   */
  protected function getAddressComponents(VenueLocation $location): array {
    $defaults = [
      'country_code' => 'AU',
      'address_line1' => '',
      'address_line2' => '',
      'locality' => '',
      'administrative_area' => '',
      'postal_code' => '',
    ];

    // VenueLocation uses address_text (simple string), not a composite address field.
    // Try to parse address_text if it exists.
    if ($location->hasField('address_text') && !$location->get('address_text')->isEmpty()) {
      $addressText = $location->get('address_text')->value ?? '';
      if ($addressText) {
        // Parse the address text into components.
        // Format is typically: "Street, Suburb STATE Postcode, Australia"
        return $this->parseAddressText($addressText);
      }
    }

    // Fallback: check for composite address field (if it exists in future).
    if ($location->hasField('address') && !$location->get('address')->isEmpty()) {
      $address = $location->get('address')->first();
      if ($address) {
        return [
          'country_code' => $address->get('country_code')->getValue() ?? 'AU',
          'address_line1' => $address->get('address_line1')->getValue() ?? '',
          'address_line2' => $address->get('address_line2')->getValue() ?? '',
          'locality' => $address->get('locality')->getValue() ?? '',
          'administrative_area' => $address->get('administrative_area')->getValue() ?? '',
          'postal_code' => $address->get('postal_code')->getValue() ?? '',
        ];
      }
    }

    return $defaults;
  }

  /**
   * Parse address text into components.
   *
   * @param string $addressText
   *   The full address text.
   *
   * @return array
   *   Parsed address components.
   */
  protected function parseAddressText(string $addressText): array {
    $result = [
      'country_code' => 'AU',
      'address_line1' => '',
      'address_line2' => '',
      'locality' => '',
      'administrative_area' => '',
      'postal_code' => '',
    ];

    // Simple parsing: split by comma.
    $parts = array_map('trim', explode(',', $addressText));

    if (count($parts) >= 1) {
      $result['address_line1'] = $parts[0];
    }

    // Try to extract suburb, state, postcode from remaining parts.
    // Australian format: "Suburb STATE Postcode"
    if (count($parts) >= 2) {
      $lastPart = end($parts);
      // Remove "Australia" if present.
      $lastPart = preg_replace('/\s*Australia\s*$/i', '', $lastPart);
      $parts[count($parts) - 1] = trim($lastPart);
    }

    if (count($parts) >= 2) {
      // Second part might be "Suburb STATE Postcode" or just "Suburb".
      $suburbLine = $parts[1];
      
      // Try to match "Suburb STATE Postcode" pattern.
      if (preg_match('/^(.+?)\s+(NSW|VIC|QLD|SA|WA|TAS|ACT|NT)\s+(\d{4})$/i', $suburbLine, $matches)) {
        $result['locality'] = trim($matches[1]);
        $result['administrative_area'] = strtoupper($matches[2]);
        $result['postal_code'] = $matches[3];
      }
      else {
        // Just use as locality.
        $result['locality'] = $suburbLine;
      }
    }

    return $result;
  }

}
