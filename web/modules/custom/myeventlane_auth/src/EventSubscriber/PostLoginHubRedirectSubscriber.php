<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\EventSubscriber;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_auth\Service\PostAuthRedirectResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Wraps successful user.login redirects to the MEL post-login hub when appropriate.
 *
 * Preserves the original internal destination and mel_intent as query params on
 * the hub URL. Skips admin, API, and already-hub targets.
 *
 * Service is not tagged: hub staging runs in hook_user_login() and
 * MelPostLoginSessionRedirectSubscriber (request phase) so the session is
 * committed before any hub RedirectResponse.
 */
final class PostLoginHubRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly PostAuthRedirectResolver $postAuthRedirectResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => ['onKernelResponse', -512],
    ];
  }

  public function onKernelResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $response = $event->getResponse();
    if (!$response instanceof RedirectResponse || !$response->isRedirection()) {
      return;
    }
    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== 'user.login') {
      return;
    }
    if (!$this->currentUser->isAuthenticated()) {
      return;
    }

    $target = $response->getTargetUrl();
    $internalPath = $this->extractInternalPath($request, $target);
    if ($internalPath === NULL) {
      return;
    }

    if ($this->shouldSkipHubWrap($internalPath)) {
      return;
    }

    $intent = $this->readIntentFromRequest($request);
    $destinationPath = strtok($internalPath, '?') ?: $internalPath;
    $hub = $this->postAuthRedirectResolver->buildPostLoginHubUrl(
      $this->currentUser->getAccount(),
      $destinationPath,
      $intent,
    );
    // Replacing the response drops headers added during the request (including
    // Set-Cookie for the session). Copy cookies from the outgoing redirect so
    // the browser still stores the session on the hub hop — same pattern as
    // VendorPostLoginRedirectSubscriber.
    $hubResponse = new RedirectResponse($hub, $response->getStatusCode());
    foreach ($response->headers->getCookies() as $cookie) {
      $hubResponse->headers->setCookie($cookie);
    }
    $event->setResponse($hubResponse);
  }

  /**
   * Reads mel_intent from query or POST (login form may POST intent as hidden field).
   */
  private function readIntentFromRequest(Request $request): string {
    $q = $request->query->get('mel_intent');
    if (is_string($q) && $q !== '') {
      return $q;
    }
    $p = $request->request->get('mel_intent');
    return is_string($p) ? $p : '';
  }

  /**
   * Returns an internal path (with query string) for hub wrapping, or NULL if unsafe.
   */
  private function extractInternalPath(Request $request, string $target): ?string {
    $parts = parse_url($target);
    if ($parts === FALSE) {
      return NULL;
    }
    $scheme = $parts['scheme'] ?? '';
    if ($scheme !== '' && !in_array($scheme, ['http', 'https'], TRUE)) {
      return NULL;
    }
    if (isset($parts['host'])) {
      $currentHost = $request->getHost();
      if (strcasecmp((string) $parts['host'], $currentHost) !== 0) {
        return NULL;
      }
    }
    $path = $parts['path'] ?? '/';
    $base = $request->getBasePath();
    if ($base !== '' && str_starts_with($path, $base)) {
      $path = substr($path, strlen($base)) ?: '/';
    }
    if ($path === '') {
      $path = '/';
    }
    if (!str_starts_with($path, '/')) {
      return NULL;
    }
    $query = isset($parts['query']) && is_string($parts['query']) ? '?' . $parts['query'] : '';
    return $path . $query;
  }

  /**
   * Avoid wrapping redirects that must stay untouched or would recurse.
   */
  private function shouldSkipHubWrap(string $internalPath): bool {
    $pathOnly = strtok($internalPath, '?') ?: $internalPath;
    if ($pathOnly === '/mel/post-login') {
      return TRUE;
    }
    if ($pathOnly === '/mel/auth/continue') {
      return TRUE;
    }
    if (str_starts_with($pathOnly, '/admin')) {
      return TRUE;
    }
    if (str_starts_with($pathOnly, '/system/')) {
      return TRUE;
    }
    if (str_starts_with($pathOnly, '/batch')) {
      return TRUE;
    }
    return FALSE;
  }

}
