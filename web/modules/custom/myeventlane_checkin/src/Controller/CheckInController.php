<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkin\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_checkin\Service\CheckInStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for check-in pages.
 */
final class CheckInController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly CheckInStorageInterface $checkInStorage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_checkin.storage'),
    );
  }

  /**
   * Main check-in page.
   */
  public function page(NodeInterface $node): array {
    // Check access.
    if (!$this->checkEventAccess($node)) {
      return ['#markup' => $this->t('Access denied.')];
    }

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
   * QR scan page.
   */
  public function scan(NodeInterface $node): array {
    if (!$this->checkEventAccess($node)) {
      return ['#markup' => $this->t('Access denied.')];
    }

    $build = [
      '#theme' => 'myeventlane_checkin_scan',
      '#event' => $node,
      '#attached' => [
        'library' => ['myeventlane_checkin/scan'],
      ],
    ];

    return $build;
  }

  /**
   * Attendee list page.
   */
  public function list(NodeInterface $node): array {
    if (!$this->checkEventAccess($node)) {
      return ['#markup' => $this->t('Access denied.')];
    }

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
  public function toggle(NodeInterface $node, int $attendee_id): JsonResponse {
    if (!$this->checkEventAccess($node)) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    // Determine attendee type from ID or request.
    $request = \Drupal::request();
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
    if (!$this->checkEventAccess($node)) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    $query = $request->query->get('q', '');
    $results = $this->checkInStorage->searchAttendees($node, $query);

    return new JsonResponse([
      'results' => $results,
    ]);
  }

  /**
   * Checks if user has access to event check-in.
   */
  private function checkEventAccess(NodeInterface $node): bool {
    // Owner always has access.
    if ((int) $node->getOwnerId() === (int) $this->currentUser()->id()) {
      return TRUE;
    }

    // @todo Check vendor staff roles.
    // For now, only owner.
    return FALSE;
  }

}
