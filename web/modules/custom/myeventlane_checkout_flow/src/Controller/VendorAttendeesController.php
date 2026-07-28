<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Controller;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_checkout_flow\Service\AttendeeEventStatsService;
use Drupal\myeventlane_checkout_flow\Service\MelAttendeeCheckinManager;
use Drupal\myeventlane_checkout_flow\Service\MelAttendeeOperationsPresenter;
use Drupal\myeventlane_core\Service\TicketLabelResolver;
use Drupal\myeventlane_core\GovernedOperationalTemplates;
use Drupal\myeventlane_event_attendees\Service\AttendanceManagerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for vendor Attendees & Sales dashboard.
 */
final class VendorAttendeesController extends ControllerBase {

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
    private readonly TicketLabelResolver $ticketLabelResolver,
    private readonly ?AttendeeEventStatsService $attendeeStats,
    private readonly MelAttendeeOperationsPresenter $operationsPresenter,
    private readonly GovernedOperationalTemplates $operationalTemplates,
    private readonly AttendanceManagerInterface $attendanceManager,
    private readonly MelAttendeeCheckinManager $checkinManager,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('myeventlane_core.ticket_label_resolver'),
      $container->has('myeventlane_checkout_flow.attendee_event_stats')
        ? $container->get('myeventlane_checkout_flow.attendee_event_stats') : NULL,
      $container->get('myeventlane_checkout_flow.attendee_operations_presenter'),
      $container->get('myeventlane_surface.governed_operational_templates'),
      $container->get('myeventlane_event_attendees.manager'),
      $container->get('myeventlane_checkout_flow.attendee_checkin_manager'),
    );
  }

  public function checkAccess(): AccessResult {
    $account = $this->currentUser;

    if ($account->hasPermission('administer commerce_order') || $account->hasPermission('bypass node access')) {
      return AccessResult::allowed()->addCacheContexts(['user.permissions']);
    }

    if (\Drupal::hasService('myeventlane_checkout_flow.vendor_ownership_resolver')) {
      $vendorResolver = \Drupal::service('myeventlane_checkout_flow.vendor_ownership_resolver');
      $store = $vendorResolver->getStoreForUser($account);
      if ($store) {
        return AccessResult::allowed()->addCacheContexts(['user']);
      }
    }

    return AccessResult::forbidden('Only vendors and administrators can access this page.')->addCacheContexts(['user']);
  }

  public function dashboard(): array {
    $account = $this->currentUser;
    $store = NULL;

    if (\Drupal::hasService('myeventlane_checkout_flow.vendor_ownership_resolver')) {
      $vendorResolver = \Drupal::service('myeventlane_checkout_flow.vendor_ownership_resolver');
      $store = $vendorResolver->getStoreForUser($account);
    }

    if (!$store) {
      return [
        '#markup' => $this->t('No vendor account found. Please contact support.'),
        '#cache' => ['contexts' => ['user']],
      ];
    }

    $events = $this->getVendorEvents($store);
    $eventData = [];
    $operationalSummaries = [];
    $melCards = [];
    $statsAvailable = TRUE;
    $melKpis = [
      'events' => 0,
      'tickets_sold' => 0,
      'revenue' => '0.00',
      'upcoming' => 0,
    ];

    if (!empty($events)) {
      if ($this->attendeeStats === NULL) {
        $statsAvailable = FALSE;
      }
      else {
        try {
          $stats = $this->attendeeStats->buildStatsForEvents(array_values($events));
          $melCards = is_array($stats['cards'] ?? NULL) ? $stats['cards'] : [];
          $melKpis = is_array($stats['kpis'] ?? NULL) ? $stats['kpis'] : $melKpis;
        }
        catch (\Throwable $e) {
          $statsAvailable = FALSE;
          $this->getLogger('myeventlane_checkout_flow')->error('Unable to build the organiser attendee portfolio: @message', [
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }

    foreach ($melCards as $eid => &$card) {
      if (!is_array($card)) {
        continue;
      }
      $event = $events[$eid] ?? NULL;
      if (!$event instanceof NodeInterface) {
        continue;
      }
      try {
        $door = Url::fromRoute('myeventlane_event_attendees.vendor_operations_door', ['node' => (int) $eid])->toString();
        $card['door_checkin_url'] = $door;
        $card['checkin_url'] = $door;
      }
      catch (\Throwable) {
      }
      try {
        $card['operations_url'] = Url::fromRoute(
          'myeventlane_event_attendees.vendor_operations',
          ['node' => (int) $eid],
        )->toString();
      }
      catch (\Throwable) {
        $card['operations_url'] = '';
      }
      $readiness = $this->checkinManager->readinessForEvent($event);
      $availability = $this->attendanceManager->getAvailability($event);
      $card['ops_checked_in'] = (int) ($readiness['checked_in'] ?? 0);
      $card['ops_total_attendees'] = (int) ($readiness['total'] ?? 0);
      $totalOpsAtt = (int) ($readiness['total'] ?? 0);
      $card['ops_check_in_percent'] = $totalOpsAtt > 0
        ? round(100.0 * (int) ($readiness['checked_in'] ?? 0) / $totalOpsAtt, 1)
        : 0.0;
      $card['ops_remaining_capacity'] = $availability['remaining'];
      $card['ops_ready'] = (int) ($readiness['ready'] ?? 0);
      $now = time();
      $startTs = 0;
      if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
        $raw = $event->get('field_event_start')->value;
        if (is_string($raw)) {
          $startTs = strtotime($raw) ?: 0;
        }
      }
      $endTs = $startTs;
      if ($event->hasField('field_event_end') && !$event->get('field_event_end')->isEmpty()) {
        $rawEnd = $event->get('field_event_end')->value;
        if (is_string($rawEnd)) {
          $parsed = strtotime($rawEnd);
          if ($parsed) {
            $endTs = $parsed;
          }
        }
      }
      $card['ops_event_active'] = $event->isPublished() && $startTs > 0
        && $now >= ($startTs - 6 * 3600)
        && $now <= ($endTs > $startTs ? $endTs + 4 * 3600 : $startTs + 18 * 3600);
    }
    unset($card);

    foreach ($events as $event) {
      $eventId = (int) $event->id();
      $card = is_array($melCards[$eventId] ?? NULL) ? $melCards[$eventId] : [];
      $eventData[] = [
        'event' => $event,
        'id' => $eventId,
        'title' => $event->label(),
        'url' => $event->toUrl()->toString(),
        'start_date' => $event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()
          ? date('F j, Y', strtotime($event->get('field_event_start')->value))
          : NULL,
        'tickets_sold' => (int) ($card['sold'] ?? 0),
        'attendee_count' => (int) ($card['attendees'] ?? 0),
        'revenue' => (float) str_replace(',', '', (string) ($card['revenue'] ?? '0.00')),
      ];

      if ($event instanceof NodeInterface) {
        $vm = $this->operationsPresenter->buildEventViewModel($event);
        $operationalSummaries[(int) $event->id()] = [
          'event_id' => (int) $event->id(),
          'title' => (string) $event->label(),
          'url' => $event->toUrl()->toString(),
          'totals' => $vm['readiness'],
        ];
      }
    }

    $build = [
      '#theme' => 'myeventlane_vendor_attendees_dashboard',
      '#title' => $this->t('Attendees'),
      '#events' => $eventData,
      '#mel_cards' => $melCards,
      '#mel_kpis' => $melKpis,
      '#mel_stats_unavailable' => !$statsAvailable,
      '#operational_summaries' => $operationalSummaries,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node_list:event'],
      ],
    ];

    if (empty($events)) {
      $build['#mel_vendor_attendees_dashboard_empty'] = $this->operationalTemplates->vendorAttendeesDashboardEmpty();
    }

    return $build;
  }

  private function getVendorEvents(StoreInterface $store): array {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $events = [];

    if (\Drupal::moduleHandler()->moduleExists('myeventlane_vendor')) {
      $vendorStorage = $this->entityTypeManager->getStorage('myeventlane_vendor');
      $vendorIds = $vendorStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_vendor_store', $store->id())
        ->execute();

      if (!empty($vendorIds)) {
        $vendorIds = array_values($vendorIds);
        $eventIds = $nodeStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', 'event')
          ->condition('field_event_vendor', $vendorIds, 'IN')
          ->condition('status', 1)
          ->sort('field_event_start', 'DESC')
          ->execute();

        if (!empty($eventIds)) {
          $events = $nodeStorage->loadMultiple($eventIds);
        }
      }
    }

    if (empty($events)) {
      $eventIds = $nodeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('uid', $store->getOwnerId())
        ->condition('status', 1)
        ->sort('field_event_start', 'DESC')
        ->execute();

      if (!empty($eventIds)) {
        $events = $nodeStorage->loadMultiple($eventIds);
      }
    }

    return $events;
  }

}
