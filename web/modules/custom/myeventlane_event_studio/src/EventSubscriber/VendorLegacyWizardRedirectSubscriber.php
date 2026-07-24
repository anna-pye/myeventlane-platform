<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Http\MelKernelAuthRouteSilencer;
use Drupal\myeventlane_core\VendorConsoleTrust;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects vendors from legacy wizard and ops URLs to Event Workspace.
 */
final class VendorLegacyWizardRedirectSubscriber implements EventSubscriberInterface {

  /**
   * Legacy event wizard step routes.
   *
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

  /**
   * Remaining vendor operational edit surfaces that now terminate in Studio.
   *
   * @var list<string>
   */
  private const VENDOR_LEGACY_OPERATION_ROUTES = [
    'myeventlane_vendor.manage_event.edit',
    'myeventlane_vendor.manage_event.design',
    'myeventlane_vendor.manage_event.content',
    'myeventlane_vendor.manage_event.tickets',
    'myeventlane_vendor.manage_event.checkout_questions',
    'myeventlane_vendor.manage_event.series',
    'myeventlane_vendor.manage_event.promote',
    'myeventlane_vendor.manage_event.payments',
    'myeventlane_vendor.manage_event.comms',
    'myeventlane_vendor.manage_event.advanced',
    'myeventlane_vendor.console.event_workspace',
    'myeventlane_vendor.console.event_overview',
    'myeventlane_vendor.console.event_orders',
    'myeventlane_vendor.console.event_order_view',
    'myeventlane_vendor.console.event_tickets',
    'myeventlane_vendor.console.event_rsvps',
    'myeventlane_rsvp.vendor_event_rsvps',
    'myeventlane_vendor.console.event_analytics',
    'myeventlane_vendor.console.event_settings',
    'myeventlane_vendor.console.event_unpublish',
    'myeventlane_vendor.console.event_publish',
    'myeventlane_vendor_comms.branding',
    'myeventlane_event_attendees.vendor_list',
    'myeventlane_event_attendees.legacy_vendor_event_attendees_path',
    'myeventlane_event_attendees.waitlist_manage',
    'myeventlane_tickets.event_tickets_types',
    'entity.mel_event_ticket_settings.edit_form',
    'myeventlane_tickets.event_tickets_settings',
    'myeventlane_tickets.event_tickets_groups',
    'myeventlane_tickets.event_tickets_groups_add',
    'myeventlane_tickets.event_tickets_groups_edit',
    'myeventlane_tickets.event_tickets_access_codes',
    'myeventlane_tickets.event_tickets_access_codes_add',
    'myeventlane_tickets.event_tickets_access_codes_edit',
    'myeventlane_tickets.event_tickets_widgets',
    'myeventlane_tickets.event_tickets_widgets_add',
    'myeventlane_tickets.event_tickets_widgets_edit',
    'myeventlane_tickets.event_ticket_type_edit',
  ];

