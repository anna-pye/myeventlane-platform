<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Http\MelKernelAuthRouteSilencer;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends vendors off legacy step-wizard URLs to Event Studio; staff may keep wizard.
 */
final class VendorLegacyWizardRedirectSubscriber implements EventSubscriberInterface {

  /**
   * @var list<string>
   */
  private const WIZARD_STEP_ROUTES = [
    'myeventlane_event.wizard.basics',
    'myeventlane_event.wizard.when_where',
    'myeventlane_event.wizard.tickets',
    'myeventlane_event.wizard.details',
    'myeventlane_event.wizard.review',
    'myeventlane_event.wizard.publish',
    'myeventlane_event.wizard.success',
  ];

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onKernelRequest', 40]];
  }

  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    if (MelKernelAuthRouteSilencer::shouldBypassAuthAccountRoutes($request)) {
      return;
    }
    $route = (string) ($request->attributes->get('_route') ?? '');
    if (!in_array($route, self::WIZARD_STEP_ROUTES, TRUE)) {
      return;
    }

    if ($this->currentUser->hasPermission('administer nodes')) {
      return;
    }

    if ($this->currentUser->isAnonymous()) {
      return;
    }

    $event_param = $request->attributes->get('event');
    $node = NULL;
    if ($event_param instanceof NodeInterface) {
      $node = $event_param;
    }
    elseif (is_numeric($event_param) && (int) $event_param > 0) {
      $loaded = $this->entityTypeManager->getStorage('node')->load((int) $event_param);
      $node = $loaded instanceof NodeInterface ? $loaded : NULL;
    }

    if (!$node instanceof NodeInterface || $node->bundle() !== 'event') {
      return;
    }

    $url = Url::fromRoute('myeventlane_event_studio.edit', ['node' => $node->id()])->toString();
    $this->logger->notice('Vendor legacy wizard redirect: from_route=@from to_route=myeventlane_event_studio.edit event_id=@eid studio_selected=1 uid=@uid', [
      '@from' => $route,
      '@eid' => (string) $node->id(),
      '@uid' => (string) $this->currentUser->id(),
    ]);

    $event->setResponse(new RedirectResponse($url, 302));
  }

}
