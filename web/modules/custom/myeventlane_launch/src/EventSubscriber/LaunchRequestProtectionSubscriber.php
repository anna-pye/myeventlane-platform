<?php

declare(strict_types=1);

namespace Drupal\myeventlane_launch\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_launch\Service\CheckoutIdempotencyGuard;
use Drupal\myeventlane_launch\Service\MELMonitoringService;
use Drupal\myeventlane_core\Http\MelKernelAuthRouteSilencer;
use Drupal\myeventlane_launch\Service\PlatformRateLimiter;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces launch hardening request protections.
 */
final class LaunchRequestProtectionSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly PlatformRateLimiter $rateLimiter,
    private readonly CheckoutIdempotencyGuard $checkoutGuard,
    private readonly MELMonitoringService $monitoringService,
    private readonly LoggerInterface $logger,
    private readonly ?AccountProxyInterface $currentUser = NULL,
    private readonly ?UserVendorMembershipQuery $userVendorMembershipQuery = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
    ];
  }

  /**
   * Applies route-specific rate limits and checkout idempotency checks.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    if ($event->hasResponse()) {
      return;
    }

    $path = $event->getRequest()->getPathInfo() ?: '/';
    if (str_starts_with($path, '/vendor/stripe') || str_starts_with($path, '/stripe/')) {
      return;
    }

    $request = $event->getRequest();
    if (MelKernelAuthRouteSilencer::shouldBypassAuthAccountRoutes($request)) {
      return;
    }
    $routeName = (string) $request->attributes->get('_route');
    $method = $request->getMethod();

    if ($routeName === 'commerce_checkout.form' && $method === 'POST') {
      $this->rateLimiter->enforce(
        'myeventlane_launch.checkout_attempt',
        $this->rateLimiter->buildIdentifier($request, 'checkout'),
        10,
        60
      );
      $this->enforceCheckoutSession($request->attributes->get('commerce_order'), $request->hasSession() ? $request->getSession() : NULL);
      return;
    }

    // Do not rate-limit user.login POST here. Core already flood-controls failed
    // logins (user.failed_login_*); a pre-form cap counted every POST and locked
    // out legitimate users on shared IPs / retries without adding real security.

    if ($routeName === 'myeventlane_rsvp.public_rsvp_form' && $method === 'POST') {
      $this->rateLimiter->enforce(
        'myeventlane_launch.rsvp_submit',
        $this->rateLimiter->buildIdentifier($request, 'rsvp'),
        20,
        60
      );
      return;
    }

    if (in_array($routeName, ['myeventlane_event_studio.create', 'myeventlane_vendor.console.events_add'], TRUE)) {
      if ($this->shouldSkipEventCreateRateLimit($routeName)) {
        return;
      }
      $this->rateLimiter->enforce(
        'myeventlane_launch.event_create',
        $this->rateLimiter->buildIdentifier($request, 'event_create'),
        10,
        60
      );
    }
  }

  /**
   * Skips the event-create cap when the user has no linked vendor (onboarding),
   * so failed /vendor/* attempts do not block legitimate progress.
   */
  private function shouldSkipEventCreateRateLimit(string $routeName): bool {
    if ($this->currentUser === NULL || $this->userVendorMembershipQuery === NULL) {
      return FALSE;
    }
    if ($this->currentUser->isAnonymous()) {
      return FALSE;
    }
    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return FALSE;
    }
    $ids = $this->userVendorMembershipQuery->getVendorIdsForUser($uid);
    if ($ids === []) {
      $this->logger->notice('Launch event_create rate limit skipped: uid=@uid reason=no_vendor_membership route=@route', [
        '@uid' => (string) $uid,
        '@route' => $routeName,
      ]);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Blocks checkout requests when the session is already completed.
   */
  private function enforceCheckoutSession(mixed $orderCandidate, ?\Symfony\Component\HttpFoundation\Session\SessionInterface $session): void {
    if (!$orderCandidate instanceof OrderInterface) {
      return;
    }

    $sessionId = $this->checkoutGuard->buildSessionId($orderCandidate, $session);
    if (!$this->checkoutGuard->isCompleted($sessionId)) {
      return;
    }

    $this->monitoringService->monitorCheckoutFailure([
      'order_id' => (int) $orderCandidate->id(),
      'session_id' => $sessionId,
      'reason' => 'duplicate_checkout_request',
    ]);
    $this->logger->warning('Blocked duplicate checkout request for order {order_id}.', [
      'order_id' => (int) $orderCandidate->id(),
    ]);
    $this->checkoutGuard->assertNotCompleted($sessionId);
  }

}
