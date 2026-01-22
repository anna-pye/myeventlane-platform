<?php

declare(strict_types=1);

namespace Drupal\myeventlane_dashboard\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;

/**
 * Service for loading events for the dashboard.
 */
class DashboardEventLoader {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Constructs a DashboardEventLoader object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * Loads events for the dashboard.
   *
   * @param bool $admin
   *   If TRUE, load all events. If FALSE, load only user's events.
   * @param int $limit
   *   Maximum number of events to load.
   *
   * @return \Drupal\node\NodeInterface[]
   *   An array of event nodes.
   */
  public function loadEvents(bool $admin = FALSE, int $limit = 10): array {
    try {
      $storage = $this->entityTypeManager->getStorage('node');
      $userId = (int) $this->currentUser->id();

      // If user is anonymous and not admin, return empty.
      if (!$admin && $userId === 0) {
        return [];
      }

      $query = $storage->getQuery()
        ->accessCheck($admin)
        ->condition('type', 'event')
        ->sort('created', 'DESC')
        ->range(0, $limit);

      // Vendors only see their own events (including unpublished).
      if (!$admin) {
        $query->condition('uid', $userId);
        // Don't filter by status for vendors - they should see all their events.
      }
      else {
        // Admins see only published events.
        $query->condition('status', NodeInterface::PUBLISHED);
      }

      $ids = $query->execute();
      if (empty($ids)) {
        return [];
      }

      $events = $storage->loadMultiple($ids);

      // Filter out events the user doesn't have access to (for unpublished events).
      if (!$admin) {
        $events = array_filter($events, function (NodeInterface $event) use ($userId) {
          // User owns the event, so they can see it regardless of status.
          return (int) $event->getOwnerId() === $userId;
        });
      }

      return $events;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Gets the count of events.
   *
   * @param bool $admin
   *   If TRUE, count all events. If FALSE, count only user's events.
   *
   * @return int
   *   The event count.
   */
  public function getEventCount(bool $admin = FALSE): int {
    try {
      $query = $this->entityTypeManager
        ->getStorage('node')
        ->getQuery()
        ->accessCheck($admin)
        ->condition('type', 'event')
        ->condition('status', NodeInterface::PUBLISHED);

      if (!$admin) {
        $query->condition('uid', $this->currentUser->id());
      }

      return (int) $query->count()->execute();
    }
    catch (\Exception $e) {
      return 0;
    }
  }

}
