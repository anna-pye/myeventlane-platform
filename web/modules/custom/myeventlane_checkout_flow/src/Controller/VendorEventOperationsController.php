<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_checkout_flow\Service\MelAttendeeOperationsAccessInterface;
use Drupal\myeventlane_checkout_flow\Service\MelDoorCheckinValidateService;
use Drupal\myeventlane_checkout_flow\Service\MelVenueOperationsViewModelBuilder;
use Drupal\myeventlane_core\GovernedOperationalTemplates;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Single operational venue surface for an event (live operations slice).
 *
 * Registered under route {@code myeventlane_event_attendees.vendor_operations}
 * while living in {@code myeventlane_checkout_flow} to avoid a hard module
 * dependency cycle with {@code myeventlane_event_attendees}.
 */
final class VendorEventOperationsController extends VendorConsoleBaseController {

  private const DOOR_CSRF_ID = 'mel_door_checkin';

  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly MelVenueOperationsViewModelBuilder $venueOperationsViewModel,
    private readonly VendorEventTabsService $eventTabsService,
    private readonly GovernedOperationalTemplates $governedOperationalTemplates,
    private readonly MelAttendeeOperationsAccessInterface $attendeeOperationsAccess,
    private readonly MelDoorCheckinValidateService $doorCheckinValidate,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('myeventlane_checkout_flow.venue_operations_view_model'),
      $container->get('myeventlane_vendor.service.event_tabs'),
      $container->get('myeventlane_surface.governed_operational_templates'),
      $container->get('myeventlane_checkout_flow.attendee_operations_access'),
      $container->get('myeventlane_checkout_flow.door_checkin_validate'),
      $container->get('csrf_token'),
      $container->get('module_handler'),
    );
  }

  /**
   * Renders the canonical venue operations page.
   */
  public function page(NodeInterface $node): array {
    if ($node->bundle() !== 'event') {
      throw new AccessDeniedHttpException();
    }
    $this->assertEventOwnership($node);

    $tabs = $this->eventTabsService->getTabs($node, 'operations');
    $vm = $this->venueOperationsViewModel->build($node);

    $empty = NULL;
    if (($vm['metrics']['total_attendees'] ?? 0) === 0) {
      $empty = $this->buildGovernedEmptyState();
    }

    $content = [
      '#theme' => 'mel_venue_operations',
      '#event' => $vm['event'],
      '#hero' => $vm['hero'],
      '#metrics' => $vm['metrics'],
      '#attendee_rows' => $vm['attendee_rows'],
      '#readiness_breakdown' => $vm['readiness_breakdown'],
      '#recent_activity' => $vm['recent_activity'],
      '#links' => $vm['links'],
      '#search' => $vm['search'],
      '#operational_actions' => $vm['operational_actions'],
      '#mel_venue_operations_empty' => $empty,
      '#csrf' => $vm['csrf'] ?? [],
      '#attached' => [
        'library' => ['myeventlane_checkout_flow/mel_vendor_venue_operations'],
      ],
      '#cache' => $vm['#cache'] ?? [],
    ];

    return $this->buildVendorPage('mel_event_workspace', [
      'event' => $node,
      'tabs' => $tabs,
      'actions' => [],
      'meta' => NULL,
      'sidebar' => NULL,
      'content' => $content,
    ]);
  }

  /**
   * Canonical vendor Door Mode (scanner + manual) inside the event workspace.
   */
  public function doorPage(NodeInterface $node): array {
    if ($node->bundle() !== 'event') {
      throw new AccessDeniedHttpException();
    }
    $this->assertEventOwnership($node);
    $checkInAccess = $this->attendeeOperationsAccess->canCheckInAttendees($node, $this->currentUser->getAccount());
    if (!$checkInAccess->isAllowed()) {
      throw new AccessDeniedHttpException();
    }

    $eventId = (int) $node->id();
    $tabs = $this->eventTabsService->getTabs($node, 'operations');

    $validateUrl = Url::fromRoute(
      'myeventlane_event_attendees.vendor_operations_door_validate',
      ['node' => $eventId],
      ['absolute' => TRUE],
    )->toString();
    $searchUrl = $validateUrl;

    $operationsUrl = Url::fromRoute(
      'myeventlane_event_attendees.vendor_operations',
      ['node' => $eventId],
    )->toString();

    $csrf = $this->csrfToken->get(self::DOOR_CSRF_ID);

    $libraries = [
      'myeventlane_event/door_checkin',
      'myeventlane_checkout_flow/mel_vendor_door_checkin',
    ];
    if ($this->moduleHandler->moduleExists('myeventlane_tickets')) {
      $libraries[] = 'myeventlane_tickets/checkin_scanner';
    }

    $doorContext = $this->venueOperationsViewModel->buildDoorHeaderContext($node);

    $content = [
      '#theme' => 'mel_vendor_door_checkin',
      '#event' => $node,
      '#event_id' => $eventId,
      '#validate_url' => $validateUrl,
      '#search_url' => $searchUrl,
      '#csrf_token' => $csrf,
      '#operations_url' => $operationsUrl,
      '#door_context' => $doorContext,
      '#attached' => [
        'library' => $libraries,
        'drupalSettings' => [
          'melDoorCheckin' => [
            'validateUrl' => $validateUrl,
            'searchUrl' => $searchUrl,
            'csrfToken' => $csrf,
            'eventId' => $eventId,
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node:' . $eventId],
      ],
    ];

    return $this->buildVendorPage('mel_event_workspace', [
      'event' => $node,
      'tabs' => $tabs,
      'actions' => [],
      'meta' => NULL,
      'sidebar' => NULL,
      'content' => $content,
    ]);
  }

  /**
   * JSON validate/search for vendor Door Mode (same pipeline as public door).
   */
  public function doorValidate(NodeInterface $node, Request $request): JsonResponse {
    if ($node->bundle() !== 'event') {
      return new JsonResponse(['status' => 'error', 'message' => 'Invalid event.'], 400);
    }
    $this->assertEventOwnership($node);
    $checkInAccess = $this->attendeeOperationsAccess->canCheckInAttendees($node, $this->currentUser->getAccount());
    if (!$checkInAccess->isAllowed()) {
      return new JsonResponse(['status' => 'error', 'message' => 'Access denied.'], 403);
    }

    return $this->doorCheckinValidate->handleRequest(
      $node,
      $request,
      $this->currentUser->getAccount(),
      $this->csrfToken,
      self::DOOR_CSRF_ID,
    );
  }

  /**
   * @return array<string, mixed>|null
   */
  private function buildGovernedEmptyState(): ?array {
    try {
      return $this->governedOperationalTemplates->vendorAttendeeOperationsNoAttendeesYet();
    }
    catch (\Throwable $e) {
      \Drupal::logger('myeventlane_checkout_flow')->warning('Venue operations empty state failed: @msg.', [
        '@msg' => $e->getMessage(),
      ]);
    }
    return NULL;
  }

}
