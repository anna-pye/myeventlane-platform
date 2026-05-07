<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Http\MelKernelAuthRouteSilencer;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Canonical redirects keeping Drupal user routes as infrastructure, not primary UX.
 */
final class SurfaceRouteSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
    ];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    if (MelKernelAuthRouteSilencer::shouldBypassAuthAccountRoutes($event->getRequest())) {
      return;
    }

    $route_name = $this->routeMatch->getRouteName();
    $route = $this->routeMatch->getRouteObject();
    if (!$route || !$route_name) {
      return;
    }

    if ($route_name === 'entity.user.canonical') {
      $this->redirectOwnProfile($event, 'myeventlane_account.dashboard');
      return;
    }

    if ($route_name === 'entity.user.edit_form') {
      $this->redirectOwnProfile($event, 'myeventlane_account.settings', TRUE);
    }
  }

  /**
   * Redirects when the current user is editing or viewing their own account.
   *
   * @param string $target_route
   *   Route name to redirect to.
   * @param bool $needs_user_parameter
   *   Whether the target route requires a {user} route parameter.
   */
  private function redirectOwnProfile(RequestEvent $event, string $target_route, bool $needs_user_parameter = FALSE): void {
    $user_param = $this->routeMatch->getParameter('user');
    if ($user_param === NULL) {
      return;
    }

    $uid = is_object($user_param) ? (int) $user_param->id() : (int) $user_param;
    $current_uid = (int) $this->currentUser->id();

    if (!$this->currentUser->isAuthenticated() || $current_uid !== $uid) {
      return;
    }

    try {
      $route_parameters = [];
      if ($needs_user_parameter) {
        $route_parameters['user'] = $uid;
      }
      $url = Url::fromRoute($target_route, $route_parameters);
      // Route resolution happens during string generation, not only in fromRoute().
      $event->setResponse(new RedirectResponse($url->toString(), 302));
    }
    catch (\Throwable $e) {
      $this->logger->warning('Surface redirect skipped for @route (missing route or generate failure): @message', [
        '@route' => $target_route,
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
