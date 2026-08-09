<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends customers away from legacy Drupal profile routes to the MEL hub.
 *
 * Also requires login for customer-only hub pages implemented as Views or
 * lightweight controllers (saved events, followed organisers).
 */
final class CustomerAccountRouteRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onKernelRequest', 30]];
  }

  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $route = $this->routeMatch->getRouteName();

    if (!$this->currentUser->isAuthenticated()) {
      if (in_array($route, [
        'view.mel_saved_events.page_1',
        'myeventlane_account.followed_organisers',
      ], TRUE)) {
        $destination = $event->getRequest()->getRequestUri();
        $login = Url::fromRoute('user.login', [], [
          'query' => ['destination' => $destination],
        ])->toString();
        $event->setResponse(new RedirectResponse($login, 302));
      }
      return;
    }
    $parameterUser = $this->routeMatch->getParameter('user');
    if (!$parameterUser instanceof UserInterface) {
      return;
    }

    if ((int) $parameterUser->id() !== (int) $this->currentUser->id()) {
      return;
    }

    if ($route === 'entity.user.edit_form') {
      $request = $event->getRequest();
      $query = [];
      foreach (['pass-reset-token', 'check_logged_in'] as $key) {
        $value = $request->query->get($key);
        if (is_string($value) && $value !== '') {
          $query[$key] = $value;
        }
      }
      $url = Url::fromRoute(
        'myeventlane_account.settings',
        ['user' => $parameterUser->id()],
        ['query' => $query],
      )->toString();
      // A one-time password token must never be cached as a permanent redirect.
      $event->setResponse(new RedirectResponse($url, 302));
      return;
    }

    if ($route === 'entity.user.canonical') {
      $url = Url::fromRoute('myeventlane_account.dashboard')->toString();
      $event->setResponse(new RedirectResponse($url, 301));
    }
  }

}
