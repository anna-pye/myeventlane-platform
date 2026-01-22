<?php

declare(strict_types=1);

namespace Drupal\myeventlane_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_api\Service\ApiResponseFormatter;
use Drupal\myeventlane_api\Service\EventSerializer;
use Drupal\myeventlane_api\Service\RateLimiterService;
use Drupal\myeventlane_event_state\Service\EventStateResolverInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for public Event API endpoints.
 */
final class PublicEventApiController extends ControllerBase {

  /**
   * Constructs PublicEventApiController.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    private readonly ApiResponseFormatter $responseFormatter,
    private readonly EventSerializer $eventSerializer,
    private readonly RateLimiterService $rateLimiter,
    private readonly EventStateResolverInterface $eventStateResolver,
  ) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_api.response_formatter'),
      $container->get('myeventlane_api.event_serializer'),
      $container->get('myeventlane_api.rate_limiter'),
      $container->get('myeventlane_event_state.resolver'),
    );
  }

  /**
   * Lists public events.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response.
   */
  public function listEvents(Request $request): JsonResponse {
    // Rate limiting (per IP).
    $client_ip = $this->rateLimiter->getClientIp($request);
    $limit_check = $this->rateLimiter->checkLimit(
      $request,
      $client_ip,
      RateLimiterService::DEFAULT_PUBLIC_LIMIT,
      RateLimiterService::PERIOD_MINUTE
    );

    if (!$limit_check['allowed']) {
      return $this->responseFormatter->error(
        'RATE_LIMIT_EXCEEDED',
        'Rate limit exceeded. Please try again later.',
        429
      )->headers->set('X-RateLimit-Remaining', (string) $limit_check['remaining'])
        ->headers->set('X-RateLimit-Reset', (string) $limit_check['reset']);
    }

    // Get query parameters.
    $q = $request->query->get('q', '');
    $category = $request->query->get('category');
    $from = $request->query->get('from');
    $to = $request->query->get('to');
    $city = $request->query->get('city');
    $online = $request->query->get('online');
    $page = max(1, (int) $request->query->get('page', 1));
    $limit = min(100, max(1, (int) $request->query->get('limit', 20)));

    // Build query.
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('status', NodeInterface::PUBLISHED)
      ->range(($page - 1) * $limit, $limit)
      ->sort('field_event_start', 'ASC');

    // Search query.
    if (!empty($q)) {
      $or_group = $query->orConditionGroup()
        ->condition('title', $q, 'CONTAINS')
        ->condition('body', $q, 'CONTAINS');
      $query->condition($or_group);
    }

    // Category filter.
    if (!empty($category)) {
      // Support both term ID and term name/slug lookup.
      if (is_numeric($category)) {
        $query->condition('field_category', (int) $category);
      }
      else {
        // Look up term by name (simplified - in production might use taxonomy term storage).
        $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
        $terms = $term_storage->loadByProperties(['name' => $category]);
        if (!empty($terms)) {
          $term_ids = array_keys($terms);
          $query->condition('field_category', $term_ids, 'IN');
        }
        else {
          // No matching category - return empty results.
          $query->condition('nid', 0);
        }
      }
    }

    // Date range filter.
    if (!empty($from)) {
      $from_timestamp = strtotime($from);
      if ($from_timestamp !== FALSE) {
        $query->condition('field_event_start', date('Y-m-d\TH:i:s', $from_timestamp), '>=');
      }
    }
    if (!empty($to)) {
      $to_timestamp = strtotime($to);
      if ($to_timestamp !== FALSE) {
        $query->condition('field_event_start', date('Y-m-d\TH:i:s', $to_timestamp), '<=');
      }
    }

    // City/location filter (simplified - would need address field query).
    // Online filter (would need field to indicate online events).
    $event_ids = $query->execute();
    if (empty($event_ids)) {
      return $this->responseFormatter->paginated([], $page, $limit, 0);
    }

    $events = $storage->loadMultiple($event_ids);

    // Filter by visibility and state.
    $public_events = [];
    foreach ($events as $event) {
      // Skip cancelled events unless explicitly requested.
      $state = $this->eventStateResolver->resolveState($event);
      if ($state === 'cancelled') {
        continue;
      }

      // @todo Add visibility check (public/unlisted/private) if field exists.
      // For now, assume all published events are public.
      $public_events[] = $event;
    }

    // Serialize events.
    $items = [];
    foreach ($public_events as $event) {
      try {
        $items[] = $this->eventSerializer->serializePublic($event);
      }
      catch (\Exception $e) {
        // Skip events that can't be serialized.
        continue;
      }
    }

    // Get total count (simplified - would ideally count with same filters).
    $count_query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('status', NodeInterface::PUBLISHED);
    $total = $count_query->count()->execute();

    $response = $this->responseFormatter->paginated($items, $page, $limit, (int) $total);
    $response->headers->set('X-RateLimit-Remaining', (string) $limit_check['remaining']);
    $response->headers->set('X-RateLimit-Reset', (string) $limit_check['reset']);

    return $response;
  }

  /**
   * Gets a single event by ID.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param \Drupal\node\NodeInterface $node
   *   The event node (from route parameter).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response.
   */
  public function getEvent(Request $request, NodeInterface $node): JsonResponse {
    if ($node->bundle() !== 'event') {
      return $this->responseFormatter->error('INVALID_REQUEST', 'Invalid event ID.', 404);
    }

    // Rate limiting.
    $client_ip = $this->rateLimiter->getClientIp($request);
    $limit_check = $this->rateLimiter->checkLimit(
      $request,
      $client_ip,
      RateLimiterService::DEFAULT_PUBLIC_LIMIT,
      RateLimiterService::PERIOD_MINUTE
    );

    if (!$limit_check['allowed']) {
      return $this->responseFormatter->error(
        'RATE_LIMIT_EXCEEDED',
        'Rate limit exceeded. Please try again later.',
        429
      );
    }

    // Check if event is published.
    if (!$node->isPublished()) {
      return $this->responseFormatter->error('NOT_FOUND', 'Event not found.', 404);
    }

    // Check state - skip cancelled unless explicitly requested.
    $state = $this->eventStateResolver->resolveState($node);
    if ($state === 'cancelled' && !$request->query->getBoolean('include_cancelled')) {
      return $this->responseFormatter->error('NOT_FOUND', 'Event not found.', 404);
    }

    // @todo Add visibility check (public/unlisted/private).
    try {
      $data = $this->eventSerializer->serializePublic($node);
      $response = $this->responseFormatter->success($data);
      $response->headers->set('X-RateLimit-Remaining', (string) $limit_check['remaining']);
      $response->headers->set('X-RateLimit-Reset', (string) $limit_check['reset']);
      return $response;
    }
    catch (\Exception $e) {
      return $this->responseFormatter->error('INTERNAL_ERROR', 'An error occurred processing the request.', 500);
    }
  }

}
