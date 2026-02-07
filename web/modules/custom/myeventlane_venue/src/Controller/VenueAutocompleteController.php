<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_venue\Entity\Venue;
use Drupal\myeventlane_venue\Service\VenueAccessResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for venue autocomplete.
 */
class VenueAutocompleteController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected VenueAccessResolver $accessResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_venue.access_resolver'),
    );
  }

  /**
   * Autocomplete callback for venues.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with matching venues.
   */
  public function autocomplete(Request $request): JsonResponse {
    $query = $request->query->get('q', '');
    $results = [];

    if (strlen($query) < 2) {
      return new JsonResponse($results);
    }

    $venues = $this->accessResolver->getAccessibleVenues($this->currentUser());
    $query_lower = strtolower($query);

    foreach ($venues as $venue) {
      if (!$venue instanceof Venue) {
        continue;
      }

      $name = $venue->getName();
      if (str_contains(strtolower($name), $query_lower)) {
        $results[] = [
          'value' => $venue->id() . ': ' . $name,
          'label' => $name,
          'venue_id' => $venue->id(),
          'visibility' => $venue->getVisibility(),
        ];
      }

      // Limit results.
      if (count($results) >= 20) {
        break;
      }
    }

    return new JsonResponse($results);
  }

}
