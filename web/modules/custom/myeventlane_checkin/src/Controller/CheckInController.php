<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkin\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_checkin\Service\CheckInStorageInterface;
use Drupal\myeventlane_core\VendorConsoleTrust;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller for check-in pages.
 */
final class CheckInController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly CheckInStorageInterface $checkInStorage,
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_checkin.storage'),
      $container->get('myeventlane_vendor.event_access_checker'),
    );
  }

  /**
   * Main check-in page — VX2-05 converges on Door Mode for console users.
   *
   * Team accounts with check-in-only permission (no vendor console trust) keep
   * the legacy UI: Door Mode is gated by vendor_console access and would 403.
   *
   * @return array<string, mixed>|\Symfony\Component\HttpFoundation\RedirectResponse
   *   Legacy page build or Door Mode redirect.
   */
  public function page(NodeInterface $node): array|RedirectResponse {
    $this->assertEventAccess($node);

    if ($this->shouldConvergeToDoorMode()) {
      return new RedirectResponse(
        Url::fromRoute('myeventlane_event_attendees.vendor_operations_door', ['node' => $node->id()])->toString(),
        302,
      );
    }

    $attendees = $this->checkInStorage->getAttendees($node);
    $checkedInCount = count(array_filter($attendees, fn($a) => $a['checked_in']));
    $totalCount = count($attendees);

    return [
      '#theme' => 'myeventlane_checkin_page',
      '#event' => $node,
      '#attendees' => $attendees,
      '#stats' => [
        'total' => $totalCount,
        'checked_in' => $checkedInCount,
        'remaining' => $totalCount - $checkedInCount,
      ],
      '#attached' => [
        'library' => ['myeventlane_checkin/checkin'],
      ],
    ];
  }

  /**
   * Legacy QR scan route — Door Mode for console users; otherwise scan UI.
   *
   * @return array<string, mixed>|\Symfony\Component\HttpFoundation\RedirectResponse
   *   Legacy scan build or Door Mode redirect.
   */
  public function scan(NodeInterface $node): array|RedirectResponse {
    $this->assertEventAccess($node);

    if ($this->shouldConvergeToDoorMode()) {
      return new RedirectResponse(
        Url::fromRoute('myeventlane_event_attendees.vendor_operations_door', ['node' => $node->id()])->toString(),
        302,
      );
    }

    return [
      '#theme' => 'myeventlane_checkin_scan',
      '#event' => $node,
      '#attached' => [
        'library' => ['myeventlane_checkin/checkin'],
      ],
    ];
  }

  /**
   * Attendee list page — Door Mode for console users; otherwise legacy list.
   *
   * @return array<string, mixed>|\Symfony\Component\HttpFoundation\RedirectResponse
   *   Legacy list build or Door Mode redirect.
   */
  public function list(NodeInterface $node): array|RedirectResponse {
    $this->assertEventAccess($node);

    if ($this->shouldConvergeToDoorMode()) {
      return new RedirectResponse(
        Url::fromRoute('myeventlane_event_attendees.vendor_operations_door', ['node' => $node->id()])->toString(),
        302,
      );
    }

    $attendees = $this->checkInStorage->getAttendees($node);

    return [
      '#theme' => 'myeventlane_checkin_list',
      '#event' => $node,
      '#attendees' => $attendees,
      '#attached' => [
        'library' => ['myeventlane_checkin/checkin'],
      ],
    ];
  }

  /**
   * Toggle check-in status.
   *
   * Requires POST + CSRF (route) + event ownership + attendee∈event bind.
   */
  public function toggle(NodeInterface $node, int $attendee_id, Request $request): JsonResponse {
    $this->assertEventAccess($node);

    $type = (string) $request->query->get('type', 'rsvp');
    if (!in_array($type, ['rsvp', 'ticket'], TRUE)) {
      throw new AccessDeniedHttpException();
    }

    // Bind attendee to route event before any mutation.
    if (!$this->checkInStorage->attendeeBelongsToEvent($node, $attendee_id, $type)) {
      throw new AccessDeniedHttpException();
    }

    $newStatus = $this->checkInStorage->toggleCheckIn(
      $node,
      $attendee_id,
      $type,
      (int) $this->currentUser()->id()
    );

    return new JsonResponse([
      'success' => TRUE,
      'checked_in' => $newStatus,
    ]);
  }

  /**
   * Search attendees.
   */
  public function search(NodeInterface $node, Request $request): JsonResponse {
    $this->assertEventAccess($node);

    $query = $request->query->get('q', '');
    $results = $this->checkInStorage->searchAttendees($node, $query);

    return new JsonResponse([
      'results' => $results,
    ]);
  }

  /**
   * Whether this account may use Door Mode (vendor console trust).
   *
   * Door Mode routes use vendor_console access; check-in-only team accounts
   * must not be redirected into a surface they cannot open.
   */
  private function shouldConvergeToDoorMode(): bool {
    return VendorConsoleTrust::accountIsTrustedForVendorConsole($this->currentUser());
  }

  /**
   * Ensures the user may check in for this event (owner, vendor team, or admin).
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   */
  private function assertEventAccess(NodeInterface $node): void {
    $account = $this->currentUser();
    if ($account->hasPermission('administer nodes')) {
      return;
    }
    if ((int) $node->getOwnerId() === (int) $account->id()) {
      return;
    }
    if ($this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($node, $account)) {
      return;
    }
    throw new AccessDeniedHttpException();
  }

}
