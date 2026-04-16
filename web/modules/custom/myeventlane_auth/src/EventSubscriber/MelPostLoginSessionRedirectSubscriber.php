<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_auth\PostLoginDecision;
use Drupal\myeventlane_auth\Service\IdentityIntentResolver;
use Drupal\myeventlane_auth\Service\PostAuthRedirectResolver;
use Drupal\myeventlane_core\Http\MelKernelAuthRouteSilencer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Performs MEL post-login hub redirect on the first GET after password login.
 *
 * Staging runs in hook_user_login() (session save + mel_post_login_redirect bag).
 * This listener runs after the login redirect is followed — not on the login
 * RedirectResponse — so the session cookie is committed before any MEL hop.
 *
 * Destination policy (vendor vs customer vs onboarding) is exclusively in
 * PostLoginRouter. This subscriber only builds the hub URL and redirects;
 * shouldSkipHubWrap() is a safety guard, not business routing.
 */
final class MelPostLoginSessionRedirectSubscriber implements EventSubscriberInterface {

  public const SESSION_KEY = 'mel_post_login_redirect';

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly PostAuthRedirectResolver $postAuthRedirectResolver,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After UserProfileRedirectSubscriber (30): replace profile redirect when hub pending.
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 20],
    ];
  }

  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    if (MelKernelAuthRouteSilencer::shouldBypassAuthAccountRoutes($request)) {
      return;
    }
    if (!$request->isMethod('GET')) {
      return;
    }
    if ($request->isXmlHttpRequest()) {
      return;
    }

    if (!$this->currentUser->isAuthenticated()) {
      return;
    }

    if (!$request->hasSession()) {
      return;
    }

    $session = $request->getSession();
    $pending = $session->get(self::SESSION_KEY);
    if (!is_array($pending)) {
      return;
    }

    $destRaw = isset($pending['destination']) && is_string($pending['destination']) ? $pending['destination'] : '';
    $intent = isset($pending['intent']) && is_string($pending['intent']) ? $pending['intent'] : '';

    $internal = $this->destinationToInternalPath($request, $destRaw);
    if ($internal === NULL || $this->shouldSkipHubWrap($internal)) {
      $session->remove(self::SESSION_KEY);
      $session->save();
      return;
    }

    $session->remove(self::SESSION_KEY);
    $session->save();

    $destinationPath = strtok($internal, '?') ?: $internal;
    try {
      $hub = $this->postAuthRedirectResolver->buildPostLoginHubUrl(
        $this->currentUser->getAccount(),
        $destinationPath,
        $intent,
      );
    }
    catch (\Throwable $e) {
      $uid = (int) $this->currentUser->id();
      $normalized_intent = trim($intent) !== '' ? $intent : IdentityIntentResolver::INTENT_BROWSE;
      $this->logger->error(
        'MelPostLoginSessionRedirectSubscriber: hub URL build failed uid=@uid intent=@intent decision=@decision route=@route message=@message',
        [
          '@uid' => (string) $uid,
          '@intent' => $normalized_intent,
          '@decision' => PostLoginDecision::SESSION_HUB_URL_BUILD_FAILED,
          '@route' => 'myeventlane_auth.post_login',
          '@message' => $e->getMessage(),
        ],
      );
      return;
    }

    $event->setResponse(new RedirectResponse($hub, 302));
  }

  /**
   * Maps a destination string to an internal path for this host, or NULL if unsafe.
   */
  private function destinationToInternalPath(Request $request, string $destination): ?string {
    $destination = trim($destination);
    if ($destination === '' || $destination === '/') {
      return '/';
    }
    if (str_starts_with($destination, '//')) {
      return NULL;
    }
    if (preg_match('#^https?://#i', $destination)) {
      $parts = parse_url($destination);
      if ($parts === FALSE) {
        return NULL;
      }
      $scheme = $parts['scheme'] ?? '';
      if ($scheme !== '' && !in_array($scheme, ['http', 'https'], TRUE)) {
        return NULL;
      }
      if (isset($parts['host']) && strcasecmp((string) $parts['host'], $request->getHost()) !== 0) {
        return NULL;
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
    if (str_starts_with($destination, '/')) {
      return $destination;
    }
    return NULL;
  }

  /**
   * Mirrors PostLoginHubRedirectSubscriber::shouldSkipHubWrap().
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
