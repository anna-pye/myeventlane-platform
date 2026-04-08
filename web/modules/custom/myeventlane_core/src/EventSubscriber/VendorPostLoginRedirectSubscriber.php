<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
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
 * When force_redirects is enabled, core may send users to public /vendor/* or
 * /admin/* first; the next request bounces to the vendor/admin host. If the
 * session cookie is not yet sent on that subdomain hop, the vendor gate sends
 * them back to public login — a redirect loop. Rewriting the first post-login
 * Location to the canonical vendor/admin URL (TrustedRedirectResponse) avoids
 * the intermediate public console hop.
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
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After RedirectResponseSubscriber (default 0) and other -10 listeners.
    return [KernelEvents::RESPONSE => ['onResponse', -25]];
  }

  /**
   * Replaces fragile post-login redirects with a trusted absolute URL.
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
    $trusted = $this->resolveTrustedPostLoginUrl($request);
    if ($trusted === NULL) {
      return;
    }

    if ($target === $trusted) {
      return;
    }

    $stuckOnLogin = str_contains($target, '/user/login');
    $crossHostConsole = $this->shouldRewriteCrossHostConsoleRedirect($target, $trusted);
    if (!$stuckOnLogin && !$crossHostConsole) {
      return;
    }

    $fixed = new TrustedRedirectResponse($trusted, $response->getStatusCode());
    foreach ($response->headers->getCookies() as $cookie) {
      $fixed->headers->setCookie($cookie);
    }

    $this->logger->notice('Adjusted post-login redirect to trusted URL @url (was @prev).', [
      '@url' => $trusted,
      '@prev' => $target,
    ]);

    $event->setResponse($fixed);
  }

  /**
   * TRUE when the browser would hit public host for a console path but trusted
   * target is the configured vendor/admin host (force_redirects multi-domain).
   */
  private function shouldRewriteCrossHostConsoleRedirect(string $currentTarget, string $trustedTarget): bool {
    if (!(bool) $this->configFactory->get('myeventlane_core.domain_settings')->get('force_redirects')) {
      return FALSE;
    }

    $c = parse_url($currentTarget);
    $t = parse_url($trustedTarget);
    if ($c === FALSE || $t === FALSE) {
      return FALSE;
    }

    $cp = $c['path'] ?? '';
    if (!str_starts_with($cp, '/vendor') && !str_starts_with($cp, '/admin')) {
      return FALSE;
    }

    $locCurrent = $this->urlPathAndQueryFragment($c);
    $locTrusted = $this->urlPathAndQueryFragment($t);
    if ($locCurrent !== $locTrusted) {
      return FALSE;
    }

    $ch = strtolower((string) ($c['host'] ?? ''));
    $th = strtolower((string) ($t['host'] ?? ''));

    return $ch !== '' && $th !== '' && $ch !== $th;
  }

  /**
   * @param array<string, mixed> $parts
   */
  private function urlPathAndQueryFragment(array $parts): string {
    $path = $parts['path'] ?? '';
    $q = isset($parts['query']) ? '?' . $parts['query'] : '';
    $frag = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
    return $path . $q . $frag;
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
