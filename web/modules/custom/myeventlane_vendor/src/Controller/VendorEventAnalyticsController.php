<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_commerce\Service\TicketTierAnalyticsService;
use Drupal\myeventlane_event\Service\TicketTierLifecycleService;
use Drupal\myeventlane_pro\Service\ProActiveResolver;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\MetricsAggregator;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Event analytics controller.
 *
 * Displays real analytics data from Commerce and RSVP.
 */
final class VendorEventAnalyticsController extends VendorConsoleBaseController {

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MetricsAggregator $metricsAggregator,
    private readonly VendorEventTabsService $eventTabsService,
    private readonly TicketTierLifecycleService $ticketTierLifecycle,
    private readonly TicketTierAnalyticsService $ticketTierAnalytics,
    private readonly ?ProActiveResolver $proActiveResolver = NULL,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  /**
   * Displays analytics for an event.
   */
  public function analytics(NodeInterface $event): array {
    $this->assertEventOwnership($event);
    if (!$this->proActiveResolver) {
      throw new AccessDeniedHttpException('Pro resolver service is unavailable.');
    }
    $user = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
    if (!$user instanceof UserInterface || !$this->proActiveResolver->isUserProActive($user)) {
      throw new AccessDeniedHttpException('Pro subscription is required.');
    }
    $tabs = $this->eventTabsService->getTabs($event, 'analytics');
    $charts = $this->metricsAggregator->getEventCharts($event);
    $overview = $this->metricsAggregator->getEventOverview($event);

    $ticketRows = array_values(array_filter(
      $this->ticketTierLifecycle->loadOrderedTicketsForEvent($event),
      static fn ($entity) => $entity instanceof TicketTypeInterface
    ));
    $ticket_tier_rollup = $this->ticketTierAnalytics->buildEventTierRollup($event, $ticketRows);
    $ticket_tier_rollup['tier_row_count'] = count($ticketRows);

    $chart_data = [
      'event-sales' => [
        'type' => 'line',
        'labels' => array_column($charts['sales'] ?? [], 'date'),
        'datasets' => [
          [
            'label' => 'Sales',
            'data' => array_column($charts['sales'] ?? [], 'amount'),
            'borderColor' => '#2563eb',
            'backgroundColor' => 'rgba(37, 99, 235, 0.12)',
          ],
        ],
      ],
      'event-rsvps' => [
        'type' => 'line',
        'labels' => array_column($charts['rsvps'] ?? [], 'date'),
        'datasets' => [
          [
            'label' => 'RSVPs',
            'data' => array_column($charts['rsvps'] ?? [], 'rsvps'),
            'borderColor' => '#10b981',
            'backgroundColor' => 'rgba(16, 185, 129, 0.12)',
          ],
        ],
      ],
    ];

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => $event->label() . ' — Analytics',
      'tabs' => $tabs,
      'body' => [
        '#theme' => 'myeventlane_vendor_event_analytics',
        '#event' => $event,
        '#charts' => $charts,
        '#overview' => $overview,
        '#ticket_tier_rollup' => $ticket_tier_rollup,
      ],
      '#attached' => [
        'drupalSettings' => [
          'vendorCharts' => $chart_data,
        ],
      ],
    ]);
  }

}
