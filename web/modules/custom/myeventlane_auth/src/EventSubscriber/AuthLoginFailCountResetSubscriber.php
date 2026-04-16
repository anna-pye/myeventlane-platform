<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\EventSubscriber;

use Drupal\myeventlane_auth\Service\MelAuthOAuthSession;
use Drupal\myeventlane_core\Http\MelKernelAuthRouteSilencer;
use Drupal\myeventlane_core\Service\DomainDetector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resets vendor SSO fail counter on the vendor host when login is not a retry.
 *
 * Scoped to vendor domain + existing fail count so public/admin login traffic
 * does not clear vendor callback failure state.
 */
final class AuthLoginFailCountResetSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly DomainDetector $domainDetector,
  ) {}

  public static function getSubscribedEvents(): array {
    // After routing so _route is available (core RouterListener runs at 32).
    return [KernelEvents::REQUEST => ['onRequest', 33]];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || \PHP_SAPI === 'cli') {
      return;
    }

    if (!$this->domainDetector->isVendorDomain()) {
      return;
    }

    $request = $event->getRequest();
    if (MelKernelAuthRouteSilencer::shouldBypassAuthAccountRoutes($request)) {
      return;
    }
    if ((string) $request->attributes->get('_route') !== 'user.login') {
      return;
    }

    // GET only: avoids edge cases with HEAD (proxies, crawlers) mutating session.
    if ($request->getMethod() !== 'GET') {
      return;
    }

    $destination = $request->query->get('destination');
    if ($destination === '/vendor/dashboard') {
      return;
    }

    $session = $request->getSession();
    if ((int) $session->get(MelAuthOAuthSession::KEY_FAIL_COUNT, 0) === 0) {
      return;
    }

    MelAuthOAuthSession::resetFailCount($session);
  }

}
