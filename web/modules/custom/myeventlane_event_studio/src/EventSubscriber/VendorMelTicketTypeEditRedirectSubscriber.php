<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\mel_ticket\Entity\TicketType;
use Drupal\myeventlane_core\Http\MelKernelAuthRouteSilencer;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends organiser ticket-type edit URLs to Event Studio; staff with administer bypass.
 */
final class VendorMelTicketTypeEditRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onRequest', 35]];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    if ($this->currentUser->hasPermission('administer mel_ticket_type entities')) {
      return;
    }

    $request = $event->getRequest();
    if (MelKernelAuthRouteSilencer::shouldBypassAuthAccountRoutes($request)) {
      return;
    }

    $route = (string) ($request->attributes->get('_route') ?? '');
    if ($route !== 'entity.mel_ticket_type.edit_form') {
      return;
    }

    if ($this->currentUser->isAnonymous()) {
      return;
    }

    $ticket = $this->resolveTicketTypeFromRequest($request);
    if (!$ticket instanceof TicketType) {
      return;
    }

    if ($ticket->get('event')->isEmpty()) {
      $this->logger->warning('Vendor mel_ticket_type edit blocked: ticket has no event reference tid=@tid uid=@uid', [
        '@tid' => (string) $ticket->id(),
        '@uid' => (string) $this->currentUser->id(),
      ]);
      throw new AccessDeniedHttpException('Edit this ticket type from Event Studio when it is attached to an event.');
    }

    $event_nid = (int) $ticket->get('event')->target_id;
    if ($event_nid < 1) {
      throw new AccessDeniedHttpException('Edit ticket types in Event Studio.');
    }

    $url = Url::fromRoute('myeventlane_event_studio.workspace_tickets', ['node' => $event_nid])->toString();
    $this->logger->notice('Vendor mel_ticket_type edit redirect: tid=@tid event_id=@eid uid=@uid', [
      '@tid' => (string) $ticket->id(),
      '@eid' => (string) $event_nid,
      '@uid' => (string) $this->currentUser->id(),
    ]);

    $event->setResponse(new RedirectResponse($url, 302));
  }

  private function resolveTicketTypeFromRequest(Request $request): ?TicketType {
    $raw = $request->attributes->get('mel_ticket_type');
    if ($raw instanceof TicketType) {
      return $raw;
    }
    if (is_numeric($raw)) {
      $loaded = $this->entityTypeManager->getStorage('mel_ticket_type')->load((int) $raw);
      return $loaded instanceof TicketType ? $loaded : NULL;
    }
    return NULL;
  }

}
