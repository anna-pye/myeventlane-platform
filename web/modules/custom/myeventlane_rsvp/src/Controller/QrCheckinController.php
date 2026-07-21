<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_checkout_flow\Service\MelAttendeeOperationsAccessInterface;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_event_attendees\Service\AttendanceManager;
use Drupal\myeventlane_rsvp\Entity\RsvpSubmission;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * QR code scan and validation for RSVP check-in.
 */
final class QrCheckinController extends ControllerBase {

  /**
   * Flood limit: requests per identifier per window.
   */
  private const FLOOD_LIMIT = 30;

  /**
   * Flood window in seconds.
   */
  private const FLOOD_WINDOW = 60;

  /**
   * Constructs QrCheckinController.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $em,
    private readonly ConfigFactoryInterface $config,
    private readonly FloodInterface $flood,
    private readonly AccountProxyInterface $account,
    private readonly ?AttendanceManager $attendanceManager = NULL,
    private readonly mixed $melAttendeeCheckinManager = NULL,
    private readonly ?MelAttendeeOperationsAccessInterface $attendeeOperationsAccess = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $c): self {
    return new self(
      $c->get('entity_type.manager'),
      $c->get('config.factory'),
      $c->get('flood'),
      $c->get('current_user'),
      $c->has('myeventlane_event_attendees.manager')
        ? $c->get('myeventlane_event_attendees.manager')
        : NULL,
      $c->has('myeventlane_checkout_flow.attendee_checkin_manager')
        ? $c->get('myeventlane_checkout_flow.attendee_checkin_manager')
        : NULL,
      $c->has('myeventlane_checkout_flow.attendee_operations_access')
        ? $c->get('myeventlane_checkout_flow.attendee_operations_access')
        : NULL,
    );
  }

  /**
   * Renders the QR scan page for an event.
   */
  public function scanPage($event): array {
    return [
      '#theme' => 'myeventlane_rsvp_qr_scan',
      '#event_id' => $event,
      '#attached' => [
        'library' => [
          'myeventlane_rsvp/qrscan',
        ],
      ],
    ];
  }

  /**
   * Validates QR code and marks RSVP as checked in.
   *
   * Requires manage own event rsvps. Verifies user can access the event.
   * Rate-limited by IP. Response is uncacheable.
   */
  public function validate(Request $req): JsonResponse {
    $identifier = 'myeventlane_rsvp.qr_validate:' . $req->getClientIp();
    if (!$this->flood->isAllowed('myeventlane_rsvp.qr_validate', self::FLOOD_LIMIT, self::FLOOD_WINDOW, $identifier)) {
      $this->getLogger('myeventlane_rsvp')->notice('QR validate flood limit exceeded');
      throw new AccessDeniedHttpException('Too many requests.');
    }
    $this->flood->register('myeventlane_rsvp.qr_validate', self::FLOOD_WINDOW, $identifier);

    $data = json_decode($req->getContent(), TRUE);
    $code = trim((string) ($data['code'] ?? ''));

    if ($code === '') {
      return $this->jsonResponse(['status' => 'invalid', 'message' => 'Bad QR format']);
    }

    if (preg_match('/^mel:rsvp:(\d+):([a-f0-9]{64})$/', $code, $m)) {
      return $this->validateLegacyRsvpQr((int) $m[1], $m[2]);
    }

    $codeUpper = strtoupper($code);
    if (preg_match('/^MEL-\d+-\d+-\d+-[A-Z0-9]{6}$/', $codeUpper)) {
      return $this->validateCommerceRsvpTicketQr($codeUpper);
    }

    return $this->jsonResponse(['status' => 'invalid', 'message' => 'Bad QR format']);
  }

  /**
   * Legacy rsvp_submission QR (mel:rsvp:…).
   */
  private function validateLegacyRsvpQr(int $rsvp_id, string $hash): JsonResponse {
    $rsvp = $this->em->getStorage('rsvp_submission')->load($rsvp_id);
    if (!$rsvp instanceof RsvpSubmission) {
      return $this->jsonResponse(['status' => 'invalid', 'message' => 'RSVP not found']);
    }

    $event = $rsvp->getEvent();
    if (!$event) {
      return $this->jsonResponse(['status' => 'invalid', 'message' => 'Event not found']);
    }

    if (!$this->canManageEvent($event)) {
      throw new AccessDeniedHttpException('You do not have access to check in for this event.');
    }

    $event_id = $rsvp->getEventId();
    $secret = $this->config->get('myeventlane_rsvp.settings')->get('private_qr_key') ?: 'mel-rsvp-qr';
    $expected = hash('sha256', $event_id . ':' . $rsvp_id . ':' . $secret);
    if (!hash_equals($expected, $hash)) {
      return $this->jsonResponse(['status' => 'invalid', 'message' => 'Invalid QR']);
    }

    if ($rsvp->isCheckedIn()) {
      return $this->jsonResponse([
        'status' => 'repeat',
        'message' => 'Already checked in',
      ]);
    }

    $rsvp->checkIn();
    $rsvp->save();

    $name = $rsvp->get('attendee_name')->value ?? $rsvp->get('name')->value ?? '';

    return $this->jsonResponse([
      'status' => 'success',
      'message' => 'Checked in',
      'name' => $name,
    ]);
  }

