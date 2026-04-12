<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_boost\Service\StripeChecker;
use Drupal\myeventlane_vendor\Access\VendorConsoleAccess;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Access control for boost routes.
 *
 * Enforces:
 * - Event bundle validation.
 * - Admin override.
 * - Owner + purchase permission (public /event/{node}/boost).
 * - Published event requirement.
 * - Stripe connected requirement.
 *
 * Vendor workspace route additionally requires VendorConsoleAccess and allows
 * vendor team members linked via field_event_vendor (same as event console).
 */
final class BoostRouteAccess {

  private const EVENT_BUNDLE = 'event';

  private const PERMISSION_PURCHASE = 'purchase boost for events';

  private const PERMISSION_ADMIN_BOOST = 'administer myeventlane boost';

  private const PERMISSION_ADMIN_PLATFORM = 'administer myeventlane';

  private const PERMISSION_ADMIN_NODES = 'administer nodes';

  private const CACHE_CONTEXTS = ['user', 'user.permissions'];

  /**
   * The stripe checker.
   */
  private StripeChecker $stripeChecker;

  /**
   * The module logger.
   */
  private LoggerInterface $logger;

  /**
   * Constructs BoostRouteAccess.
   *
   * @param \Drupal\myeventlane_boost\Service\StripeChecker|\Drupal\Core\Entity\EntityTypeManagerInterface $stripeChecker
   *   The stripe checker service, or legacy entity type manager in stale
   *   container builds.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    StripeChecker|EntityTypeManagerInterface $stripeChecker,
    LoggerInterface $logger,
  ) {
    $this->logger = $logger;
    $this->stripeChecker = $stripeChecker instanceof StripeChecker
      ? $stripeChecker
      : new StripeChecker($stripeChecker, $logger);
  }

  /**
   * Checks access to boost route.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   The upcasted event node parameter.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function access(?NodeInterface $node, AccountInterface $account): AccessResult {
    if (!$node instanceof NodeInterface) {
      $this->logger->debug('Boost access denied: invalid node parameter');
      return AccessResult::forbidden()
        ->addCacheContexts(self::CACHE_CONTEXTS);
    }

    // Admin override.
    if ($this->hasAdminOverride($account)) {
      $this->logger->debug('Boost access allowed via admin override for user @uid and event @nid', [
        '@uid' => $account->id(),
        '@nid' => $node->id(),
      ]);
      return AccessResult::allowed()
        ->addCacheContexts(self::CACHE_CONTEXTS)
        ->addCacheableDependency($node);
    }

    if ($node->bundle() !== self::EVENT_BUNDLE) {
      $this->logger->debug('Boost access denied: node @nid is not an event bundle', [
        '@nid' => $node->id(),
      ]);
      return AccessResult::forbidden()
        ->addCacheContexts(self::CACHE_CONTEXTS)
        ->addCacheableDependency($node);
    }

    // Must be owner.
    if ((int) $node->getOwnerId() !== (int) $account->id()) {
      $this->logger->debug('Boost access denied: user @uid is not owner of event @nid', [
        '@uid' => $account->id(),
        '@nid' => $node->id(),
      ]);
      return AccessResult::forbidden()
        ->addCacheContexts(self::CACHE_CONTEXTS)
        ->addCacheableDependency($node);
    }

    $permissionAccess = AccessResult::allowedIfHasPermission($account, self::PERMISSION_PURCHASE)
      ->addCacheableDependency($node);
    if (!$permissionAccess->isAllowed()) {
      $this->logger->debug('Boost access denied: user @uid lacks permission @permission', [
        '@uid' => $account->id(),
        '@permission' => self::PERMISSION_PURCHASE,
      ]);
      return $permissionAccess
        ->addCacheContexts(self::CACHE_CONTEXTS)
        ->addCacheableDependency($node);
    }

    // Must be published.
    if (!$node->isPublished()) {
      $this->logger->debug('Boost access denied: event @nid is not published', [
        '@nid' => $node->id(),
      ]);
      return AccessResult::forbidden('Event must be published to boost.')
        ->addCacheContexts(self::CACHE_CONTEXTS)
        ->addCacheableDependency($node);
    }

    // Must have Stripe connected (non-admin path).
    if (!$this->stripeChecker->isConnected($account)) {
      $this->logger->debug('Boost access denied: Stripe is not connected for user @uid', [
        '@uid' => $account->id(),
      ]);
      return AccessResult::forbidden('Connect Stripe before purchasing boosts.')
        ->addCacheContexts(self::CACHE_CONTEXTS)
        ->addCacheableDependency($node);
    }

    return AccessResult::allowed()
      ->addCacheContexts(self::CACHE_CONTEXTS)
      ->addCacheableDependency($node);
  }

  /**
   * Access for /vendor/events/{event}/boost — vendor console + team + boost rules.
   */
  public function accessVendorWorkspace(RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    $vendorAccess = VendorConsoleAccess::access($route_match, $account);
    if (!$vendorAccess->isAllowed()) {
      return $vendorAccess;
    }

    $event = $route_match->getParameter('event');
    if (!$event instanceof NodeInterface) {
      $this->logger->debug('Boost vendor workspace denied: missing event parameter');
      return AccessResult::forbidden()
        ->addCacheContexts(self::CACHE_CONTEXTS);
    }

    if ($this->hasAdminOverride($account)) {
      return AccessResult::allowed()
        ->addCacheContexts(self::CACHE_CONTEXTS)
        ->addCacheableDependency($event);
    }

    if ($event->bundle() !== self::EVENT_BUNDLE) {
      return AccessResult::forbidden()
        ->addCacheContexts(self::CACHE_CONTEXTS)
        ->addCacheableDependency($event);
    }

    if (!$this->accountManagesEvent($event, $account)) {
      $this->logger->debug('Boost vendor workspace denied: user @uid cannot manage event @nid', [
        '@uid' => $account->id(),
        '@nid' => $event->id(),
      ]);
      return AccessResult::forbidden()
        ->addCacheContexts(self::CACHE_CONTEXTS)
        ->addCacheableDependency($event);
    }

    $permissionAccess = AccessResult::allowedIfHasPermission($account, self::PERMISSION_PURCHASE)
      ->addCacheableDependency($event);
    if (!$permissionAccess->isAllowed()) {
      return $permissionAccess
        ->addCacheContexts(self::CACHE_CONTEXTS)
        ->addCacheableDependency($event);
    }

    if (!$event->isPublished()) {
      return AccessResult::forbidden('Event must be published to boost.')
        ->addCacheContexts(self::CACHE_CONTEXTS)
        ->addCacheableDependency($event);
    }

    if (!$this->stripeChecker->isConnected($account)) {
      return AccessResult::forbidden('Connect Stripe before purchasing boosts.')
        ->addCacheContexts(self::CACHE_CONTEXTS)
        ->addCacheableDependency($event);
    }

    return AccessResult::allowed()
      ->addCacheContexts(self::CACHE_CONTEXTS)
      ->addCacheableDependency($event);
  }

  /**
   * Whether the account is the event owner or a linked vendor team member.
   */
  private function accountManagesEvent(NodeInterface $event, AccountInterface $account): bool {
    if ((int) $event->getOwnerId() === (int) $account->id()) {
      return TRUE;
    }
    if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      $vendor = $event->get('field_event_vendor')->entity;
      if ($vendor && $vendor->hasField('field_vendor_users')) {
        foreach ($vendor->get('field_vendor_users')->getValue() as $item) {
          if (isset($item['target_id']) && (int) $item['target_id'] === (int) $account->id()) {
            return TRUE;
          }
        }
      }
    }
    return FALSE;
  }

  /**
   * Determines whether the account has admin-level boost overrides.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return bool
   *   TRUE when an admin override permission is present.
   */
  private function hasAdminOverride(AccountInterface $account): bool {
    return $account->hasPermission(self::PERMISSION_ADMIN_BOOST)
      || $account->hasPermission(self::PERMISSION_ADMIN_PLATFORM)
      || $account->hasPermission(self::PERMISSION_ADMIN_NODES);
  }

}
