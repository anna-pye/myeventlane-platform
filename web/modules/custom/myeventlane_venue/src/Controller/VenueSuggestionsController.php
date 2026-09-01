<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_venue\Service\VenueSuggestionService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides read-only organiser venue suggestions.
 */
final class VenueSuggestionsController extends ControllerBase {

  /**
   * Constructs the venue suggestions controller.
   */
  public function __construct(
    private readonly VenueSuggestionService $suggestionService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('myeventlane_venue.suggestion_service'));
  }

  /**
   * Returns accessible existing venues and reviewed public suggestions.
   */
  public function suggest(Request $request): JsonResponse {
    $name = mb_substr(trim((string) $request->query->get('name', '')), 0, 255);
    $address = mb_substr(trim((string) $request->query->get('address', '')), 0, 500);
    $latitude = $this->coordinate($request->query->get('lat'), -90.0, 90.0);
    $longitude = $this->coordinate($request->query->get('lng'), -180.0, 180.0);

    $payload = ['existing' => [], 'overture' => [], 'attribution' => ''];
    if (mb_strlen($name) >= 2 || ($latitude !== NULL && $longitude !== NULL)) {
      $payload = $this->suggestionService->suggest($name, $address, $latitude, $longitude);
      $payload['attribution'] = $payload['overture'] === [] ? '' : 'Data from Overture Maps Foundation';
    }

    $response = new JsonResponse($payload);
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

  /**
   * Returns a valid coordinate, or NULL for invalid input.
   */
  private function coordinate(mixed $value, float $minimum, float $maximum): ?float {
    if (!is_numeric($value)) {
      return NULL;
    }
    $coordinate = (float) $value;
    return $coordinate >= $minimum && $coordinate <= $maximum ? $coordinate : NULL;
  }

}
