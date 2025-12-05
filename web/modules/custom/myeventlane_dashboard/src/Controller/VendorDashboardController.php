<?php

declare(strict_types=1);

namespace Drupal\myeventlane_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\TimeInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_dashboard\Service\DashboardAccess;
use Drupal\myeventlane_dashboard\Service\DashboardEventLoader;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for the vendor dashboard.
 */
final class VendorDashboardController extends ControllerBase {

  /**
   * Constructs a VendorDashboardController object.
   */
  public function __construct(
    private readonly DashboardAccess $dashboardAccess,
    private readonly DashboardEventLoader $eventLoader,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_dashboard.access'),
      $container->get('myeventlane_dashboard.event_loader'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Redirects from old /dashboard path to /vendor/dashboard.
   */
  public function legacyRedirect(): RedirectResponse {
    return new RedirectResponse(
      Url::fromRoute('myeventlane_dashboard.vendor')->toString(),
      301
    );
  }

  /**
   * Gets attendee and revenue stats for an event.
   *
   * @param int $eventId
   *   The event node ID.
   *
   * @return array
   *   Array with 'attendee_count', 'revenue', and 'mode'.
   */
  private function getEventStats(int $eventId): array {
    // Get attendee count using entity query API.
    $attendeeCount = $this->entityTypeManager()
      ->getStorage('event_attendee')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $eventId)
      ->condition('status', 'confirmed')
      ->count()
      ->execute();

    // Get revenue from Commerce orders.
    // Find all order items linked to this event.
    $orderItemStorage = $this->entityTypeManager()->getStorage('commerce_order_item');
    $orderItems = $orderItemStorage->loadByProperties([
      'field_target_event' => $eventId,
    ]);

    $revenue = 0;
    foreach ($orderItems as $item) {
      $totalPrice = $item->getTotalPrice();
      if ($totalPrice) {
        $revenue += (float) $totalPrice->getNumber();
      }
    }

    // Get event mode.
    $eventNode = $this->entityTypeManager()->getStorage('node')->load($eventId);
    $mode = 'unknown';
    if ($eventNode) {
      try {
        $modeManager = \Drupal::service('myeventlane_event.mode_manager');
        $mode = $modeManager->getEffectiveMode($eventNode);
      }
      catch (ServiceNotFoundException) {
        // Service not available, use default mode.
      }
    }

    return [
      'attendee_count' => (int) $attendeeCount,
      'revenue' => $revenue,
      'mode' => $mode,
    ];
  }

  /**
   * Renders the vendor dashboard page.
   *
   * @return array
   *   A render array for the vendor dashboard.
   */
  public function dashboard(): array {
    $currentUser = $this->currentUser();
    // Load events for the current user (admin=FALSE loads only user's events).
    $events = $this->eventLoader->loadEvents(FALSE, 50);

    // Build event summary data.
    $upcomingEvents = [];
    $pastEvents = [];
    $now = $this->time->getRequestTime();

    foreach ($events as $event) {
      $startTime = NULL;

      if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
        try {
          $startTime = strtotime($event->get('field_event_start')->value);
        }
        catch (\Exception) {
          // Ignore date parsing errors.
        }
      }

      // Get attendee counts and revenue for this event.
      $stats = $this->getEventStats((int) $event->id());

      $eventData = [
        'id' => $event->id(),
        'title' => $event->label(),
        'url' => $event->toUrl()->toString(),
        'edit_url' => $event->toUrl('edit-form')->toString(),
        'start_date' => $startTime ? date('M j, Y', $startTime) : '',
        'start_time' => $startTime ? date('g:ia', $startTime) : '',
        'attendee_count' => $stats['attendee_count'],
        'revenue' => $stats['revenue'],
        'event_mode' => $stats['mode'],
      ];

      if ($startTime && $startTime > $now) {
        $upcomingEvents[] = $eventData;
      }
      else {
        $pastEvents[] = $eventData;
      }
    }

    // Quick action links.
    $quickLinks = [
      [
        'title' => $this->t('Create Event'),
        'url' => Url::fromRoute('node.add', ['node_type' => 'event'])->toString(),
        'icon' => 'add',
      ],
    ];

    return [
      '#theme' => 'myeventlane_vendor_dashboard',
      '#upcoming_events' => $upcomingEvents,
      '#past_events' => $pastEvents,
      '#quick_links' => $quickLinks,
      '#welcome_message' => $this->t('Welcome back, @name. Here is an overview of your events.', [
        '@name' => $currentUser->getDisplayName(),
      ]),
      '#attached' => [
        'library' => ['myeventlane_dashboard/dashboard'],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node_list', 'user:' . $currentUser->id()],
        'max-age' => 300,
      ],
    ];
  }

}
