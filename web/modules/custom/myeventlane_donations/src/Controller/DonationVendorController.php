<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\myeventlane_donations\Service\DonationService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for vendor donation analytics.
 */
final class DonationVendorController extends ControllerBase {

  /**
   * Constructs a DonationVendorController.
   *
   * @param \Drupal\myeventlane_donations\Service\DonationService $donationService
   *   The donation service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   */
  public function __construct(
    private readonly DonationService $donationService,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_donations.service'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Lists donations received by the vendor, grouped by event.
   *
   * @return array
   *   A render array.
   */
  public function list(): array {
    $currentUser = $this->currentUser();
    $vendorUid = (int) $currentUser->id();

    // Get all events owned by this vendor.
    $eventStorage = $this->entityTypeManager()->getStorage('node');
    $eventIds = $eventStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('uid', $vendorUid)
      ->sort('created', 'DESC')
      ->execute();

    $eventsData = [];
    $totalDonations = 0.0;
    $totalCount = 0;

    if (!empty($eventIds)) {
      $events = $eventStorage->loadMultiple($eventIds);
      foreach ($events as $event) {
        $eventId = (int) $event->id();
        $stats = $this->donationService->getEventDonationStats($eventId);

        if ($stats['count'] > 0) {
          $donations = $this->getEventDonations($eventId);

          $eventsData[] = [
            'event_id' => $eventId,
            'event_title' => $event->label(),
            'event_url' => $event->toUrl()->toString(),
            'total' => $stats['total'],
            'count' => $stats['count'],
            'average' => $stats['count'] > 0 ? $stats['total'] / $stats['count'] : 0,
            'donations' => $donations,
            'created' => $event->getCreatedTime(),
          ];

          $totalDonations += $stats['total'];
          $totalCount += $stats['count'];
        }
      }
    }

    // Sort by total donations descending.
    usort($eventsData, function ($a, $b) {
      return $b['total'] <=> $a['total'];
    });

    return [
      '#theme' => 'myeventlane_donation_vendor_list',
      '#events' => $eventsData,
      '#total_donations' => $totalDonations,
      '#total_count' => $totalCount,
      '#average_donation' => $totalCount > 0 ? $totalDonations / $totalCount : 0,
      '#attached' => [
        'library' => ['myeventlane_donations/donation-vendor'],
      ],
    ];
  }

  /**
   * Gets detailed donation list for an event.
   *
   * @param int $eventId
   *   The event node ID.
   *
   * @return array
   *   Array of donation data with date, amount, donor info.
   */
  private function getEventDonations(int $eventId): array {
    $donations = [];

    try {
      $orderStorage = $this->entityTypeManager()->getStorage('commerce_order');
      $orderItemStorage = $this->entityTypeManager()->getStorage('commerce_order_item');

      // Find all RSVP donation order items for this event.
      $orderItemIds = $orderItemStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'rsvp_donation')
        ->condition('field_target_event', $eventId)
        ->execute();

      if (!empty($orderItemIds)) {
        $orderItems = $orderItemStorage->loadMultiple($orderItemIds);
        foreach ($orderItems as $orderItem) {
          if (!$orderItem->hasField('order_id') || $orderItem->get('order_id')->isEmpty()) {
            continue;
          }

          // Safely load the order entity to avoid getOrder() warnings.
          $order_id = $orderItem->get('order_id')->target_id;
          if (!$order_id) {
            continue;
          }

          try {
            $order = $this->entityTypeManager()
              ->getStorage('commerce_order')
              ->load($order_id);
            if ($order && $order->getState()->getId() === 'completed') {
              $user = $order->getCustomer();
              $donations[] = [
                'date' => $this->dateFormatter->format($order->getCreatedTime(), 'short'),
                'amount' => (float) ($orderItem->getTotalPrice() ? $orderItem->getTotalPrice()->getNumber() : 0),
                'donor_name' => $user ? $user->getDisplayName() : 'Anonymous',
                'donor_email' => $user ? $user->getEmail() : '',
                'order_id' => $order->id(),
              ];
            }
          }
          catch (\Exception $e) {
            continue;
          }
        }
      }
    }
    catch (\Exception $e) {
      $this->getLogger('myeventlane_donations')->error('Failed to get event donations: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    // Sort by date descending.
    usort($donations, function ($a, $b) {
      return strtotime($b['date']) <=> strtotime($a['date']);
    });

    return $donations;
  }

}