  /**
   * Legacy check-in stacks that converge on Door Mode (VX2-05).
   *
   * @var list<string>
   */
  private const DOOR_MODE_REDIRECT_ROUTES = [
    'myeventlane_checkin.page',
    'myeventlane_checkin.list',
    'myeventlane_checkin.scan',
    'myeventlane_tickets.ticket_checkin',
    'myeventlane_rsvp.checkin_list',
    'myeventlane_rsvp.checkin_scan',
  ];

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 31],
      KernelEvents::EXCEPTION => ['onKernelException', 0],
      KernelEvents::RESPONSE => ['onKernelResponse', 0],
    ];
  }

  /**
   * Redirects legacy organiser routes on request.
   */
  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    if (MelKernelAuthRouteSilencer::shouldBypassAuthAccountRoutes($request)) {
      return;
    }
    $response = $this->redirectResponseForLegacyRequest($request);
    if ($response instanceof RedirectResponse) {
      $event->setResponse($response);
    }
  }

  /**
   * Redirects legacy organiser routes on access-denied exceptions.
   */
  public function onKernelException(ExceptionEvent $event): void {
    if (!$event->isMainRequest() || !$event->getThrowable() instanceof AccessDeniedHttpException) {
      return;
    }

    $request = $event->getRequest();
    if (MelKernelAuthRouteSilencer::shouldBypassAuthAccountRoutes($request)) {
      return;
    }
    $response = $this->redirectResponseForLegacyRequest($request);
    if ($response instanceof RedirectResponse) {
      $event->setResponse($response);
    }
  }

  /**
   * Redirects legacy organiser routes on 403 responses.
   */
  public function onKernelResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest() || $event->getResponse()->getStatusCode() !== 403) {
      return;
    }

    $request = $event->getRequest();
    if (MelKernelAuthRouteSilencer::shouldBypassAuthAccountRoutes($request)) {
      return;
    }
    $response = $this->redirectResponseForLegacyRequest($request);
    if ($response instanceof RedirectResponse) {
      $event->setResponse($response);
    }
  }

  /**
   * Builds a redirect response for a legacy organiser request, if applicable.
   */
  private function redirectResponseForLegacyRequest(Request $request): ?RedirectResponse {
    $route = (string) ($request->attributes->get('_route') ?? '');
    $redirect_routes = array_merge(
      self::WIZARD_STEP_ROUTES,
      self::VENDOR_LEGACY_OPERATION_ROUTES,
      self::DOOR_MODE_REDIRECT_ROUTES,
    );
    if (!in_array($route, $redirect_routes, TRUE)) {
      return NULL;
    }

    if ($this->currentUser->hasPermission('administer nodes')) {
      return NULL;
    }
    if ((int) $this->currentUser->id() === 1) {
      return NULL;
    }

    if ($this->currentUser->isAnonymous()) {
      return NULL;
    }

    $node = $this->resolveEventNode($request);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'event') {
      return NULL;
    }

    if (in_array($route, self::DOOR_MODE_REDIRECT_ROUTES, TRUE)) {
      // Door Mode requires vendor_console access. Check-in-only team accounts
      // must keep their legacy surfaces (access check-in / check in tickets).
      if (!VendorConsoleTrust::accountIsTrustedForVendorConsole($this->currentUser)) {
        $this->logger->notice('Vendor Door Mode redirect skipped (no console trust): from_route=@from event_id=@eid uid=@uid', [
          '@from' => $route,
          '@eid' => (string) $node->id(),
          '@uid' => (string) $this->currentUser->id(),
        ]);
        return NULL;
      }
      $url = Url::fromRoute('myeventlane_event_attendees.vendor_operations_door', [
        'node' => (int) $node->id(),
      ])->toString();
      $this->logger->notice('Vendor Door Mode redirect: from_route=@from event_id=@eid uid=@uid', [
        '@from' => $route,
        '@eid' => (string) $node->id(),
        '@uid' => (string) $this->currentUser->id(),
      ]);
      return new RedirectResponse($url, 302);
    }

    [$to_route, $params, $query] = $this->destinationForLegacyRoute($route, $node);
    $url = Url::fromRoute($to_route, $params, ['query' => $query])->toString();
    $this->logger->notice('Vendor legacy wizard redirect: from_route=@from to_route=@to event_id=@eid studio_selected=1 uid=@uid', [
      '@from' => $route,
      '@to' => $to_route,
      '@eid' => (string) $node->id(),
      '@uid' => (string) $this->currentUser->id(),
    ]);

    return new RedirectResponse($url, 302);
  }

  /**
   * Maps a legacy organiser route to a Convergence destination.
   *
   * @return array{0: string, 1: array<string, int|string>, 2: array<string, string>}
   *   Destination route name, parameters, and query arguments.
   */
  private function destinationForLegacyRoute(string $route, NodeInterface $node): array {
    $nid = (int) $node->id();
    $to_route = $this->sectionRouteForLegacyRoute($route);
    // Global Payments hub has no event parameter.
    if ($to_route === 'myeventlane_vendor.console.payouts') {
      return [$to_route, [], []];
    }
    $query = [];
    if (in_array($route, [
      'myeventlane_vendor.console.event_rsvps',
      'myeventlane_rsvp.vendor_event_rsvps',
    ], TRUE)) {
      $query['filter'] = 'rsvp';
    }
    if ($route === 'myeventlane_event_attendees.waitlist_manage') {
      $query['filter'] = 'waitlist';
    }
    return [$to_route, ['node' => $nid], $query];
  }

  /**
   * Maps a legacy route name to the Convergence destination route.
   */
  private function sectionRouteForLegacyRoute(string $route): string {
    return match ($route) {
      'myeventlane_event.wizard.basics',
      'myeventlane_event.wizard.when_where' => 'myeventlane_event_studio.workspace_information',
      'myeventlane_vendor.console.event_workspace',
      'myeventlane_vendor.console.event_overview' => 'myeventlane_event_studio.workspace',
      'myeventlane_event.wizard.tickets',
      'myeventlane_vendor.manage_event.tickets',
      'myeventlane_vendor.console.event_tickets',
      'myeventlane_tickets.event_tickets_types',
      'entity.mel_event_ticket_settings.edit_form',
      'myeventlane_tickets.event_tickets_settings',
      'myeventlane_tickets.event_tickets_groups',
      'myeventlane_tickets.event_tickets_groups_add',
      'myeventlane_tickets.event_tickets_groups_edit',
      'myeventlane_tickets.event_tickets_access_codes',
      'myeventlane_tickets.event_tickets_access_codes_add',
      'myeventlane_tickets.event_tickets_access_codes_edit',
      'myeventlane_tickets.event_tickets_widgets',
      'myeventlane_tickets.event_tickets_widgets_add',
      'myeventlane_tickets.event_tickets_widgets_edit',
      'myeventlane_tickets.event_ticket_type_edit' => 'myeventlane_event_studio.workspace_tickets',
      'myeventlane_event.wizard.details',
      'myeventlane_event.wizard.review',
      'myeventlane_vendor.manage_event.content' => 'myeventlane_event_studio.workspace_content',
      'myeventlane_vendor.manage_event.edit' => 'myeventlane_event_studio.workspace_information',
      'myeventlane_vendor.manage_event.design',
      'myeventlane_vendor_comms.branding' => 'myeventlane_event_studio.workspace_branding',
      'myeventlane_vendor.manage_event.checkout_questions' => 'myeventlane_event_studio.workspace_questions',
      'myeventlane_vendor.manage_event.series',
      'myeventlane_event_attendees.vendor_list',
      'myeventlane_event_attendees.legacy_vendor_event_attendees_path',
      'myeventlane_event_attendees.waitlist_manage',
      'myeventlane_vendor.console.event_rsvps',
      'myeventlane_rsvp.vendor_event_rsvps' => 'myeventlane_event_studio.workspace_attendees',
      'myeventlane_vendor.manage_event.promote' => 'myeventlane_boost.boost_page',
      'myeventlane_vendor.manage_event.comms' => 'myeventlane_event_studio.workspace_messaging',
      'myeventlane_vendor.console.event_orders',
      'myeventlane_vendor.console.event_order_view' => 'myeventlane_event_studio.workspace_orders',
      'myeventlane_vendor.console.event_analytics' => 'myeventlane_event_studio.workspace_analytics',
      // Align with ManageEventPlaceholderController: Payments → Payouts hub.
      'myeventlane_vendor.manage_event.payments' => 'myeventlane_vendor.console.payouts',
      'myeventlane_event.wizard.publish',
      'myeventlane_event.wizard.success',
      'myeventlane_vendor.manage_event.advanced',
      'myeventlane_vendor.console.event_settings',
      'myeventlane_vendor.console.event_unpublish',
      'myeventlane_vendor.console.event_publish' => 'myeventlane_event_studio.workspace_settings',
      default => 'myeventlane_event_studio.workspace',
    };
  }

  /**
   * Resolves an event node from legacy wizard or studio route parameters.
   *
   * Accepts either the `event` or `node` request attribute.
   */
  private function resolveEventNode(Request $request): ?NodeInterface {
    foreach (['event', 'node'] as $key) {
      $param = $request->attributes->get($key);
      if ($param instanceof NodeInterface && $param->bundle() === 'event') {
        return $param;
      }
      if (is_numeric($param) && (int) $param > 0) {
        $loaded = $this->entityTypeManager->getStorage('node')->load((int) $param);
        if ($loaded instanceof NodeInterface && $loaded->bundle() === 'event') {
          return $loaded;
        }
      }
    }
    if (preg_match('#^/vendor/events?/(\d+)(?:/|$)#', $request->getPathInfo(), $matches) === 1) {
      $loaded = $this->entityTypeManager->getStorage('node')->load((int) $matches[1]);
      if ($loaded instanceof NodeInterface && $loaded->bundle() === 'event') {
        return $loaded;
      }
    }
    return NULL;
  }

}
