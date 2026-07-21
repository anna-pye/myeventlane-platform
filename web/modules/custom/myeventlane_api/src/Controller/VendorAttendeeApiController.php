<?php

declare(strict_types=1);

namespace Drupal\myeventlane_api\Controller;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;
use Drupal\myeventlane_api\Service\ApiAuthenticationService;
use Drupal\myeventlane_api\Service\ApiResponseFormatter;
use Drupal\myeventlane_api\Service\RateLimiterService;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_event_attendees\Service\AttendanceManagerInterface;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for vendor Attendee API endpoints.
 */
final class VendorAttendeeApiController extends VendorApiBaseController {

  /**
   * Constructs VendorAttendeeApiController.
   */
  public function __construct(
    ApiAuthenticationService $authenticationService,
    ApiResponseFormatter $responseFormatter,
    RateLimiterService $rateLimiter,
    EventVendorAccessCheckerInterface $eventVendorAccessChecker,
    EntityTypeManagerInterface $entityTypeManager,
    private readonly AttendanceManagerInterface $attendanceManager,
    private readonly mixed $melAttendeeCheckinManager,
  ) {
    parent::__construct(
      $authenticationService,
      $responseFormatter,
      $rateLimiter,
      $eventVendorAccessChecker,
      $entityTypeManager,
    );
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
      $container->get('myeventlane_event_attendees.manager'),
      $container->has('myeventlane_checkout_flow.attendee_checkin_manager')
        ? $container->get('myeventlane_checkout_flow.attendee_checkin_manager')
        : NULL,
    );
  }

  /**
   * Lists attendees for an event.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response.
   */
  public function listAttendees(Request $request, NodeInterface $node): JsonResponse {
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

    // Get query parameters.
    $checked_in = $request->query->get('checked_in');
    $q = $request->query->get('q', '');
    $page = max(1, (int) $request->query->get('page', 1));
    $limit = min(100, max(1, (int) $request->query->get('limit', 20)));

    // Get attendees.
    $attendees = $this->attendanceManager->getAttendeesForEvent((int) $node->id());

    // Filter by checked_in status.
    if ($checked_in !== NULL) {
      $checked_in_bool = filter_var($checked_in, FILTER_VALIDATE_BOOLEAN);
      $attendees = array_filter($attendees, function (EventAttendee $attendee) use ($checked_in_bool) {
        return $attendee->isCheckedIn() === $checked_in_bool;
      });
    }

    // Filter by search query.
    if (!empty($q)) {
      $attendees = array_filter($attendees, function (EventAttendee $attendee) use ($q) {
        $name = strtolower($attendee->getName() ?? '');
        $email = strtolower($attendee->getEmail() ?? '');
        $search = strtolower($q);
        return strpos($name, $search) !== FALSE || strpos($email, $search) !== FALSE;
      });
    }

    // Paginate.
    $total = count($attendees);
    $attendees = array_slice($attendees, ($page - 1) * $limit, $limit);

    // Serialize attendees.
    $items = [];
    foreach ($attendees as $attendee) {
      $items[] = $this->serializeAttendee($attendee);
    }

    return $this->responseFormatter->paginated($items, $page, $limit, $total);
  }

  /**
   * Toggles check-in status for an attendee.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   * @param int $attendee_id
   *   The attendee ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response.
   */
  public function checkIn(Request $request, NodeInterface $node, int $attendee_id): JsonResponse {
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

    // Get request body.
    $content = $request->getContent();
    $data = json_decode($content, TRUE);
    $checked_in = $data['checked_in'] ?? NULL;

    if ($checked_in === NULL || !is_bool($checked_in)) {
      return $this->responseFormatter->error('INVALID_REQUEST', 'Missing or invalid checked_in parameter.', 400);
    }

    // Load attendee.
    $storage = $this->entityTypeManager->getStorage('event_attendee');
    $attendee = $storage->load($attendee_id);

    if (!$attendee || !$attendee instanceof EventAttendee) {
      return $this->responseFormatter->error('NOT_FOUND', 'Attendee not found.', 404);
    }

    // Verify attendee belongs to this event.
    if ($attendee->getEventId() !== (int) $node->id()) {
      return $this->responseFormatter->error('FORBIDDEN', 'Attendee does not belong to this event.', 403);
    }

    $actor = $this->entityTypeManager->getStorage('user')->load((int) $vendor->getOwnerId());
    if (!$actor instanceof UserInterface) {
      return $this->responseFormatter->error('SERVER_ERROR', 'Could not resolve vendor actor for check-in.', 500);
    }

    if (is_object($this->melAttendeeCheckinManager) && method_exists($this->melAttendeeCheckinManager, 'markManualOverride')) {
      /** @var array<string, mixed> $result */
      $result = $this->melAttendeeCheckinManager->markManualOverride($attendee, $actor, $checked_in);
      $fail = $this->checkInManagerFailureResponse($result);
      if ($fail !== NULL) {
        return $fail;
      }
    }
    else {
      if ($checked_in) {
        $attendee->checkIn();
      }
      else {
        $attendee->setStatus(EventAttendee::STATUS_CONFIRMED);
        $attendee->set('checked_in_at', NULL);
      }
      $attendee->save();
    }

    $storage->resetCache([$attendee->id()]);
    $attendee = $storage->load($attendee_id);
    if (!$attendee instanceof EventAttendee) {
      return $this->responseFormatter->error('SERVER_ERROR', 'Attendee reload failed.', 500);
    }

    // Fail closed if persisted state diverges from the requested toggle (covers noop races).
    if ($attendee->isCheckedIn() !== $checked_in) {
      return $this->responseFormatter->error(
        'CONFLICT',
        'Check-in request could not be applied; attendee state unchanged.',
        409,
      );
    }

    return $this->responseFormatter->success($this->serializeAttendee($attendee));
  }

  /**
   * Maps a failed MelAttendeeCheckinManager result to a JSON API error response.
   *
   * @param array<string, mixed> $result
   *   Outcome shape from {@see \Drupal\myeventlane_checkout_flow\Service\MelAttendeeCheckinManager}.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   JsonResponse when the operation definitively failed, or NULL when success flag is TRUE.
   */
  private function checkInManagerFailureResponse(array $result): ?JsonResponse {
    if ($result['success'] ?? FALSE) {
      return NULL;
    }
    $reason = (string) ($result['reason'] ?? '');
    if ($reason === 'forbidden') {
      return $this->responseFormatter->error('FORBIDDEN', 'Check-in operation was denied for this attendee or event.', 403);
    }
    if (str_starts_with($reason, 'state_not_eligible')) {
      return $this->responseFormatter->error('INVALID_STATE', 'Attendee is not eligible for this check-in update.', 422);
    }
    if ($reason === 'no_event') {
      return $this->responseFormatter->error('INVALID_STATE', 'Attendee record cannot be resolved to an event.', 422);
    }
    if ($reason === 'transition_failed') {
      return $this->responseFormatter->error('SERVER_ERROR', 'Persisting check-in failed.', 500);
    }
    return $this->responseFormatter->error('OPERATION_FAILED', 'Could not apply check-in changes.', 400);
  }

  /**
   * Serializes an attendee for API response.
   *
   * @param \Drupal\myeventlane_event_attendees\Entity\EventAttendee $attendee
   *   The attendee entity.
   *
   * @return array
   *   Serialized attendee data.
   */
  private function serializeAttendee(EventAttendee $attendee): array {
    $checked_in_at = NULL;
    if ($attendee->hasField('checked_in_at') && !$attendee->get('checked_in_at')->isEmpty()) {
      $checked_in_at = date('c', (int) $attendee->get('checked_in_at')->value);
    }

    return [
      'id' => (int) $attendee->id(),
      'name' => $attendee->getName() ?? '',
      'email' => $attendee->getEmail() ?? NULL,
      'ticket_code' => $attendee->getTicketCode(),
      'ticket_label' => $attendee->isFromTicket() ? 'Ticket' : 'RSVP',
      'checked_in' => $attendee->isCheckedIn(),
      'checked_in_at' => $checked_in_at,
      'source' => $attendee->getSource(),
    ];
  }

}
