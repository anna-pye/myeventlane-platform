<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\EventSubscriber;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\myeventlane_core\Service\DomainDetector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event subscriber to redirect admin domain root to admin dashboard.
 */
final class AdminDomainRedirectSubscriber implements EventSubscriberInterface {

  /**
   * Constructs an AdminDomainRedirectSubscriber object.
   *
   * @param \Drupal\myeventlane_core\Service\DomainDetector $domainDetector
   *   The domain detector service.
   */
  public function __construct(
    private readonly DomainDetector $domainDetector,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    $events[KernelEvents::REQUEST][] = ['onRequest', 30];
    return $events;
  }

  /**
   * Handles the request event.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event): void {
    // Skip for sub-requests, CLI, and non-admin domains.
    if (!$event->isMainRequest() || PHP_SAPI === 'cli' || !$this->domainDetector->isAdminDomain()) {
      return;
    }

    $request = $event->getRequest();
    $path = $request->getPathInfo();

    // Redirect root path to admin dashboard.
    // Also handle front page route matches.
    if ($path === '/' || $path === '' || $request->attributes->get('_route') === '<front>') {
      // If user is anonymous, redirect to login first, then to dashboard.
      $current_user = \Drupal::currentUser();
      if ($current_user->isAnonymous()) {
        $login_url = $this->domainDetector->buildDomainUrl('/user/login?destination=/admin/myeventlane', 'admin');
        $response = new TrustedRedirectResponse($login_url, 302);
        $event->setResponse($response);
        return;
      }

      $redirect_url = $this->domainDetector->buildDomainUrl('/admin/myeventlane', 'admin');
      $response = new TrustedRedirectResponse($redirect_url, 302);
      $event->setResponse($response);
    }
  }

}
