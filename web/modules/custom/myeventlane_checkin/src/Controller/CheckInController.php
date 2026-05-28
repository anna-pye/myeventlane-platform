<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkin\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_checkin\Service\CheckInStorageInterface;
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
   * Main check-in page.
   */
  public function page(NodeInterface $node): array {
    $this->assertEventAccess($node);

    $attendees = $this->checkInStorage->getAttendees($node);
    $checkedInCount = count(array_filter($attendees, fn($a) => $a['checked_in']));
    $totalCount = count($attendees);

    $build = [
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

    return $build;
  }

  /**
   * Legacy QR scan route — redirects to canonical Door Mode.
   */
  public function scan(NodeInterface $node): RedirectResponse {
    $this->assertEventAccess($node);

    return new RedirectResponse(
      Url::fromRoute('myeventlane_event_attendees.vendor_operations_door', ['node' => $node->id()])->toString(),
      302,
    );
  }

  /**
   * Attendee list page.
   */
  public function list(NodeInterface $node): array {
    $this->assertEventAccess($node);

    $attendees = $this->checkInStorage->getAttendees($node);

    $build = [
      '#theme' => 'myeventlane_checkin_list',
      '#event' => $node,
      '#attendees' => $attendees,
      '#attached' => [
        'library' => ['myeventlane_checkin/checkin'],
      ],
    ];

    return $build;
  }

  /**
   * Toggle check-in status.
   */
  public function toggle(NodeInterface $node, int $attendee_id, Request $request): JsonResponse {
    $this->assertEventAccess($node);

    $type = $request->query->get('type', 'rsvp');

    $newStatus = $this->checkInStorage->toggleCheckIn(
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
