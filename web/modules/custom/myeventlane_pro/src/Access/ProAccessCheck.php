<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_pro\Event\ProFeatureAccessDeniedEvent;
use Drupal\myeventlane_pro\Service\ProActiveResolver;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Route;

final class ProAccessCheck implements AccessInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ProActiveResolver $resolver,
    private readonly EventDispatcherInterface $eventDispatcher,
  ) {}

  public function access(Route $route, AccountInterface $account): AccessResult {

    $user = $this->entityTypeManager
      ->getStorage('user')
      ->load((int) $account->id());

    if (!$user instanceof UserInterface) {
      return AccessResult::forbidden();
    }

    if ($this->resolver->isUserProActive($user)) {
      return AccessResult::allowed()
        ->addCacheContexts(['user'])
        ->addCacheTags(['user:' . $user->id()]);
    }

    $routeName = $route->getRouteName() ?? '';
    if ($routeName !== '') {
      $this->eventDispatcher->dispatch(new ProFeatureAccessDeniedEvent($user, $routeName));
    }

    return AccessResult::forbidden()
      ->addCacheContexts(['user']);
  }

}
