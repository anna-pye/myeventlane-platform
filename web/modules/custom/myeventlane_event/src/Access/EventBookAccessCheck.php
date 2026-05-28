<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_event\Service\PublicEventVisibility;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;

/**
 * Access checker for the event booking route.
 *
 * Enforces visibility rules before the booking flow resolver decides
 * whether booking is available.
 */
final class EventBookAccessCheck implements AccessInterface {

  public function __construct(
    private readonly PublicEventVisibility $publicVisibility,
    private readonly RequestStack $requestStack,
  ) {}

  public function access(Route $route, AccountInterface $account, ?NodeInterface $node = NULL): AccessResultInterface {
    if (!$node instanceof NodeInterface || $node->bundle() !== 'event') {
      return AccessResult::forbidden('Not an event node.')
        ->addCacheContexts(['route']);
    }

    $request = $this->requestStack->getCurrentRequest();

    if ($this->publicVisibility->canBook($node, $account, $request)) {
      return AccessResult::allowed()
        ->addCacheableDependency($node)
        ->cachePerUser()
        ->addCacheContexts(['session']);
    }

    return AccessResult::forbidden('Event visibility restricts booking access.')
      ->addCacheableDependency($node)
      ->cachePerUser()
      ->addCacheContexts(['session']);
  }

}
