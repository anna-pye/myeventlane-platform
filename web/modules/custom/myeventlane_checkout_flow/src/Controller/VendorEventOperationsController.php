<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Controller;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_checkout_flow\Service\MelVenueOperationsViewModelBuilder;
use Drupal\myeventlane_core\GovernedOperationalTemplates;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Single operational venue surface for an event (live operations slice).
 *
 * Registered under route {@code myeventlane_event_attendees.vendor_operations}
 * while living in {@code myeventlane_checkout_flow} to avoid a hard module
 * dependency cycle with {@code myeventlane_event_attendees}.
 */
final class VendorEventOperationsController extends VendorConsoleBaseController {

  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly MelVenueOperationsViewModelBuilder $venueOperationsViewModel,
    private readonly VendorEventTabsService $eventTabsService,
    private readonly GovernedOperationalTemplates $governedOperationalTemplates,
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
