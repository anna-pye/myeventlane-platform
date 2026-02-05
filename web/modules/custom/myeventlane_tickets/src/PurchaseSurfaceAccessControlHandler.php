<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_tickets\Service\EventAccess;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Access control handler for Purchase Surface entities.
 */
final class PurchaseSurfaceAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {


  /**
   * Constructs PurchaseSurfaceAccessControlHandler.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    private readonly EventAccess $eventAccess,
  ) {
    parent::__construct($entity_type);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('myeventlane_tickets.event_access'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\myeventlane_tickets\Entity\PurchaseSurface $entity */

    // Admin permission grants full access.
    if ($account->hasPermission('administer all events tickets')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Check event access via EventAccess service.
    $event = $entity->getEvent();
    if (!$event) {
      return AccessResult::forbidden('Purchase surface has no associated event.');
    }

    if (!$this->eventAccess->canManageEventTickets($event)) {
      return AccessResult::forbidden()->cachePerUser()->addCacheableDependency($entity);
    }

    return AccessResult::allowed()
      ->cachePerUser()
      ->addCacheableDependency($entity)
      ->addCacheableDependency($event);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    if ($account->hasPermission('administer all events tickets')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    if ($account->hasPermission('manage own events tickets')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return AccessResult::forbidden();
  }

}
