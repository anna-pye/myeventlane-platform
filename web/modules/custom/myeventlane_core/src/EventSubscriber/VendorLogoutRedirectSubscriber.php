<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\EventSubscriber;

use Drupal\myeventlane_core\Service\DomainDetector;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends vendor-domain logouts to vendor /user/login instead of core front route.
 *
 * Core uses an absolute redirect to the front route (/home), which often resolves
 * to the public hostname and/or a cacheable page, so users still appear signed in
 * on the vendor host. Session destruction already ran; this only fixes Location.
 *
 * Do not add ?destination= to that URL: the login route redirects authenticated
 * users to destination; a queued /vendor/dashboard caused users to bounce straight
 * back to the console after logout.
 */
final class VendorLogoutRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly DomainDetector $domainDetector,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', -10]];
  }

  /**
   * Replaces the post-logout redirect when on the vendor hostname.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $route = (string) $request->attributes->get('_route');
    if (!in_array($route, ['user.logout', 'user.logout.confirm'], TRUE)) {
      return;
    }

    if (!$this->domainDetector->isVendorDomain()) {
      return;
    }

    $response = $event->getResponse();
    if (!$response instanceof RedirectResponse) {
      return;
    }

    try {
      $login = $this->domainDetector->buildDomainUrl('/user/login', 'vendor');
    }
    catch (\Throwable $e) {
      $this->logger->error('Vendor logout redirect skipped: @message', [
        '@message' => $e->getMessage(),
      ]);
      return;
    }

    $response->setTargetUrl($login);
  }

}
