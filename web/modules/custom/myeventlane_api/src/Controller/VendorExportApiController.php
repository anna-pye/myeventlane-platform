<?php

declare(strict_types=1);

namespace Drupal\myeventlane_api\Controller;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_api\Service\ApiAuthenticationService;
use Drupal\myeventlane_api\Service\ApiResponseFormatter;
use Drupal\myeventlane_api\Service\RateLimiterService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for vendor Export API endpoints.
 */
final class VendorExportApiController extends VendorApiBaseController {

  /**
   * Constructs VendorExportApiController.
   */
  public function __construct(
    ApiAuthenticationService $authenticationService,
    ApiResponseFormatter $responseFormatter,
    RateLimiterService $rateLimiter,
    EntityTypeManagerInterface $entityTypeManager,
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
    );
  }

  /**
   * Requests a CSV export for an event.
   *
   * Returns a job ID; completion will be delivered via webhook.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with job ID.
   */
  public function requestCsvExport(Request $request, NodeInterface $node): JsonResponse {
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

    // Generate a job ID (in a real implementation, this would queue a job).
    // For now, return a placeholder job ID.
    // @todo Integrate with queue system to actually generate export.
    $job_id = 'export_' . $node->id() . '_' . time();

    return $this->responseFormatter->success([
      'job_id' => $job_id,
      'status' => 'queued',
      'event_id' => (int) $node->id(),
      'message' => 'Export job queued. Completion will be delivered via webhook.',
    ], 202);
  }

}
