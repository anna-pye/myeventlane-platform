<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects users viewing their own profile to the My Account dashboard.
 *
 * When a logged-in user visits /user/{uid} or /Anna (path alias) where {uid}
 * is their own ID, redirect to /my-account so they see the custom dashboard
 * instead of the default Drupal user profile view.
 */
final class UserProfileRedirectSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
    ];
  }

  /**
   * Redirects own-profile views to /my-account.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $route_name = $this->routeMatch->getRouteName();
    $route = $this->routeMatch->getRouteObject();

    if (!$route || $route_name !== 'entity.user.canonical') {
      return;
    }

    $user_param = $this->routeMatch->getParameter('user');
    if ($user_param === NULL) {
      return;
    }

    $uid = is_object($user_param) ? (int) $user_param->id() : (int) $user_param;
    $current_uid = (int) $this->currentUser->id();

    if (!$this->currentUser->isAuthenticated() || $current_uid !== $uid) {
      return;
    }

    $url = Url::fromRoute('myeventlane_account.dashboard');
    $event->setResponse(new RedirectResponse($url->toString(), 302));
  }

}
