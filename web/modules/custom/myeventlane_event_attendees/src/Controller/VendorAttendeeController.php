<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_attendees\Controller;

use Drupal\Core\Url;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_event_attendees\Service\AttendanceManagerInterface;
use Drupal\myeventlane_event_attendees\Service\MelAttendeeExportBuilder;
use Drupal\myeventlane_event_attendees\Service\VendorAttendeePresentationService;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for vendor attendee management pages.
 */
final class VendorAttendeeController extends ControllerBase {

  /**
   * Per-purpose CSRF token id for the manual check-in endpoint.
   *
   * Mirrors {@see \Drupal\myeventlane_checkout_flow\Service\MelVenueOperationsViewModelBuilder::CHECKIN_CSRF_ID}.
   * Kept as a local constant (and asserted to match at validation time) so
   * this module does not take a hard dependency on `myeventlane_checkout_flow`.
   *
   * The token is rendered into:
   *   - the operations page wrapper as `data-mel-ops-checkin-token` (read by
   *     the AJAX behaviour and sent as `X-CSRF-Token`);
   *   - the per-row check-in form as a hidden `_token` field (used by the
   *     no-JS progressive-enhancement fallback).
   */
  private const CHECKIN_CSRF_ID = 'mel_vendor_attendee_checkin';

