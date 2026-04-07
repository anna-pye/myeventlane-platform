<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Optional: redirects anonymous vendor-console traffic to the auth-domain SSO login.
 */
final class VendorSsoRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerChannelInterface $logger,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onKernelRequest', 31]];
  }

  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $config = $this->configFactory->get('myeventlane_auth.settings');
    if (!(bool) $config->get('vendor_sso_redirect_enabled')) {
      return;
    }
    if ($this->currentUser->isAuthenticated()) {
      return;
    }
    $request = $event->getRequest();
    $host = strtolower($request->getHost());
    if (!$this->isVendorHost($host)) {
      return;
    }
    $path = $request->getPathInfo();
    $callbackPath = (string) $config->get('vendor_sso_callback_path');
    if ($callbackPath !== '' && str_starts_with($path, $callbackPath)) {
      return;
    }
    if (!str_starts_with($path, '/vendor')) {
      return;
    }
    if (str_starts_with($path, '/core/') || str_starts_with($path, '/sites/')) {
      return;
    }

    $authBase = rtrim((string) $config->get('auth_base_url'), '/');
    if ($authBase === '') {
      $this->logger->error('vendor_sso_redirect_enabled is TRUE but auth_base_url is empty.');
      return;
    }
    $clientId = (string) $config->get('vendor_sso_client_id');
    if ($clientId === '') {
      return;
    }

    $callbackUrl = $request->getSchemeAndHttpHost() . ($callbackPath !== '' ? $callbackPath : '/vendor/sso/callback');
    $state = bin2hex(random_bytes(24));
    $session = $request->getSession();
    $session->set('myeventlane_auth.oauth_state', $state);
    $session->set('myeventlane_auth.oauth_redirect_uri', $callbackUrl);

    $query = [
      'response_type' => 'code',
      'client_id' => $clientId,
      'redirect_uri' => $callbackUrl,
      'state' => $state,
    ];
    $loginUrl = $authBase . '/auth/login?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    $this->logger->notice('Redirecting anonymous vendor request to auth SSO login.');

    $event->setResponse(new RedirectResponse($loginUrl, 302));
  }

  private function isVendorHost(string $host): bool {
    $config = $this->configFactory->get('myeventlane_auth.settings');
    $extra = $config->get('vendor_sso_redirect_hosts') ?: [];
    if (is_array($extra)) {
      foreach ($extra as $h) {
        if (is_string($h) && strtolower(trim($h)) === $host) {
          return TRUE;
        }
      }
    }
    if ($this->moduleHandler->moduleExists('myeventlane_core')) {
      $vendorUrl = (string) $this->configFactory->get('myeventlane_core.domain_settings')->get('vendor_domain');
      $vh = $vendorUrl !== '' ? parse_url($vendorUrl, PHP_URL_HOST) : NULL;
      if (is_string($vh) && strtolower($vh) === $host) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
