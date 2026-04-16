<?php

declare(strict_types=1);

namespace Drupal\myeventlane_support_console\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Http\MelKernelAuthRouteSilencer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Redirects staff (administer escalations) from front page to Support Console.
 *
 * Redirect only when:
 * - User has administer escalations permission
 * - Route is <front>
 * - Config redirect_enabled is true
 * - Not AJAX
 * - Not CLI
 * - No ?no-console=1 query param
 *
 * Fails safely: no redirect on exception; config toggle to disable instantly.
 */
final class SupportConsoleRedirectSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[KernelEvents::REQUEST][] = ['onRequest', -100];
    return $events;
  }

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Handles the request event.
   */
  public function onRequest(RequestEvent $event): void {
    try {
      if (!$event->isMainRequest()) {
        return;
      }

      if (PHP_SAPI === 'cli') {
        return;
      }

      $request = $event->getRequest();
      if (MelKernelAuthRouteSilencer::shouldBypassAuthAccountRoutes($request)) {
        return;
      }
      if ($request->isXmlHttpRequest()) {
        return;
      }

      if ($request->query->get('no-console') === '1') {
        return;
      }

      $route = $request->attributes->get('_route');
      if ($route !== '<front>') {
        return;
      }

      if (!$this->currentUser->hasPermission('administer escalations')) {
        return;
      }

      $config = $this->configFactory->get('myeventlane_support_console.settings');
      if (!$config->get('redirect_enabled')) {
        return;
      }

      try {
        $url = Url::fromRoute('myeventlane_support_console.dashboard');
      }
      catch (\Throwable) {
        return;
      }

      $response = new RedirectResponse($url->toString(), 302);
      $event->setResponse($response);
    }
    catch (\Throwable) {
    }
  }

}
