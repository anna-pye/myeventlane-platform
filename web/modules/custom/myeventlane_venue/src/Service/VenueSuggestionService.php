<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Service;

use Drupal\myeventlane_venue\Entity\Venue;

/**
 * Combines accessible MyEventLane duplicates and Overture suggestions.
 */
final class VenueSuggestionService {

  public function __construct(
    private readonly VenueAccessResolver $accessResolver,
    private readonly VenueManager $venueManager,
    private readonly OverturePlaceRepository $overtureRepository,
    private readonly VenueCandidateScorer $scorer,
  ) {}

  /**
   * Finds venue matches the current organiser is allowed to use.
   *
   * @return array{existing: array<int, array<string, mixed>>, overture: array<int, array<string, mixed>>}
   *   Existing MyEventLane venues and reviewed Overture candidates.
   */
  public function suggest(
    string $name,
    string $address = '',
    ?float $latitude = NULL,
    ?float $longitude = NULL,
    ?int $excludeVenueId = NULL,
  ): array {
    $existing = [];
    foreach ($this->accessResolver->getAccessibleVenues() as $venue) {
      if (!$venue instanceof Venue) {
        continue;
      }
      if ($excludeVenueId !== NULL && (int) $venue->id() === $excludeVenueId) {
        continue;
      }
      $location = $this->venueManager->getPrimaryLocation($venue);
      $candidate = [
        'venue_id' => (int) $venue->id(),
        'name' => $venue->getName(),
        'address' => $location?->getAddressText() ?? '',
        'latitude' => $location?->getLatitude(),
        'longitude' => $location?->getLongitude(),
        'visibility' => $venue->getVisibility(),
      ];
      $candidate['score'] = $this->scorer->score($name, $address, $latitude, $longitude, $candidate);
      if ($candidate['score'] >= 55) {
        $existing[] = $candidate;
      }
    }
    usort($existing, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

    return [
      'existing' => array_slice($existing, 0, 5),
      'overture' => $this->overtureRepository->find($name, $address, $latitude, $longitude, 5),
    ];
  }

}
