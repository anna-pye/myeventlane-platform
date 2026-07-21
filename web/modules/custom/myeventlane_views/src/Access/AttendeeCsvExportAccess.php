<?php

declare(strict_types=1);

namespace Drupal\myeventlane_views\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Route access for legacy /dashboard/attendees/export.
 *
 * Download requests require workspace parity on the event identified by
 * download_csv. The embed/list path requires organiser capability only.
 * Missing or foreign events both return forbidden (no existence leak).
 */
final class AttendeeCsvExportAccess implements AccessInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventVendorAccessCheckerInterface $eventVendorAccessChecker,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Checks access for the legacy attendee CSV route.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission('administer nodes')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    if (!$account->isAuthenticated()) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    $request = $this->requestStack->getCurrentRequest();
    $eventIdRaw = $request?->query->get('download_csv');
    if ($eventIdRaw === NULL || $eventIdRaw === '' || $eventIdRaw === FALSE) {
      if ($account->hasPermission('create event content')
        || $account->hasPermission('edit own event content')
        || $account->hasPermission('access vendor console')) {
        return AccessResult::allowed()->cachePerPermissions();
      }
      return AccessResult::forbidden()->cachePerPermissions();
    }

    $eventId = (int) $eventIdRaw;
    if ($eventId <= 0) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    $event = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      return AccessResult::forbidden()
        ->cachePerPermissions()
        ->addCacheTags(['node:' . $eventId]);
    }

    if ($this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $account)) {
      return AccessResult::allowed()
        ->cachePerUser()
        ->addCacheTags(['node:' . $eventId]);
    }

    return AccessResult::forbidden()
      ->cachePerUser()
      ->addCacheTags(['node:' . $eventId]);
  }

}
