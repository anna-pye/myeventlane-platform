<?php

declare(strict_types=1);

namespace Drupal\myeventlane_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_api\Service\ApiAuthenticationService;
use Drupal\myeventlane_api\Service\ApiResponseFormatter;
use Drupal\myeventlane_api\Service\RateLimiterService;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base controller for vendor API endpoints.
 *
 * API keys are vendor-scoped (not user-specific). After authentication the
 * acting Drupal account is the vendor entity owner. Event access requires:
 * - authenticated vendor identity
 * - when the event is linked via field_event_vendor, that link must match the
 *   authenticated vendor (vendor-scoped bind)
 * - workspace parity for the vendor owner account.
 *
 * Team members are not separately representable via vendor-wide API keys.
 */
abstract class VendorApiBaseController extends ControllerBase {

  /**
   * Constructs VendorApiBaseController.
   */
  public function __construct(
    protected readonly ApiAuthenticationService $authenticationService,
    protected readonly ApiResponseFormatter $responseFormatter,
    protected readonly RateLimiterService $rateLimiter,
    protected readonly EventVendorAccessCheckerInterface $eventVendorAccessChecker,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
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
      $container->get('myeventlane_vendor.event_access_checker'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Authenticates the request and returns the vendor.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor|null
   *   The authenticated vendor, or NULL if authentication failed.
   */
  protected function authenticate(Request $request): ?Vendor {
    return $this->authenticationService->authenticate($request);
  }

  /**
   * Checks rate limits for vendor API.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The vendor entity.
   *
   * @return array|null
   *   Rate limit check result, or NULL if allowed.
   */
  protected function checkRateLimit(Request $request, Vendor $vendor): ?array {
    $identifier = 'vendor:' . $vendor->id();
    $limit_check = $this->rateLimiter->checkLimit(
      $request,
      $identifier,
      RateLimiterService::DEFAULT_VENDOR_LIMIT,
      RateLimiterService::PERIOD_HOUR
    );

    if (!$limit_check['allowed']) {
      return $limit_check;
    }

    return NULL;
  }

  /**
   * Returns an authentication error response.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON error response.
   */
  protected function authenticationError(): JsonResponse {
    return $this->responseFormatter->error(
      'UNAUTHORIZED',
      'Authentication required. Provide a valid API key in the Authorization header.',
      401
    );
  }

  /**
   * Returns a rate limit error response.
   *
   * @param array $limit_check
   *   Rate limit check result.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON error response.
   */
  protected function rateLimitError(array $limit_check): JsonResponse {
    $response = $this->responseFormatter->error(
      'RATE_LIMIT_EXCEEDED',
      'Rate limit exceeded. Please try again later.',
      429
    );
    $response->headers->set('X-RateLimit-Remaining', (string) $limit_check['remaining']);
    $response->headers->set('X-RateLimit-Reset', (string) $limit_check['reset']);
    return $response;
  }

  /**
   * Checks if an authenticated vendor may access an event.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The vendor entity resolved from the API key.
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if the vendor may access the event.
   */
  protected function vendorOwnsEvent(Vendor $vendor, NodeInterface $event): bool {
    if ($event->bundle() !== 'event') {
      return FALSE;
    }

    // Vendor-scoped key: when an event vendor link exists it must match.
    if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      $event_vendor = $event->get('field_event_vendor')->entity;
      if (!$event_vendor || (int) $event_vendor->id() !== (int) $vendor->id()) {
        return FALSE;
      }
    }

    $owner = $this->resolveVendorActingAccount($vendor);
    if (!$owner instanceof AccountInterface) {
      return FALSE;
    }

    return $this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $owner);
  }

  /**
   * Resolves the Drupal account the vendor-wide API key acts as (vendor owner).
   */
  protected function resolveVendorActingAccount(Vendor $vendor): ?AccountInterface {
    $owner_id = (int) $vendor->getOwnerId();
    if ($owner_id <= 0) {
      return NULL;
    }
    $owner = $this->entityTypeManager()->getStorage('user')->load($owner_id);
    return $owner instanceof AccountInterface ? $owner : NULL;
  }

}
