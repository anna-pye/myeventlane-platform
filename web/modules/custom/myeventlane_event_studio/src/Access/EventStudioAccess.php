<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolver;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Access checker for the canonical vendor Event Studio workspace.
 *
 * Organiser owner and team membership (workspace parity) are sufficient.
 * Node entity update access alone is not required: Drupal node grants often
 * only cover the author, which would incorrectly block organiser-owner and
 * non-author team members from Studio despite event-management parity.
 */
final class EventStudioAccess implements ContainerInjectionInterface {

  public function __construct(
    private readonly CurrentVendorResolver $currentVendorResolver,
    private readonly EventVendorAccessCheckerInterface $eventVendorAccessChecker,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_vendor.current_vendor_resolver'),
      $container->get('myeventlane_vendor.event_access_checker'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory')->get('myeventlane_event_studio'),
    );
  }

  /**
   * Access callback for Event Studio routes.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    $event = $route_match->getParameter('node') ?? $route_match->getParameter('event');
    if (is_numeric($event)) {
      $event = $this->entityTypeManager->getStorage('node')->load((int) $event);
    }
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      return AccessResult::forbidden()
        ->addCacheContexts(['route']);
    }

    if ($account->isAnonymous()) {
      return AccessResult::forbidden()
        ->addCacheableDependency($event)
        ->addCacheContexts(['user.roles']);
    }

    if ($account->hasPermission('administer nodes')) {
      return AccessResult::allowed()
        ->addCacheableDependency($event)
        ->addCacheContexts(['user.permissions']);
    }

    $vendor = $this->currentVendorResolver->resolveFromEvent($event)
      ?? $this->currentVendorResolver->resolveFromUser($account);
    if ($vendor === NULL) {
      $this->logger->warning('Event Studio access denied: no vendor context for event_id=@eid uid=@uid', [
        '@eid' => (string) $event->id(),
        '@uid' => (string) $account->id(),
      ]);
      return AccessResult::forbidden()
        ->addCacheableDependency($event)
        ->addCacheContexts(['user']);
    }

    if (!$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $account)) {
      return AccessResult::forbidden()
        ->addCacheableDependency($event)
        ->addCacheableDependency($vendor)
        ->addCacheContexts(['user']);
    }

    return AccessResult::allowed()
      ->addCacheableDependency($event)
      ->addCacheableDependency($vendor)
      ->addCacheContexts(['user']);
  }

}
