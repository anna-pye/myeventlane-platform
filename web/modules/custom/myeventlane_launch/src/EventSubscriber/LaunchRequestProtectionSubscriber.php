<?php

declare(strict_types=1);

namespace Drupal\myeventlane_launch\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\myeventlane_launch\Service\CheckoutIdempotencyGuard;
use Drupal\myeventlane_launch\Service\MELMonitoringService;
use Drupal\myeventlane_launch\Service\PlatformRateLimiter;
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

    $request = $event->getRequest();
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

    if ($routeName === 'user.login' && $method === 'POST') {
      $this->rateLimiter->enforce(
        'myeventlane_launch.login_attempt',
        $this->rateLimiter->buildIdentifier($request, 'login'),
        5,
        60
      );
      return;
    }

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
      $this->rateLimiter->enforce(
        'myeventlane_launch.event_create',
        $this->rateLimiter->buildIdentifier($request, 'event_create'),
        10,
        60
      );
    }
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
