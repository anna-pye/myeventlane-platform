<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class VendorDomainSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly DomainDetector $domainDetector,
    private readonly RouteMatchInterface $routeMatch,
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 0],
    ];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $path = $request->getPathInfo();
    $host = $request->getHost();


    // --------------------------------------------------
    // ENVIRONMENT DETECTION
    // --------------------------------------------------
    $environment = \Drupal::service('settings')->get('mel_environment', 'production');

    // --------------------------------------------------
    // LOCAL ENVIRONMENT GUARD (DDEV)
    // --------------------------------------------------
      if (str_ends_with($request->getHost(), '.ddev.site')) {
         // 🚨 Disable domain redirects locally to prevent session loss
         return;
      }

    // --------------------------------------------------
    // GLOBAL SAFETY GUARDS
    // --------------------------------------------------
    if (
      $path === '/user' ||
      str_starts_with($path, '/user/') ||
      $path === '/admin' ||
      str_starts_with($path, '/admin/') ||
      str_starts_with($path, '/system/') ||
      str_starts_with($path, '/ajax/') ||
      str_contains($path, '/form_action_')
    ) {
      return;
    }

    if ($request->isXmlHttpRequest()) {
      return;
    }

    $route_name = (string) $request->attributes->get('_route');
    if ($route_name === '') {
      return;
    }

    if (str_starts_with($route_name, 'myeventlane_vendor.onboard')) {
      return;
    }

    // --------------------------------------------------
    // ENVIRONMENT-AWARE DOMAIN LOGIC
    // --------------------------------------------------

    $is_vendor_domain = $this->domainDetector->isVendorDomain();

    // Prevent redirect loops on vendor domain
    if ($is_vendor_domain && str_starts_with($path, '/vendor')) {
      return;
    }

    // Config toggle
    $config = $this->configFactory->get('myeventlane_core.domain_settings');
    if (!$config->get('force_redirects')) {
      return;
    }

    // --------------------------------------------------
    // ROUTE DETECTION
    // --------------------------------------------------

    $is_vendor_route = str_starts_with($route_name, 'myeventlane_vendor.')
      || str_starts_with($path, '/vendor');

    $is_public_route = str_contains($route_name, 'view.')
      || str_starts_with($path, '/events');

    // --------------------------------------------------
    // REDIRECT: PUBLIC → VENDOR DOMAIN
    // --------------------------------------------------

    if ($is_vendor_route && !$is_vendor_domain) {

      if (!$this->currentUser->isAuthenticated()) {
        return;
      }

      try {
        $vendor_url = $this->domainDetector->buildDomainUrl($request->getRequestUri(), 'vendor');
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('vendor_domain_diagnostic')->error($e->getMessage());
        return;
      }

      if ($request->getUri() !== $vendor_url) {
        $this->loggerFactory->get('vendor_domain_diagnostic')->debug('Redirect to vendor domain', [
          'uid' => $this->currentUser->id(),
          'from' => $host,
          'to' => $vendor_url,
        ]);

        $event->setResponse(new TrustedRedirectResponse($vendor_url, 302));
      }

      return;
    }

    // --------------------------------------------------
    // REDIRECT: VENDOR → PUBLIC DOMAIN
    // --------------------------------------------------

    if ($is_public_route && $is_vendor_domain) {

      if (!$this->currentUser->isAuthenticated()) {
        return;
      }

      try {
        $public_url = $this->domainDetector->buildDomainUrl($request->getRequestUri(), 'public');
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('vendor_domain_diagnostic')->error($e->getMessage());
        return;
      }

      if ($request->getUri() !== $public_url) {
        $this->loggerFactory->get('vendor_domain_diagnostic')->debug('Redirect to public domain', [
          'uid' => $this->currentUser->id(),
          'from' => $host,
          'to' => $public_url,
        ]);

        $event->setResponse(new TrustedRedirectResponse($public_url, 302));
      }
    }
  }

}