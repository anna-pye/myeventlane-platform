<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\EventSubscriber;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_core\Service\MelDestinationNormalizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends anonymous auth-page GETs on the vendor host to the public host.
 *
 * Login/register/password on vendor.* uses the vendor theme and shell; in
 * practice this has been fragile (library clashes, session/redirect edge cases)
 * while the same forms on the public host work reliably. One 302 to the
 * public URL preserves query parameters and rewrites destination to an absolute
 * URL on the correct host so post-login returns to vendor with the shared
 * session cookie (.myeventlane.com.au).
 */
final class VendorHostAuthRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly DomainDetector $domainDetector,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
    private readonly MelDestinationNormalizer $destinationNormalizer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onRequest', 36]];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || \PHP_SAPI === 'cli') {
      return;
    }

    $request = $event->getRequest();
    if ($request->getMethod() !== 'GET') {
      return;
    }

    if (!$this->domainDetector->isVendorDomain()) {
      return;
    }

    if ($this->currentUser->isAuthenticated()) {
      return;
    }

    $path = $request->getPathInfo();
    if (!$this->isAuthPath($path)) {
      return;
    }

    try {
      $query = $request->query->all();
      $query['destination'] = $this->normalizeDestination($request);
      $base = $this->domainDetector->buildDomainUrl($path, 'public');
      $target = $base . ($query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }
    catch (\Throwable $e) {
      $this->logger->error('VendorHostAuthRedirectSubscriber failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return;
    }

    $this->logger->notice('Vendor host auth page redirected to public host for path @path', [
      '@path' => $path,
    ]);

    $event->setResponse(new TrustedRedirectResponse($target, 302));
  }

  private function isAuthPath(string $path): bool {
    if (in_array($path, ['/user/login', '/user/register', '/user/password'], TRUE)) {
      return TRUE;
    }
    if (str_starts_with($path, '/user/reset/')) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Ensures destination is absolute so post-login lands on vendor (or public) correctly.
   */
  private function normalizeDestination(Request $request): string {
    $raw = $request->query->get('destination');
    $requestHost = $request->getHost();

    if (!is_string($raw) || $raw === '') {
      return $this->domainDetector->buildDomainUrl('/vendor/dashboard', 'vendor');
    }
    if (str_starts_with($raw, '//')) {
      return $this->domainDetector->buildDomainUrl('/vendor/dashboard', 'vendor');
    }
    if (preg_match('#^https?://#i', $raw)) {
      $trusted = $this->destinationNormalizer->validateTrustedAbsoluteUrl($raw, $requestHost);
      if ($trusted !== NULL) {
        return $trusted;
      }
      return $this->domainDetector->buildDomainUrl('/vendor/dashboard', 'vendor');
    }

    $pathOnly = str_contains($raw, '?') ? strstr($raw, '?', TRUE) : $raw;
    $pathOnly = '/' . ltrim((string) $pathOnly, '/');
    $built = $this->destinationNormalizer->absoluteFromInternalPathVendorPublicSplit($pathOnly);
    if ($built !== NULL) {
      return $built;
    }

    return $this->domainDetector->buildDomainUrl('/vendor/dashboard', 'vendor');
  }

}