  /**
   * Commerce RSVP: ticket_code format MEL-{event}-{order}-{item}-{suffix}.
   */
  private function validateCommerceRsvpTicketQr(string $ticketCode): JsonResponse {
    if (!$this->em->hasDefinition('event_attendee')) {
      return $this->jsonResponse(['status' => 'invalid', 'message' => 'RSVP not found']);
    }

    $ids = $this->em->getStorage('event_attendee')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('ticket_code', $ticketCode)
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return $this->jsonResponse(['status' => 'invalid', 'message' => 'RSVP not found']);
    }

    $attendee = $this->em->getStorage('event_attendee')->load(reset($ids));
    if (!$attendee instanceof EventAttendee) {
      return $this->jsonResponse(['status' => 'invalid', 'message' => 'RSVP not found']);
    }

    if ((string) $attendee->get('source')->value !== EventAttendee::SOURCE_RSVP) {
      return $this->jsonResponse(['status' => 'invalid', 'message' => 'Invalid code for RSVP check-in']);
    }

    $event = $attendee->get('event')->entity;
    if (!$event instanceof NodeInterface) {
      return $this->jsonResponse(['status' => 'invalid', 'message' => 'Event not found']);
    }

    if (!$this->canManageEvent($event)) {
      throw new AccessDeniedHttpException('You do not have access to check in for this event.');
    }

    if ($attendee->isCheckedIn()) {
      return $this->jsonResponse([
        'status' => 'repeat',
        'message' => 'Already checked in',
      ]);
    }

    if (is_object($this->melAttendeeCheckinManager) && method_exists($this->melAttendeeCheckinManager, 'checkInAttendee')) {
      /** @var array<string, mixed> $result */
      $result = $this->melAttendeeCheckinManager->checkInAttendee(
        $attendee,
        $this->account->getAccount(),
        'qr_scan',
      );
      if (!($result['success'] ?? FALSE)) {
        $reason = (string) ($result['reason'] ?? '');
        if ($reason === 'forbidden') {
          throw new AccessDeniedHttpException('You do not have access to complete this check-in.');
        }
        if (str_starts_with($reason, 'state_not_eligible')) {
          return $this->jsonResponse([
            'status' => 'invalid',
            'message' => 'This attendee cannot be checked in right now.',
          ]);
        }
        if ($reason === 'transition_failed') {
          return $this->jsonResponse([
            'status' => 'error',
            'message' => 'Check-in could not be saved.',
          ]);
        }
        if ($reason === 'no_event') {
          return $this->jsonResponse([
            'status' => 'invalid',
            'message' => 'Event data is unavailable for this attendee.',
          ]);
        }
        return $this->jsonResponse([
          'status' => 'error',
          'message' => 'Check-in failed.',
        ]);
      }

      $reasonOk = (string) ($result['reason'] ?? '');
      if ($reasonOk === 'already_checked_in') {
        return $this->jsonResponse([
          'status' => 'repeat',
          'message' => 'Already checked in',
          'name' => $attendee->label(),
        ]);
      }

      if (!($result['transitioned'] ?? FALSE)) {
        return $this->jsonResponse([
          'status' => 'error',
          'message' => 'Check-in did not complete.',
        ]);
      }

      $storage = $this->em->getStorage('event_attendee');
      $storage->resetCache([(int) $attendee->id()]);
      $reloaded = $storage->load($attendee->id());
      if ($reloaded instanceof EventAttendee) {
        $attendee = $reloaded;
      }
    }
    elseif ($this->attendanceManager instanceof AttendanceManager) {
      if (!$this->attendanceManager->checkIn($attendee)) {
        return $this->jsonResponse([
          'status' => 'repeat',
          'message' => 'Already checked in',
          'name' => $attendee->label(),
        ]);
      }
    }
    else {
      $attendee->checkIn();
      $attendee->save();
    }

    return $this->jsonResponse([
      'status' => 'success',
      'message' => 'Checked in',
      'name' => $attendee->label(),
    ]);
  }

  /**
   * Checks if the current user can manage the event for QR check-in.
   *
   * RSVP product/admin permissions preserved; organiser membership via
   * MelAttendeeOperationsAccess → EventVendorAccessChecker workspace parity.
   */
  private function canManageEvent(NodeInterface $event): bool {
    $account = $this->account->getAccount();
    if ($account->hasPermission('administer rsvps') || $account->hasPermission('administer nodes')) {
      return TRUE;
    }
    if (!$account->hasPermission('manage own event rsvps')) {
      return FALSE;
    }
    if ($this->attendeeOperationsAccess instanceof MelAttendeeOperationsAccessInterface) {
      return $this->attendeeOperationsAccess->accountHasOrganiserOwnership($event, $account);
    }
    // Fail closed when Mel attendee ops is unavailable.
    return FALSE;
  }

  /**
   * Returns a JSON response with uncacheable headers.
   */
  private function jsonResponse(array $data): JsonResponse {
    $response = new JsonResponse($data);
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');
    return $response;
  }

}
