<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\EventSubscriber;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_event\Service\EventPasscodeAccess;
use Drupal\myeventlane_event\Service\PublicEventVisibility;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects anonymous/unprivileged users to the passcode form for locked events.
 *
 * Runs on event canonical routes only. Does not affect admin, vendor edit,
 * booking, or API routes.
 */
final class EventPasscodeGateSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly PublicEventVisibility $publicVisibility,
    private readonly EventPasscodeAccess $passcodeAccess,
    private readonly AccountProxyInterface $currentUser,
    private readonly DomainDetector $domainDetector,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 30],
    ];
  }

  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $route_name = (string) $request->attributes->get('_route', '');

    if ($route_name !== 'entity.node.canonical') {
      return;
    }

    $node = $request->attributes->get('node');
    if (!$node instanceof NodeInterface || $node->bundle() !== 'event') {
      return;
    }

    if (!$this->publicVisibility->isPasscodeProtected($node)) {
      return;
    }

    if (!$node->isPublished()) {
      return;
    }

    if ($this->publicVisibility->isDirectlyViewable($node, $this->currentUser, $request)) {
      return;
    }

    if (!$this->passcodeAccess->requiresPasscode($node)) {
      return;
    }

    $relative_path = Url::fromRoute('myeventlane_event.passcode_gate', [
      'node' => $node->id(),
    ])->toString();

    $target = $this->domainDetector->publicUrl($relative_path);

    if (str_starts_with($target, 'http://') || str_starts_with($target, 'https://')) {
      $response = new TrustedRedirectResponse($target, 302);
      $cacheable_metadata = new CacheableMetadata();
      $cacheable_metadata->setCacheMaxAge(0);
      $response->addCacheableDependency($cacheable_metadata);
      $event->setResponse($response);
    }
    else {
      $event->setResponse(new RedirectResponse($target, 302));
    }
  }

}
