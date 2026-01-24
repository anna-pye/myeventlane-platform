<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\EventSubscriber;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Forces session initialization on vendor domain.
 *
 * This ensures the session cookie is written for the vendor host,
 * enabling cross-subdomain authentication.
 */
final class VendorSessionBootstrapSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly SessionManagerInterface $sessionManager,
    private readonly DomainDetector $domainDetector,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 5],
    ];
  }

  /**
   * Forces session initialization on vendor domain requests.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    if (!$this->domainDetector->isVendorDomain()) {
      return;
    }

    if ($this->currentUser->isAnonymous()) {
      return;
    }

    // 🔑 Force session write on vendor domain.
    if (!$this->sessionManager->isStarted()) {
      $this->sessionManager->start();
    }
  }

}
