<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\EventSubscriber;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_core\Service\VendorLoginDestinationNormalizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Avoids core's "authenticated on /user/login" redirect when heading to vendor.
 *
 * Anonymous vendor /user/login is sent to public /user/login with a vendor
 * destination. A logged-in customer cannot access user.login (403); core then
 * sends them to /user/* and on to /my-account. This subscriber sends them
 * through a logout hop first so they can sign in as an organiser.
 */
final class PublicVendorLoginConflictSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly DomainDetector $domainDetector,
    private readonly AccountProxyInterface $currentUser,
    private readonly VendorLoginDestinationNormalizer $normalizer,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onRequest', 48]];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || \PHP_SAPI === 'cli') {
      return;
    }

    $request = $event->getRequest();
    // Intentionally no MelKernelAuthRouteSilencer::shouldBypassAuthAccountRoutes():
    // this subscriber must run GET /user/login for authenticated conflict handling.
    if ($request->getMethod() !== 'GET') {
      return;
    }

    if (!$this->domainDetector->isPublicDomain()) {
      return;
    }

    if ($request->getPathInfo() !== '/user/login') {
      return;
    }

    if (!$this->currentUser->isAuthenticated()) {
      return;
    }

    if ($this->currentUser->hasPermission('access vendor console')) {
      return;
    }

    $raw = $request->query->get('destination');
    if (!is_string($raw) || $raw === '') {
      return;
    }

    $trusted = $this->normalizer->trustedOrganiserDestination($raw);
    if ($trusted === NULL) {
      return;
    }

    try {
      $switch = Url::fromRoute('myeventlane_core.organiser_login_switch', [], [
        'query' => ['destination' => $trusted],
      ])->setAbsolute()->toString();
    }
    catch (\Throwable $e) {
      $this->logger->error('Public vendor login conflict redirect failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return;
    }

    $this->logger->notice('Redirecting authenticated user to organiser login switch for vendor destination.');

    $event->setResponse(new TrustedRedirectResponse($switch, 302));
  }

}
