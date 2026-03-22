<?php

declare(strict_types=1);

namespace Drupal\myeventlane_growth\EventSubscriber;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_growth\Service\GrowthOrganiserSignals;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Detects lightweight commercial intent signals from vendor routes.
 */
final class GrowthVendorRouteSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly GrowthOrganiserSignals $signals,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 20],
    ];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $route = $event->getRequest()->attributes->get('_route');
    if (!is_string($route)) {
      return;
    }
    if ($route === 'myeventlane_pro.cancel_request' || $route === 'myeventlane_pro.cancel_confirm') {
      if ($this->currentUser->isAuthenticated()) {
        $this->signals->recordCancelFlowVisit((int) $this->currentUser->id());
      }
    }
  }

}
