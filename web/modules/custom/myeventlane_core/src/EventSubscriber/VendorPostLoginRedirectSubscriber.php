<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\EventSubscriber;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_core\Service\MelDestinationNormalizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Fixes post-login redirect when cross-host destinations are rejected.
 *
 * Redirect handling may build a redirect whose target is still /user/login
 * (e.g. destination points at vendor.* while login POST happened on staging.*).
 * The session cookie is often set on that response, but the browser follows a
 * bad Location header, so the user lands on an anonymous-only route while
 * authenticated — appearing as "lost session" on the next hop.
 *
 * Runs on every app host (public, vendor, admin): copies Set-Cookie onto a
 * TrustedRedirectResponse to a destination that is limited to configured MEL
 * domain hosts and path-based routing (/vendor → vendor_domain, etc.).
 */
final class VendorPostLoginRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly DomainDetector $domainDetector,
    private readonly LoggerInterface $logger,
    private readonly MelDestinationNormalizer $destinationNormalizer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After RedirectResponseSubscriber (default 0) and other -10 listeners.
    return [KernelEvents::RESPONSE => ['onResponse', -25]];
  }

  /**
   * Replaces a stuck /user/login redirect with a trusted URL when needed.
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

    $response = $event->getResponse();
    if (!$response instanceof RedirectResponse) {
      return;
    }

    $target = $response->getTargetUrl();
    if (!str_contains($target, '/user/login')) {
      return;
    }

    $url = $this->resolveTrustedPostLoginUrl($request);
    if ($url === NULL) {
      return;
    }

    $fixed = new TrustedRedirectResponse($url, $response->getStatusCode());
    foreach ($response->headers->getCookies() as $cookie) {
      $fixed->headers->setCookie($cookie);
    }

    $this->logger->notice('Adjusted post-login redirect away from stuck /user/login to @url', [
      '@url' => $url,
    ]);

    $event->setResponse($fixed);
  }

  private function resolveTrustedPostLoginUrl(Request $request): ?string {
    $destination = $request->request->get('destination');
    if (!is_string($destination) || $destination === '') {
      $destination = $request->query->get('destination');
    }

    if (is_string($destination) && $destination !== '') {
      if (str_starts_with($destination, '//')) {
        return NULL;
      }
      if (preg_match('#^https?://#i', $destination)) {
        return $this->destinationNormalizer->validateTrustedAbsoluteUrl($destination, $request->getHost());
      }
      if (str_starts_with($destination, '/')) {
        return $this->destinationNormalizer->absoluteFromInternalPathPostLogin($destination);
      }
      return NULL;
    }

    if ($this->domainDetector->isVendorDomain()) {
      try {
        return $this->domainDetector->buildDomainUrl('/vendor/dashboard', 'vendor');
      }
      catch (\Throwable $e) {
        $this->logger->error('Vendor post-login default redirect failed: @message', [
          '@message' => $e->getMessage(),
        ]);
        return NULL;
      }
    }

    if ($this->domainDetector->isAdminDomain()) {
      try {
        return $this->domainDetector->buildDomainUrl('/admin/myeventlane', 'admin');
      }
      catch (\Throwable $e) {
        $this->logger->error('Admin post-login default redirect failed: @message', [
          '@message' => $e->getMessage(),
        ]);
        return NULL;
      }
    }

    try {
      return $this->domainDetector->buildDomainUrl('/', 'public');
    }
    catch (\Throwable $e) {
      $this->logger->error('Public post-login default redirect failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}
