<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects Event Studio routes when organiser setup is incomplete.
 *
 * Reuses OnboardingManager + UserVendorMembershipQuery (same rules as
 * CreateEventGatewayController) without duplicating destination policy.
 */
final class EventStudioVendorOnboardingGateSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly UserVendorMembershipQuery $userVendorMembershipQuery,
    private readonly OnboardingManager $onboardingManager,
    private readonly RouteMatchInterface $routeMatch,
    private readonly LoggerInterface $logger,
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

    $route_name = $this->routeMatch->getRouteName();
    if ($route_name === NULL) {
      return;
    }

    $is_studio = $route_name === 'myeventlane_event_studio.create'
      || str_starts_with($route_name, 'myeventlane_event_studio.edit');
    if (!$is_studio) {
      return;
    }

    if ($this->currentUser->isAnonymous()) {
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
      $this->redirectToGateway($event, $uid, 'no_vendor');
      return;
    }

    $state = $this->onboardingManager->loadVendorStateByUid($uid);
    $is_complete = $state !== NULL
      && $state->getStage() === 'complete'
      && $state->isCompleted();

    if (!$is_complete) {
      $this->redirectToGateway($event, $uid, 'onboarding_incomplete');
    }
  }

  /**
   * Sends the user to the create-event gateway with a destination back.
   */
  private function redirectToGateway(RequestEvent $event, int $uid, string $reason): void {
    $request = $event->getRequest();
    $destination = $request->getRequestUri();

    try {
      $url = Url::fromRoute('myeventlane_vendor.create_event_gateway', [], [
        'query' => ['destination' => $destination],
      ])->toString();
    }
    catch (\Throwable $e) {
      $this->logger->error('EventStudioVendorOnboardingGate: gateway URL failed uid=@uid reason=@reason @message', [
        '@uid' => (string) $uid,
        '@reason' => $reason,
        '@message' => $e->getMessage(),
      ]);
      return;
    }

    $this->logger->notice('EventStudioVendorOnboardingGate: redirect uid=@uid reason=@reason', [
      '@uid' => (string) $uid,
      '@reason' => $reason,
    ]);

    $event->setResponse(new RedirectResponse($url, 302));
  }

}
