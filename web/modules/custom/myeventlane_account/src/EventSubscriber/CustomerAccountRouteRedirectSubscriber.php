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
    if (!$this->currentUser->isAuthenticated()) {
      return;
    }

    $route = $this->routeMatch->getRouteName();
    $parameterUser = $this->routeMatch->getParameter('user');
    if (!$parameterUser instanceof UserInterface) {
      return;
    }

    if ((int) $parameterUser->id() !== (int) $this->currentUser->id()) {
      return;
    }

    if ($route === 'entity.user.edit_form') {
      $url = Url::fromRoute('myeventlane_account.settings', ['user' => $parameterUser->id()])->toString();
      $event->setResponse(new RedirectResponse($url, 301));
      return;
    }

    if ($route === 'entity.user.canonical') {
      $url = Url::fromRoute('myeventlane_account.dashboard')->toString();
      $event->setResponse(new RedirectResponse($url, 301));
    }
  }

}
