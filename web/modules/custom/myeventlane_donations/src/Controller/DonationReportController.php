<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_donations\Service\DonationService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for admin donation reports.
 */
final class DonationReportController extends ControllerBase {

  /**
   * Constructs a DonationReportController.
   *
   * @param \Drupal\myeventlane_donations\Service\DonationService $donationService
   *   The donation service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
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
   * Renders the donation report page.
   *
   * @return array
   *   A render array.
   */
  public function report(): array {
    // Get platform donation stats (by month).
    $platformStats = $this->getPlatformDonationStats();
    // Get RSVP donation stats (by month).
    $rsvpStats = $this->getRsvpDonationStats();

    // Get total stats.
    $totalPlatform = array_sum(array_column($platformStats, 'total'));
    $totalRsvp = array_sum(array_column($rsvpStats, 'total'));
    $countPlatform = array_sum(array_column($platformStats, 'count'));
    $countRsvp = array_sum(array_column($rsvpStats, 'count'));

    $build = [
      '#theme' => 'myeventlane_donation_report',
      '#platform_stats' => $platformStats,
      '#rsvp_stats' => $rsvpStats,
      '#total_platform' => $totalPlatform,
      '#total_rsvp' => $totalRsvp,
      '#count_platform' => $countPlatform,
      '#count_rsvp' => $countRsvp,
      '#export_url' => Url::fromRoute('myeventlane_donations.admin_export')->toString(),
      '#attached' => [
        'library' => [
          'myeventlane_donations/donation-report',
        ],
      ],
    ];

    return $build;
  }

  /**
   * Exports donations as CSV.
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   *   A streamed CSV response.
   */
  public function export(): StreamedResponse {
    $response = new StreamedResponse();
    $response->setCallback(function () {
      $handle = fopen('php://output', 'w');

      // CSV headers.
      fputcsv($handle, [
        'Type',
        'Date',
        'Amount (AUD)',
        'Event',
        'Vendor',
        'Donor Name',
        'Donor Email',
        'Order ID',
        'Status',
      ]);

      // Get all platform donations.
      $platformDonations = $this->getAllPlatformDonations();
      foreach ($platformDonations as $donation) {
        fputcsv($handle, [
          'Platform',
          $donation['date'],
          number_format($donation['amount'], 2),
          '',
          $donation['vendor'],
          $donation['donor_name'],
          $donation['donor_email'],
          $donation['order_id'],
          $donation['status'],
        ]);
      }

      // Get all RSVP donations.
      $rsvpDonations = $this->getAllRsvpDonations();
      foreach ($rsvpDonations as $donation) {
        fputcsv($handle, [
          'RSVP',
          $donation['date'],
          number_format($donation['amount'], 2),
          $donation['event'],
          $donation['vendor'],
          $donation['donor_name'],
          $donation['donor_email'],
          $donation['order_id'],
          $donation['status'],
        ]);
      }

      fclose($handle);
    });

    $filename = 'donations-export-' . date('Y-m-d-His') . '.csv';
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    // Log export.
    $this->getLogger('myeventlane_donations')->info('Donation CSV export downloaded by user @uid', [
      '@uid' => $this->currentUser()->id(),
    ]);

    return $response;
  }

