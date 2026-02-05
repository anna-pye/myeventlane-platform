<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Route access check for event review form.
 *
 * Enforces feature flag at access level (fail-closed when disabled).
 * Used with _custom_access route requirement.
 */
final class EventReviewRouteAccessCheck {

  /**
   * Constructs the access checker.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory')
    );
  }

  /**
   * Checks access to the event review route.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param \Drupal\node\NodeInterface|null $node
   *   The event node (from route parameter).
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function checkAccess(AccountInterface $account, ?NodeInterface $node = NULL): AccessResult {
    $config = $this->configFactory->get('myeventlane_account.reviews');
    $cache_tags = ['config:myeventlane_account.reviews'];

    if (!$config->get('enabled')) {
      return AccessResult::forbidden()
        ->addCacheContexts(['user.roles'])
        ->addCacheTags($cache_tags);
    }

    if (!$node || $node->bundle() !== 'event') {
      return AccessResult::forbidden()
        ->addCacheContexts(['user.roles'])
        ->addCacheTags($cache_tags);
    }

    $cache_tags[] = 'node:' . $node->id();

    return AccessResult::allowedIfHasPermission($account, 'create event reviews')
      ->addCacheContexts(['user.roles'])
      ->addCacheTags($cache_tags);
  }

}
