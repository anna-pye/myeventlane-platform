<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Http\MelKernelAuthRouteSilencer;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_core\VendorConsoleTrust;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends vendor-console traffic to public login when there is no organiser session.
 *
 * Without this, anonymous users see a 403 on /vendor/dashboard, and
 * ticket-buyer sessions never reach PublicVendorLoginConflictSubscriber (which
 * only runs on public /user/login). Redirect preserves the vendor URL as
 * ?destination= so post-login returns to the console.
 *
 * Priority 33 runs after VendorSsoRedirectSubscriber (34) but before Symfony's
 * RouterListener (32) so console redirects are applied before route access runs.
 */
final class VendorConsoleGateRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly DomainDetector $domainDetector,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onRequest', 33]];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || \PHP_SAPI === 'cli') {
      return;
    }

    $request = $event->getRequest();
    if (MelKernelAuthRouteSilencer::shouldBypassAuthAccountRoutes($request)) {
      return;
    }
    if (!in_array($request->getMethod(), ['GET', 'HEAD'], TRUE)) {
      return;
    }

    if ($request->isXmlHttpRequest()) {
      return;
    }

    if (!$this->domainDetector->isVendorDomain()) {
      return;
    }

    $path = $request->getPathInfo();
    if (!$this->isVendorConsolePath($path)) {
      return;
    }

    try {
      $returnUrl = $this->buildSelfReturnUrl($request);
      if ($returnUrl === NULL) {
        return;
      }

      if ($this->currentUser->isAnonymous()) {
        // When vendor SSO redirect is enabled, VendorSsoRedirectSubscriber (priority 34)
        // runs before this subscriber and sets the /auth/login redirect. Do not override.
        // If it did not set one, fall through to legacy public login (e.g. misconfigured allowlist).
        $authSettings = $this->configFactory->get('myeventlane_auth.settings');
        if ((bool) $authSettings->get('vendor_sso_redirect_enabled') && $event->hasResponse()) {
          return;
        }

        $target = $this->domainDetector->buildDomainUrl('/user/login', 'public');
        $target .= '?' . http_build_query(['destination' => $returnUrl], '', '&', PHP_QUERY_RFC3986);
        $this->logger->notice('Vendor console: redirecting anonymous user to public login for path @path.', [
          '@path' => $path,
        ]);
        $event->setResponse(new TrustedRedirectResponse($target, 302));
        return;
      }

      if (VendorConsoleTrust::accountIsTrustedForVendorConsole($this->currentUser)) {
        return;
      }

      $base = $this->domainDetector->buildDomainUrl('/user/switch-for-organiser', 'public');
      $target = $base . '?' . http_build_query(['destination' => $returnUrl], '', '&', PHP_QUERY_RFC3986);
      $this->logger->notice('Vendor console: redirecting customer session to organiser switch for path @path.', [
        '@path' => $path,
      ]);
      $event->setResponse(new TrustedRedirectResponse($target, 302));
    }
    catch (\Throwable $e) {
      $this->logger->error('VendorConsoleGateRedirectSubscriber failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Vendor public profile is /vendor/{numeric}; onboarding stays on vendor host.
   */
  private function isVendorConsolePath(string $path): bool {
    if ($path === '/vendor' || $path === '') {
      return FALSE;
    }
    // Handled by myeventlane_vendor.login_alias → user.login (not console 403).
    if ($path === '/vendor/login') {
      return FALSE;
    }
    if (preg_match('#^/vendor/\d+$#', $path)) {
      return FALSE;
    }
    if (str_starts_with($path, '/vendor/onboard')) {
      return FALSE;
    }
    // OAuth return: anonymous must hit VendorSsoCallbackController with ?code= (not redirected away).
    if ($this->pathIsVendorSsoCallback($path)) {
      return FALSE;
    }
    return str_starts_with($path, '/vendor');
  }

  /**
   * Matches myeventlane_auth vendor SSO callback route (configurable path).
   */
  private function pathIsVendorSsoCallback(string $path): bool {
    $configured = trim((string) $this->configFactory->get('myeventlane_auth.settings')->get('vendor_sso_callback_path'));
    if ($configured === '') {
      $configured = '/vendor/sso/callback';
    }
    if (!str_starts_with($configured, '/')) {
      $configured = '/' . $configured;
    }
    return str_starts_with($path, $configured);
  }

  /**
   * Internal path (+ query) for ?destination= after public login.
   *
   * Core UserLoginForm only continues the destination flow when "destination"
   * is present in the POST body; many clients POST to /user/login without the
   * original query string. Using an internal path (not an absolute vendor URL)
   * also lets RedirectResponseSubscriber build a same-host Location header;
   * authenticated users are then moved to the vendor host by domain routing.
   */
  private function buildSelfReturnUrl(Request $request): ?string {
    $path = $request->getPathInfo();
    if ($path === '' || $path === '/') {
      return NULL;
    }
    $query = $request->query->all();
    unset($query['destination']);
    if ($query === []) {
      return $path;
    }
    return $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
  }

}
