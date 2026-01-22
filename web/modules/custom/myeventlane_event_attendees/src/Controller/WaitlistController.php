<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_attendees\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_event_attendees\Service\AttendanceWaitlistManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for waitlist-related endpoints.
 */
final class WaitlistController extends ControllerBase {

  /**
   * Constructs WaitlistController.
   */
  public function __construct(
    private readonly AttendanceWaitlistManager $waitlistManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_event_attendees.waitlist'),
    );
  }

  /**
   * Returns waitlist position for the current user.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with waitlist position or error.
   */
  public function getPosition(NodeInterface $node): JsonResponse {
    if ($node->bundle() !== 'event') {
      return new JsonResponse(['error' => 'Invalid event.'], 400);
    }

    $currentUser = $this->currentUser();
    if ($currentUser->isAnonymous()) {
      return new JsonResponse(['error' => 'Authentication required.'], 401);
    }

    // Find attendee record for current user.
    $storage = $this->entityTypeManager()->getStorage('event_attendee');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $node->id())
      ->condition('email', $currentUser->getEmail())
      ->condition('status', 'waitlist')
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return new JsonResponse([
        'on_waitlist' => FALSE,
        'position' => NULL,
        'total_waitlist' => $this->waitlistManager->getWaitlistCount((int) $node->id()),
      ]);
    }

    $attendee = $storage->load(reset($ids));
    $position = $this->waitlistManager->getWaitlistPosition($attendee);
    $totalWaitlist = $this->waitlistManager->getWaitlistCount((int) $node->id());

    return new JsonResponse([
      'on_waitlist' => TRUE,
      'position' => $position,
      'total_waitlist' => $totalWaitlist,
      'event_id' => $node->id(),
      'event_title' => $node->label(),
    ]);
  }

  /**
   * Access callback for waitlist position endpoint.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   Access result.
   */
  public function accessPosition(NodeInterface $node, AccountInterface $account): AccessResult {
    // Allow authenticated users to check their waitlist position.
    return AccessResult::allowedIf($account->isAuthenticated())
      ->addCacheContexts(['user']);
  }

}
