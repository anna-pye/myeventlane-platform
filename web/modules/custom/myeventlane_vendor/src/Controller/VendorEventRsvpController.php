<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\RsvpStatsService;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Event RSVP controller.
 *
 * Displays real RSVP data from the database.
 */
final class VendorEventRsvpController extends VendorConsoleBaseController {

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly RsvpStatsService $rsvpStatsService,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly VendorEventTabsService $eventTabsService,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('myeventlane_vendor.service.rsvp_stats'),
      $container->get('entity_type.manager'),
      $container->get('myeventlane_vendor.service.event_tabs'),
    );
  }

  /**
   * Displays RSVPs for an event.
   */
  public function rsvps(NodeInterface $event): array {
    $this->assertEventOwnership($event);
    $this->assertStripeConnected();
    $tabs = $this->eventTabsService->getTabs($event, 'rsvps');

    // Use new API: getStatsForEvent returns ['total' => int, 'recent' => int].
    $rsvpStats = $this->rsvpStatsService->getStatsForEvent((int) $event->id());
    $summary = [
      'total' => $rsvpStats['total'] ?? 0,
      'recent' => $rsvpStats['recent'] ?? 0,
    ];

    // Daily series no longer available - use empty array.
    $series = [];

    // Get actual RSVP submissions.
    $rsvpList = $this->getRsvpList($event);

    $chart_data = [
      'event-rsvps' => [
        'type' => 'line',
        'labels' => [],
        'datasets' => [
          [
            'label' => 'RSVPs',
            'data' => [],
            'borderColor' => '#2563eb',
            'backgroundColor' => 'rgba(37, 99, 235, 0.12)',
          ],
        ],
      ],
    ];

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => $event->label() . ' — RSVPs',
      'tabs' => $tabs,
      'header_actions' => count($rsvpList) > 0 ? [
        [
          'label' => 'Export CSV',
          'url' => Url::fromRoute('myeventlane_rsvp.export_csv', ['event' => $event->id()])->toString(),
          'class' => 'mel-btn--secondary',
        ],
      ] : [],
      'body' => [
        '#theme' => 'myeventlane_vendor_event_rsvps',
        '#event' => $event,
        '#summary' => $summary,
        '#series' => $series,
        '#rsvps' => $rsvpList,
      ],
      '#attached' => [
        'drupalSettings' => [
          'vendorCharts' => $chart_data,
        ],
      ],
    ]);
  }

  /**
   * Gets RSVP submission list for an event.
   */
  private function getRsvpList(NodeInterface $event): array {
    $rsvps = [];
    $eventId = (int) $event->id();

    try {
      $rsvpStorage = $this->entityTypeManager->getStorage('rsvp_submission');
      $rsvpEntities = $rsvpStorage->loadByProperties([
        'event_id' => $eventId,
      ]);

      foreach ($rsvpEntities as $rsvp) {
        $rsvps[] = [
          'name' => $rsvp->get('name')->value ?? '',
          'email' => $rsvp->get('email')->value ?? '',
          'status' => ucfirst($rsvp->get('status')->value ?? 'pending'),
          'guests' => (int) ($rsvp->get('guests')->value ?? 0),
          'created' => date('M j, Y', (int) ($rsvp->get('created')->value ?? 0)),
        ];
      }

      // Sort by most recent first.
      usort($rsvps, fn($a, $b) => strtotime($b['created']) <=> strtotime($a['created']));
    }
    catch (\Exception) {
      // RSVP module may not be available.
    }

    return $rsvps;
  }

}
