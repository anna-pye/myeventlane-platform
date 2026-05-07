<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\EventSubscriber;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Restricts Event Studio routes to authenticated users with a vendor entity.
 *
 * Onboarding completion, Stripe, and profile details are not gated here;
 * publish-time validation handles those requirements.
 */
final class EventStudioVendorOnboardingGateSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly UserVendorMembershipQuery $userVendorMembershipQuery,
    private readonly RouteMatchInterface $routeMatch,
    private readonly LoggerInterface $logger,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 30],
    ];
  }

  public function onKernelRequest(RequestEvent $event): void {
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
    if (!($path === '/vendor' || str_starts_with($path, '/vendor/'))) {
      return;
    }

    $route_name = $this->routeMatch->getRouteName();
    if ($route_name === NULL) {
      return;
    }

    $is_studio = $route_name === 'myeventlane_event_studio.create'
      || str_starts_with($route_name, 'myeventlane_event_studio.edit')
      || $route_name === 'myeventlane_event_studio.governance_refresh'
      || $route_name === 'myeventlane_event_studio.governance_component'
      || $route_name === 'myeventlane_vendor.manage_event.edit'
      || $route_name === 'myeventlane_vendor.console.event_editor';
    if (!$is_studio) {
      return;
    }

    if ($this->currentUser->isAnonymous()) {
      $this->redirectAnonymousToLogin($event);
      return;
    }

    if ($this->currentUser->hasPermission('administer nodes')) {
      return;
    }

    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return;
    }

    $vendor_ids = $this->userVendorMembershipQuery->getVendorIdsForUser($uid);
    if ($vendor_ids === []) {
      $this->redirectToOnboardProfile($event, $uid, 'no_vendor_event_studio_fallback');
    }
  }

  /**
   * Sends anonymous users to login with return to the requested studio URL.
   */
  private function redirectAnonymousToLogin(RequestEvent $event): void {
    $request = $event->getRequest();
    $path = $request->getPathInfo();
    $query = $request->query->all();
    unset($query['destination']);
    $destination = $path;
    if ($query !== []) {
      $destination .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    try {
      if ($this->moduleHandler->moduleExists('myeventlane_auth')) {
        $auth_continue = Url::fromRoute('myeventlane_auth.mel_continue', [], [
          'query' => [
            'destination' => $destination,
            'mel_intent' => 'vendor_studio',
          ],
        ]);
        $url = Url::fromRoute('user.login', [], [
          'query' => ['destination' => $auth_continue->toString()],
        ])->toString();
      }
      else {
        $url = Url::fromRoute('user.login', [], [
          'query' => ['destination' => $destination],
        ])->toString();
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('EventStudioVendorOnboardingGate: login URL failed @message', [
        '@message' => $e->getMessage(),
      ]);
      return;
    }

    $this->logger->notice('EventStudioVendorOnboardingGate: anonymous redirect to login for studio path');
    $event->setResponse(new RedirectResponse($url, 302));
  }

  /**
   * Fallback when no vendor membership is visible (e.g. non-vendor domain);
   * primary handling is VendorPathMembershipGuardSubscriber.
   */
  private function redirectToOnboardProfile(RequestEvent $event, int $uid, string $reason): void {
    $request = $event->getRequest();
    $path = $request->getPathInfo();
    $query = $request->query->all();
    unset($query['destination']);
    $destination = $path;
    if ($query !== []) {
      $destination .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    try {
      $url = Url::fromRoute('myeventlane_vendor.onboard.profile', [], [
        'query' => ['destination' => $destination],
      ])->toString();
    }
    catch (\Throwable $e) {
      $this->logger->error('EventStudioVendorOnboardingGate: onboard profile URL failed uid=@uid reason=@reason @message', [
        '@uid' => (string) $uid,
        '@reason' => $reason,
        '@message' => $e->getMessage(),
      ]);
      return;
    }

    $this->logger->notice('EventStudioVendorOnboardingGate: redirect uid=@uid reason=@reason target_route=myeventlane_vendor.onboard.profile', [
      '@uid' => (string) $uid,
      '@reason' => $reason,
    ]);

    $event->setResponse(new RedirectResponse($url, 302));
  }

}
