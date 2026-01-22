<?php

declare(strict_types=1);

namespace Drupal\myeventlane_api\Controller;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_api\Service\ApiAuthenticationService;
use Drupal\myeventlane_api\Service\ApiResponseFormatter;
use Drupal\myeventlane_api\Service\EventSerializer;
use Drupal\myeventlane_api\Service\RateLimiterService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for vendor Event API endpoints.
 */
final class VendorEventApiController extends VendorApiBaseController {

  /**
   * Constructs VendorEventApiController.
   */
  public function __construct(
    ApiAuthenticationService $authenticationService,
    ApiResponseFormatter $responseFormatter,
    RateLimiterService $rateLimiter,
    EntityTypeManagerInterface $entityTypeManager,
    private readonly EventSerializer $eventSerializer,
  ) {
    parent::__construct($authenticationService, $responseFormatter, $rateLimiter);
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_api.authentication'),
      $container->get('myeventlane_api.response_formatter'),
      $container->get('myeventlane_api.rate_limiter'),
      $container->get('entity_type.manager'),
      $container->get('myeventlane_api.event_serializer'),
    );
  }

  /**
   * Lists events for the authenticated vendor.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response.
   */
  public function listEvents(Request $request): JsonResponse {
    // Authenticate.
    $vendor = $this->authenticate($request);
    if (!$vendor) {
      return $this->authenticationError();
    }

    // Rate limiting.
    $rate_limit = $this->checkRateLimit($request, $vendor);
    if ($rate_limit) {
      return $this->rateLimitError($rate_limit);
    }

    // Get query parameters.
    $page = max(1, (int) $request->query->get('page', 1));
    $limit = min(100, max(1, (int) $request->query->get('limit', 20)));

    // Get vendor's events.
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('field_event_vendor', $vendor->id())
      ->sort('created', 'DESC')
      ->range(($page - 1) * $limit, $limit);

    $event_ids = $query->execute();
    if (empty($event_ids)) {
      return $this->responseFormatter->paginated([], $page, $limit, 0);
    }

    $events = $storage->loadMultiple($event_ids);

    // Serialize events (use public serializer for now - can be extended).
    $items = [];
    foreach ($events as $event) {
      try {
        $items[] = $this->eventSerializer->serializePublic($event);
      }
      catch (\Exception $e) {
        continue;
      }
    }

    // Get total count.
    $count_query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('field_event_vendor', $vendor->id());
    $total = $count_query->count()->execute();

    return $this->responseFormatter->paginated($items, $page, $limit, (int) $total);
  }

  /**
   * Gets a single event for the authenticated vendor.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response.
   */
  public function getEvent(Request $request, NodeInterface $node): JsonResponse {
    if ($node->bundle() !== 'event') {
      return $this->responseFormatter->error('INVALID_REQUEST', 'Invalid event ID.', 404);
    }

    // Authenticate.
    $vendor = $this->authenticate($request);
    if (!$vendor) {
      return $this->authenticationError();
    }

    // Verify event ownership.
    if (!$this->vendorOwnsEvent($vendor, $node)) {
      return $this->responseFormatter->error('FORBIDDEN', 'You do not have access to this event.', 403);
    }

    // Rate limiting.
    $rate_limit = $this->checkRateLimit($request, $vendor);
    if ($rate_limit) {
      return $this->rateLimitError($rate_limit);
    }

    try {
      $data = $this->eventSerializer->serializePublic($node);
      return $this->responseFormatter->success($data);
    }
    catch (\Exception $e) {
      return $this->responseFormatter->error('INTERNAL_ERROR', 'An error occurred processing the request.', 500);
    }
  }

}