  /**
   * Gets platform donation statistics by month.
   *
   * @return array
   *   Array of monthly stats with 'month', 'total', 'count'.
   */
  private function getPlatformDonationStats(): array {
    $stats = [];
    try {
      $orderStorage = $this->entityTypeManager()->getStorage('commerce_order');
      $orderItemStorage = $this->entityTypeManager()->getStorage('commerce_order_item');

      // Find all platform donation orders.
      $orderIds = $orderStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'platform_donation')
        ->condition('state', 'completed')
        ->sort('created', 'DESC')
        ->execute();

      if (!empty($orderIds)) {
        $orders = $orderStorage->loadMultiple($orderIds);
        foreach ($orders as $order) {
          $created = $order->getCreatedTime();
          $monthKey = date('Y-m', $created);
          $monthLabel = date('M Y', $created);
          if (!isset($stats[$monthKey])) {
            $stats[$monthKey] = [
              'month' => $monthLabel,
              'total' => 0.0,
              'count' => 0,
            ];
          }
          $totalPrice = $order->getTotalPrice();
          if ($totalPrice) {
            $stats[$monthKey]['total'] += (float) $totalPrice->getNumber();
          }
          $stats[$monthKey]['count']++;
        }
      }
    }
    catch (\Exception $e) {
      $this->getLogger('myeventlane_donations')->error('Failed to get platform donation stats: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return array_values($stats);
  }

  /**
   * Gets RSVP donation statistics by month.
   *
   * @return array
   *   Array of monthly stats with 'month', 'total', 'count'.
   */
  private function getRsvpDonationStats(): array {
    $stats = [];
    try {
      $orderStorage = $this->entityTypeManager()->getStorage('commerce_order');
      $orderItemStorage = $this->entityTypeManager()->getStorage('commerce_order_item');

      // Find all RSVP donation orders.
      $orderIds = $orderStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'rsvp_donation')
        ->condition('state', 'completed')
        ->sort('created', 'DESC')
        ->execute();

      if (!empty($orderIds)) {
        $orders = $orderStorage->loadMultiple($orderIds);
        foreach ($orders as $order) {
          $created = $order->getCreatedTime();
          $monthKey = date('Y-m', $created);
          $monthLabel = date('M Y', $created);
          if (!isset($stats[$monthKey])) {
            $stats[$monthKey] = [
              'month' => $monthLabel,
              'total' => 0.0,
              'count' => 0,
            ];
          }
          $totalPrice = $order->getTotalPrice();
          if ($totalPrice) {
            $stats[$monthKey]['total'] += (float) $totalPrice->getNumber();
          }
          $stats[$monthKey]['count']++;
        }
      }
    }
    catch (\Exception $e) {
      $this->getLogger('myeventlane_donations')->error('Failed to get RSVP donation stats: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return array_values($stats);
  }

  /**
   * Gets all platform donations for export.
   *
   * @return array
   *   Array of donation data.
   */
  private function getAllPlatformDonations(): array {
    $donations = [];
    try {
      $orderStorage = $this->entityTypeManager()->getStorage('commerce_order');
      $orderIds = $orderStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'platform_donation')
        ->sort('created', 'DESC')
        ->execute();

      if (!empty($orderIds)) {
        $orders = $orderStorage->loadMultiple($orderIds);
        foreach ($orders as $order) {
          $user = $order->getCustomer();
          $donations[] = [
            'date' => $this->dateFormatter->format($order->getCreatedTime(), 'short'),
            'amount' => (float) ($order->getTotalPrice() ? $order->getTotalPrice()->getNumber() : 0),
            'vendor' => $user ? $user->getDisplayName() : 'Unknown',
            'donor_name' => $user ? $user->getDisplayName() : 'Unknown',
            'donor_email' => $user ? $user->getEmail() : '',
            'order_id' => $order->id(),
            'status' => $order->getState()->getLabel(),
          ];
        }
      }
    }
    catch (\Exception $e) {
      $this->getLogger('myeventlane_donations')->error('Failed to get platform donations: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $donations;
  }

  /**
   * Gets all RSVP donations for export.
   *
   * @return array
   *   Array of donation data.
   */
  private function getAllRsvpDonations(): array {
    $donations = [];
    try {
      $orderStorage = $this->entityTypeManager()->getStorage('commerce_order');
      $orderItemStorage = $this->entityTypeManager()->getStorage('commerce_order_item');

      // Find all RSVP donation order items.
      $orderItemIds = $orderItemStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'rsvp_donation')
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
            if ($order) {
              $event = NULL;
              if ($orderItem->hasField('field_target_event') && !$orderItem->get('field_target_event')->isEmpty()) {
                $event = $orderItem->get('field_target_event')->entity;
              }

              $user = $order->getCustomer();
              $donations[] = [
                'date' => $this->dateFormatter->format($order->getCreatedTime(), 'short'),
                'amount' => (float) ($orderItem->getTotalPrice() ? $orderItem->getTotalPrice()->getNumber() : 0),
                'event' => $event ? $event->label() : 'Unknown',
                'vendor' => $event && $event->getOwner() ? $event->getOwner()->getDisplayName() : 'Unknown',
                'donor_name' => $user ? $user->getDisplayName() : 'Anonymous',
                'donor_email' => $user ? $user->getEmail() : '',
                'order_id' => $order->id(),
                'status' => $order->getState()->getLabel(),
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
      $this->getLogger('myeventlane_donations')->error('Failed to get RSVP donations: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $donations;
  }

}