  /**
   * Constructs VendorAttendeeController.
   */
  public function __construct(
    private readonly AttendanceManagerInterface $attendanceManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly VendorAttendeePresentationService $vendorPresentation,
    private readonly MelAttendeeExportBuilder $exportBuilder,
    private readonly mixed $melAttendeeCheckinManager,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly EventVendorAccessCheckerInterface $eventVendorAccessChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_event_attendees.manager'),
      $container->get('date.formatter'),
      $container->get('myeventlane_event_attendees.vendor_presentation'),
      $container->get('myeventlane_event_attendees.attendee_export_builder'),
      $container->has('myeventlane_checkout_flow.attendee_checkin_manager')
        ? $container->get('myeventlane_checkout_flow.attendee_checkin_manager')
        : NULL,
      $container->get('csrf_token'),
      // MelAttendeeOperationsAccess lives in checkout_flow (depends on this
      // module); ownership uses the same canonical checker Mel delegates to.
      $container->get('myeventlane_vendor.event_access_checker'),
    );
  }

  /**
   * Access check for vendor attendee routes.
   *
   * Product gates preserved; organiser membership is workspace parity via
   * EventVendorAccessChecker (MelAttendeeOperationsAccess delegate).
   */
  public function access(NodeInterface $node, AccountInterface $account): AccessResultInterface {
    // Must be an event node.
    if ($node->bundle() !== 'event') {
      return AccessResult::forbidden('Not an event.');
    }

    // Admin can access all.
    if ($account->hasPermission('administer event attendees')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    if (!$account->hasPermission('view own event attendees')) {
      return AccessResult::forbidden('You do not have access to view attendees for this event.');
    }

    // Canonical workspace parity: author, vendor entity owner, or team.
    if ($this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($node, $account)) {
      return AccessResult::allowed()
        ->cachePerUser()
        ->addCacheableDependency($node);
    }

    return AccessResult::forbidden('You do not have access to view attendees for this event.');
  }

  /**
   * Access check for single attendee operations.
   *
   * Reuses same policy as access(): admin, product permission, or parity.
   */
  public function accessAttendee(EventAttendee $event_attendee, AccountInterface $account): AccessResultInterface {
    $event = $event_attendee->getEvent();

    if (!$event instanceof NodeInterface) {
      return AccessResult::forbidden('Event not found.');
    }

    return $this->access($event, $account);
  }

  /**
   * Page title callback for attendee list.
   */
  public function listTitle(NodeInterface $node): string {
    return (string) $this->t('Ticket holders for @event', ['@event' => $node->label()]);
  }

  /**
   * Lists attendees for an event, grouped by ticket type.
   */
  public function list(NodeInterface $node): array {
    $attendees = $this->attendanceManager->getAttendeesForEvent((int) $node->id());
    $availability = $this->attendanceManager->getAvailability($node);

    // Group attendees by source and ticket type.
    $grouped = [
      'rsvp' => [],
      'ticket' => [],
      'manual' => [],
    ];

    $ticketTypeGroups = [];

    foreach ($attendees as $attendee) {
      $source = $attendee->getSource();
      $grouped[$source][] = $attendee;

      // For ticket-based attendees, group by variation.
      if ($source === 'ticket' && $attendee->hasField('order_item') && !$attendee->get('order_item')->isEmpty()) {
        $orderItem = $attendee->get('order_item')->entity;
        if ($orderItem) {
          $purchasedEntity = $orderItem->getPurchasedEntity();
          if ($purchasedEntity) {
            $variationId = $purchasedEntity->id();
            $variationTitle = $purchasedEntity->label();

            // Extract ticket type from variation title (e.g., "Event Name – General" -> "General").
            $ticketType = $variationTitle;
            if (strpos($variationTitle, ' – ') !== FALSE) {
              $parts = explode(' – ', $variationTitle, 2);
              $ticketType = $parts[1] ?? $variationTitle;
            }

            if (!isset($ticketTypeGroups[$variationId])) {
              $ticketTypeGroups[$variationId] = [
                'title' => $ticketType,
                'attendees' => [],
              ];
            }
            $ticketTypeGroups[$variationId]['attendees'][] = $attendee;
          }
        }
      }
    }

    $build = [];

    // Summary section.
    $build['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['attendee-summary']],
    ];

    $summaryItems = [
      $this->t('Total attendees: @count', ['@count' => count($attendees)]),
      $this->t('RSVPs: @count', ['@count' => count($grouped['rsvp'])]),
      $this->t('Tickets: @count', ['@count' => count($grouped['ticket'])]),
      $this->t('Capacity: @capacity', [
        '@capacity' => $availability['capacity'] > 0 ? $availability['capacity'] : $this->t('Unlimited'),
      ]),
    ];

    if ($availability['remaining'] !== NULL) {
      $summaryItems[] = $this->t('Spots remaining: @remaining', ['@remaining' => $availability['remaining']]);
    }

    $build['summary']['stats'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Summary'),
      '#items' => $summaryItems,
    ];

    // Export links.
    $build['summary']['export'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['export-links']],
    ];

    $build['summary']['export']['csv'] = [
      '#type' => 'link',
      '#title' => $this->t('Export to CSV'),
      '#url' => Url::fromRoute('myeventlane_event_attendees.vendor_export', ['node' => $node->id()]),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $build['summary']['export']['csv_obfuscated'] = [
      '#type' => 'link',
      '#title' => $this->t('Export to CSV (Obfuscated Emails)'),
      '#url' => Url::fromRoute('myeventlane_event_attendees.vendor_export', ['node' => $node->id()], ['query' => ['obfuscate' => '1']]),
      '#attributes' => ['class' => ['button', 'button--secondary']],
    ];

    // RSVP attendees section.
    if (!empty($grouped['rsvp'])) {
      $build['rsvp_section'] = [
        '#type' => 'details',
        '#title' => $this->t('RSVP responses (@count)', ['@count' => count($grouped['rsvp'])]),
        '#open' => TRUE,
      ];

      $rsvpRows = [];
      foreach ($grouped['rsvp'] as $attendee) {
        $rsvpRows[] = [
          'name' => $attendee->getName(),
          'email' => $attendee->getEmail(),
          'status' => ucfirst($attendee->getStatus()),
          'checked_in' => $attendee->isCheckedIn() ? $this->t('Yes') : $this->t('No'),
          'created' => $this->dateFormatter->format($attendee->get('created')->value, 'short'),
        ];
      }

      $build['rsvp_section']['table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Name'),
          $this->t('Email'),
          $this->t('Status'),
          $this->t('Checked in'),
          $this->t('Registered'),
        ],
        '#rows' => $rsvpRows,
        '#attributes' => ['class' => ['attendee-list', 'rsvp-list']],
      ];
    }

    // Ticket attendees grouped by ticket type.
    if (!empty($ticketTypeGroups)) {
      $build['ticket_section'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ticket-attendees']],
      ];

      $build['ticket_section']['title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Ticket holders (@count)', ['@count' => count($grouped['ticket'])]),
      ];

      foreach ($ticketTypeGroups as $variationId => $group) {
        $build['ticket_section'][$variationId] = [
          '#type' => 'details',
          '#title' => $this->t('@type (@count)', [
            '@type' => $group['title'],
            '@count' => count($group['attendees']),
          ]),
          '#open' => TRUE,
        ];

        $ticketRows = [];
        foreach ($group['attendees'] as $attendee) {
          $ticketRows[] = [
            'name' => $attendee->getName(),
            'email' => $attendee->getEmail(),
            'ticket_code' => $attendee->getTicketCode() ?? '',
            'status' => ucfirst($attendee->getStatus()),
            'checked_in' => $attendee->isCheckedIn() ? $this->t('Yes') : $this->t('No'),
            'created' => $this->dateFormatter->format($attendee->get('created')->value, 'short'),
          ];
        }

        $build['ticket_section'][$variationId]['table'] = [
          '#type' => 'table',
          '#header' => [
            $this->t('Name'),
            $this->t('Email'),
            $this->t('Ticket Code'),
            $this->t('Status'),
            $this->t('Checked in'),
            $this->t('Registered'),
          ],
          '#rows' => $ticketRows,
          '#attributes' => ['class' => ['attendee-list', 'ticket-list']],
        ];
      }
    }

    // Manual attendees.
    if (!empty($grouped['manual'])) {
      $build['manual_section'] = [
        '#type' => 'details',
        '#title' => $this->t('Manual Entries (@count)', ['@count' => count($grouped['manual'])]),
        '#open' => FALSE,
      ];

      $manualRows = [];
      foreach ($grouped['manual'] as $attendee) {
        $manualRows[] = [
          'name' => $attendee->getName(),
          'email' => $attendee->getEmail(),
          'status' => ucfirst($attendee->getStatus()),
          'checked_in' => $attendee->isCheckedIn() ? $this->t('Yes') : $this->t('No'),
          'created' => $this->dateFormatter->format($attendee->get('created')->value, 'short'),
        ];
      }

      $build['manual_section']['table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Name'),
          $this->t('Email'),
          $this->t('Status'),
          $this->t('Checked in'),
          $this->t('Registered'),
        ],
        '#rows' => $manualRows,
        '#attributes' => ['class' => ['attendee-list', 'manual-list']],
      ];
    }

    // Empty state — governed via mel_empty_state through
    // GovernedOperationalTemplates if available, with a sane fallback.
    if (empty($attendees)) {
      $build['empty'] = $this->governedEmptyAttendees();
    }

    $build['#cache'] = [
      'tags' => ['event_attendee_list:' . $node->id()],
      'contexts' => ['user'],
    ];

    return $build;
  }

  /**
   * Builds the governed empty state for "no attendees yet" surfaces.
   *
   * Uses the optional governed_operational_templates service when available
   * (myeventlane_surface), with a static markup fallback when the service is
   * not registered (e.g. minimal install profiles).
   *
   * @return array<string, mixed>
   *   Empty attendee list render payload.
   */
  private function governedEmptyAttendees(): array {
    if (\Drupal::hasService('myeventlane_surface.governed_operational_templates')) {
      try {
        return \Drupal::service('myeventlane_surface.governed_operational_templates')
          ->vendorAttendeeOperationsNoAttendeesYet();
      }
      catch (\Throwable $e) {
        \Drupal::logger('myeventlane_event_attendees')->warning(
          'Governed empty state failed: @msg.',
          ['@msg' => $e->getMessage()]
        );
      }
    }
    return [
      '#markup' => '<p>' . $this->t('No ticket holders or RSVPs yet. Share your event link to start collecting responses or ticket sales.') . '</p>',
    ];
  }

  /**
   * Exports attendees as CSV with optional email obfuscation.
   *
   * IMPORTANT:
   * Exports {@see \Drupal\myeventlane_event_attendees\Entity\EventAttendee} rows
   * via {@see \Drupal\myeventlane_event_attendees\Service\VendorAttendeePresentationService}
   * so CSV matches vendor console attendees and order detail (canonical operations record).
   */
  public function export(NodeInterface $node): Response {
    $request = \Drupal::request();
    $obfuscateEmails = (bool) $request->query->get('obfuscate', FALSE);

    $filename = $this->exportBuilder->buildFilename($node, 'attendees');
    $rows = $this->exportBuilder->buildRowsForEvent($node, $obfuscateEmails);

    $entities = $this->attendanceManager->getAttendeesForEvent((int) $node->id());
    $pairTotal = 0;
    foreach ($entities as $entity) {
      if ($entity instanceof EventAttendee) {
        $pairTotal += count($this->vendorPresentation->normalizeCustomAnswers($entity));
      }
    }
    $this->vendorPresentation->logVendorParityBatch('csv_export', (int) $node->id(), count($entities), $pairTotal);

    $exportBuilder = $this->exportBuilder;
    $response = new StreamedResponse(static function () use ($exportBuilder, $rows): void {
      $handle = fopen('php://output', 'w');
      if (!$handle) {
        return;
      }
      $exportBuilder->streamCsv($handle, $rows);
      fclose($handle);
    });

    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');

    return $response;
  }

  /**
   * Checks in an attendee.
   *
   * Dual-mode endpoint that preserves the canonical writer
   * (`MelAttendeeCheckinManager::checkInAttendee`) and the JSON contract
   * for AJAX clients, while giving non-XHR (progressive enhancement /
   * no-JS) browser submissions a redirect back to the operations page so
   * the user never sees raw JSON.
   *
   *  - XHR (`X-Requested-With: XMLHttpRequest`) → JSON Response (unchanged).
   *  - Non-XHR browser POST → flash message + 303 redirect to
   *    `myeventlane_event_attendees.vendor_operations` for the attendee's
   *    event. Falls back to the public check-in page if the event cannot be
   *    resolved (defensive — should not happen because access already
   *    requires an event-bound attendee).
   *
   * Security:
   *   - Vendor ownership is enforced server-side via `accessAttendee()` on
   *     the route.
   *   - **CSRF protection** is enforced here for both transports:
   *       - AJAX: `X-CSRF-Token` request header.
   *       - Form fallback: hidden `_token` POST field.
   *     Both are validated against the per-purpose token id
   *     {@see self::CHECKIN_CSRF_ID}, matching the token generated by
   *     `MelVenueOperationsViewModelBuilder::CHECKIN_CSRF_ID`.
   *   - Failures fail loudly (logged via `myeventlane_event_attendees` and
   *     surface either a 403 JSON response or a 303 redirect with an error
   *     flash message) — never a silent success.
   *
   * No new mutation paths are introduced; only the response shape varies
   * by client.
   */
  public function checkIn(EventAttendee $event_attendee, Request $request): Response {
    $isXhr = $request->isXmlHttpRequest();

    if (!$this->validateCheckinCsrf($request)) {
      \Drupal::logger('myeventlane_event_attendees')->warning(
        'Vendor manual check-in CSRF rejection: attendee @id, user @uid, xhr=@xhr.',
        [
          '@id' => (int) $event_attendee->id(),
          '@uid' => (int) $this->currentUser()->id(),
          '@xhr' => $isXhr ? '1' : '0',
        ]
      );
      return $this->respondCheckinDenied(
        $event_attendee,
        $isXhr,
        'csrf_invalid',
        $this->t('Your session has expired. Please refresh the page and try again.')
      );
    }

    [$success, $reason, $message] = $this->performAttendeeCheckIn($event_attendee);

    if ($isXhr) {
      $statusCode = $reason === 'forbidden' ? 403 : 200;
      return new Response(
        json_encode([
          'success' => $success,
          'reason' => $reason,
          'message' => (string) $message,
          'attendee_id' => (int) $event_attendee->id(),
        ]),
        $statusCode,
        ['Content-Type' => 'application/json']
      );
    }

    if ($success) {
      $this->messenger()->addStatus($message);
    }
    elseif ($reason === 'forbidden') {
      $this->messenger()->addError($message);
    }
    else {
      $this->messenger()->addWarning($message);
    }

    return $this->redirectToOperationsForAttendee($event_attendee);
  }

  /**
   * Validates the CSRF token for the manual check-in endpoint.
   *
   * Accepts either source (header or form field) so the dual transport
   * (AJAX + no-JS form) keeps working without weakening the gate. Both
   * sources are validated against the same per-purpose id.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming POST request.
   *
   * @return bool
   *   TRUE iff a non-empty token from header or POST body validates.
   */
  private function validateCheckinCsrf(Request $request): bool {
    $headerToken = (string) ($request->headers->get('X-CSRF-Token') ?? '');
    if ($headerToken !== '' && $this->csrfToken->validate($headerToken, self::CHECKIN_CSRF_ID)) {
      return TRUE;
    }
    $bodyToken = (string) ($request->request->get('_token') ?? '');
    if ($bodyToken !== '' && $this->csrfToken->validate($bodyToken, self::CHECKIN_CSRF_ID)) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Builds a denial response that matches the request transport.
   *
   * @param \Drupal\myeventlane_event_attendees\Entity\EventAttendee $event_attendee
   *   The attendee being denied.
   * @param bool $isXhr
   *   TRUE if the request is XHR; FALSE for native form submission.
   * @param string $reason
   *   Machine-readable reason (e.g. "csrf_invalid").
   * @param \Stringable|string $message
   *   Human-readable message for the user.
   */
  private function respondCheckinDenied(
    EventAttendee $event_attendee,
    bool $isXhr,
    string $reason,
    \Stringable|string $message,
  ): Response {
    if ($isXhr) {
      return new Response(
        json_encode([
          'success' => FALSE,
          'reason' => $reason,
          'message' => (string) $message,
          'attendee_id' => (int) $event_attendee->id(),
        ]),
        403,
        ['Content-Type' => 'application/json']
      );
    }
    $this->messenger()->addError($message);
    return $this->redirectToOperationsForAttendee($event_attendee);
  }

  /**
   * Performs the canonical check-in transition.
   *
   * Returns a tuple [bool $success, string $reason, \Stringable|string $message]
   * suitable for both JSON and flash-message rendering.
   *
   * @return array{0: bool, 1: string, 2: \Stringable|string}
   *   Tuple of success flag, machine reason, and user message.
   */
  private function performAttendeeCheckIn(EventAttendee $event_attendee): array {
    $name = $event_attendee->getName();

    if (is_object($this->melAttendeeCheckinManager) && method_exists($this->melAttendeeCheckinManager, 'checkInAttendee')) {
      $result = $this->melAttendeeCheckinManager->checkInAttendee(
        $event_attendee,
        $this->currentUser(),
        'vendor_list',
      );
      if (!$result['success'] && ($result['reason'] ?? '') === 'forbidden') {
        return [FALSE, 'forbidden', $this->t('Access denied.')];
      }
      if ($result['success'] && ($result['reason'] ?? '') === 'already_checked_in') {
        return [TRUE, 'already_checked_in', $this->t('@name is already checked in.', ['@name' => $name])];
      }
      if ($result['success'] && !empty($result['transitioned'])) {
        return [TRUE, 'checked_in', $this->t('@name has been checked in.', ['@name' => $name])];
      }
      return [FALSE, (string) ($result['reason'] ?? 'unknown'), $this->t('Check-in could not be completed.')];
    }

    // Legacy fallback when checkout_flow is disabled.
    $success = $this->attendanceManager->checkIn($event_attendee);
    if ($success) {
      return [TRUE, 'checked_in', $this->t('@name has been checked in.', ['@name' => $name])];
    }
    return [FALSE, 'already_checked_in', $this->t('@name is already checked in.', ['@name' => $name])];
  }

  /**
   * Builds a redirect back to Live Operations for the attendee's event.
   *
   * If the event cannot be resolved (defensive — should not happen because
   * the route's accessAttendee gate requires an event-bound attendee) we
   * fall back to the user's referrer or the front page rather than emitting
   * a JSON page.
   */
  private function redirectToOperationsForAttendee(EventAttendee $event_attendee): RedirectResponse {
    $event = $event_attendee->getEvent();
    if ($event instanceof NodeInterface) {
      $url = Url::fromRoute(
        'myeventlane_event_attendees.vendor_operations',
        ['node' => (int) $event->id()],
      )->toString();
      return new RedirectResponse($url, 303);
    }

    \Drupal::logger('myeventlane_event_attendees')->warning(
      'Attendee @id has no resolvable event during non-XHR check-in redirect; falling back to home.',
      ['@id' => (int) $event_attendee->id()]
    );
    return new RedirectResponse(Url::fromRoute('<front>')->toString(), 303);
  }

}
