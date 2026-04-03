<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\EventSubscriber;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Fixes post-login redirect when LocalRedirectResponse rejects the destination host.
 *
 * RedirectResponseSubscriber turns destination into an absolute URL using the
 * current request host. LocalRedirectResponse then allows it only if the host
 * matches router.request_context complete base URL (exact match). Multi-host
 * installs (vendor.* vs apex) often end up with mismatched hosts; setTargetUrl
 * throws, the failure is swallowed, and the browser stays on /user/login. The
 * session is already authenticated, but user.login requires anonymous access,
 * so the user sees an access failure instead of the dashboard.
 */
final class VendorPostLoginRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly DomainDetector $domainDetector,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After RedirectResponseSubscriber (default 0) and other -10 listeners.
    return [KernelEvents::RESPONSE => ['onResponse', -25]];
  }

  /**
   * Replaces a stuck /user/login redirect with a trusted vendor URL when needed.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    if ($request->getMethod() !== 'POST') {
      return;
    }

    if ((string) $request->attributes->get('_route') !== 'user.login') {
      return;
    }

    if (!$this->currentUser->isAuthenticated()) {
      return;
    }

    if (!$this->domainDetector->isVendorDomain()) {
      return;
    }

    $response = $event->getResponse();
    if (!$response instanceof RedirectResponse) {
      return;
    }

    $target = $response->getTargetUrl();
    if (!str_contains($target, '/user/login')) {
      return;
    }

    $path = '/vendor/dashboard';
    $destination = $request->query->get('destination');
    if (is_string($destination) && str_starts_with($destination, '/') && !str_starts_with($destination, '//')) {
      $path = $destination;
    }

    try {
      $url = $this->domainDetector->buildDomainUrl($path, 'vendor');
    }
    catch (\Throwable $e) {
      $this->logger->error('Vendor post-login redirect failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return;
    }

    $fixed = new TrustedRedirectResponse($url, $response->getStatusCode());
    foreach ($response->headers->getCookies() as $cookie) {
      $fixed->headers->setCookie($cookie);
    }

    $event->setResponse($fixed);
  }

}
