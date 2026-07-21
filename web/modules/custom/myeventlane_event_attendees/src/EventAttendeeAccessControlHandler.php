<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_attendees;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Access control handler for the event_attendee entity.
 *
 * Product permissions stay on this handler; organiser membership uses
 * workspace parity via EventVendorAccessChecker (MelAttendeeOperationsAccess
 * delegate). Cache metadata shape is preserved from the prior author-only
 * implementation.
 */
class EventAttendeeAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  /**
   * Constructs the access control handler.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    private readonly EventVendorAccessCheckerInterface $eventVendorAccessChecker,
  ) {
    parent::__construct($entity_type);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('myeventlane_vendor.event_access_checker'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\myeventlane_event_attendees\Entity\EventAttendee $entity */

    // Admin permission grants full access.
    if ($account->hasPermission('administer event attendees')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    $event = $entity->getEvent();
    $hasParity = $event instanceof NodeInterface
      && $this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $account);

    switch ($operation) {
      case 'view':
        if ($hasParity && $account->hasPermission('view own event attendees')) {
          return AccessResult::allowed()
            ->cachePerUser()
            ->addCacheableDependency($entity);
        }
        break;

      case 'update':
      case 'delete':
        if ($hasParity && $account->hasPermission('manage own event attendees')) {
          return AccessResult::allowed()
            ->cachePerUser()
            ->addCacheableDependency($entity);
        }
        break;
    }

    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    // Only admins can manually create attendees via the admin UI.
    // Normal creation happens through RSVP forms and checkout.
    return AccessResult::allowedIfHasPermission($account, 'administer event attendees');
  }

}
