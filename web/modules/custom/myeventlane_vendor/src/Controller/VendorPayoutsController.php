<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\TicketSalesService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Payouts controller for vendor console.
 *
 * Displays real sales data from Commerce orders.
 */
final class VendorPayoutsController extends VendorConsoleBaseController implements ContainerInjectionInterface {

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    private readonly TicketSalesService $ticketSalesService,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($domain_detector, $current_user);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('myeventlane_vendor.service.ticket_sales'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Displays payout history and Stripe status.
   */
  public function payouts(): array {
    $userId = (int) $this->currentUser->id();

    // Build Stripe manage URL.
    $stripeManageUrl = NULL;
    try {
      $stripeManageUrl = Url::fromRoute('myeventlane_vendor.stripe_manage')->toString();
    }
    catch (\Exception) {
      // Route may not exist.
    }

    // Get real revenue data from TicketSalesService.
    $vendorRevenue = $this->ticketSalesService->getVendorRevenue($userId);

    $summary = [
      'total_sales' => $vendorRevenue['gross'] ?? '$0.00',
      'total_fees' => $vendorRevenue['fees'] ?? '$0.00',
      'net_earnings' => $vendorRevenue['net'] ?? '$0.00',
    // Would come from Stripe API.
      'pending_payout' => '$0.00',
    ];

    // Get recent transactions from completed orders.
    $history = $this->getRecentTransactions($userId);

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => 'Payouts',
      'header_actions' => $stripeManageUrl ? [
        [
          'label' => 'Manage in Stripe',
          'url' => $stripeManageUrl,
          'class' => 'mel-btn--secondary',
          'external' => TRUE,
        ],
      ] : [],
      'body' => [
        '#theme' => 'myeventlane_vendor_payouts',
        '#summary' => $summary,
        '#history' => $history,
        '#stripe_manage_url' => $stripeManageUrl,
      ],
    ]);
  }

  /**
   * Gets recent transactions for a vendor.
   *
   * @param int $userId
   *   The vendor user ID.
   *
   * @return array
   *   Array of recent transactions.
   */
  private function getRecentTransactions(int $userId): array {
    $transactions = [];

    try {
      // Get all events owned by this user.
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $eventIds = $nodeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('uid', $userId)
        ->execute();

      if (empty($eventIds)) {
        return [];
      }

      // Load events for titles.
      $events = $nodeStorage->loadMultiple($eventIds);
      $eventTitles = [];
      foreach ($events as $event) {
        $eventTitles[$event->id()] = $event->label();
      }

      // Get order items for these events.
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => array_values($eventIds),
      ]);

      // Group by order and get order details.
      $orderData = [];
      foreach ($orderItems as $item) {
        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        // Safely load the order entity to avoid getOrder() warnings.
        $order_id = $item->get('order_id')->target_id;
        if (!$order_id) {
          continue;
        }

        try {
          $order = $this->entityTypeManager
            ->getStorage('commerce_order')
            ->load($order_id);
          if (!$order || $order->getState()->getId() !== 'completed') {
            continue;
          }

          $orderId = $order->id();
          if (!isset($orderData[$orderId])) {
            $orderData[$orderId] = [
              'order' => $order,
              'amount' => 0.0,
              'events' => [],
            ];
          }

          $totalPrice = $item->getTotalPrice();
          if ($totalPrice) {
            $orderData[$orderId]['amount'] += (float) $totalPrice->getNumber();
          }

          // Get event title.
          if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
            $eventId = $item->get('field_target_event')->target_id;
            if (isset($eventTitles[$eventId])) {
              $orderData[$orderId]['events'][$eventId] = $eventTitles[$eventId];
            }
          }
        }
        catch (\Exception) {
          continue;
        }
      }

      // Sort by completion time (most recent first) and limit to 10.
      usort($orderData, function ($a, $b) {
        $timeA = $a['order']->getCompletedTime() ?? $a['order']->getChangedTime();
        $timeB = $b['order']->getCompletedTime() ?? $b['order']->getChangedTime();
        return $timeB <=> $timeA;
      });

      $orderData = array_slice($orderData, 0, 10);

      // Format for display.
      foreach ($orderData as $data) {
        $order = $data['order'];
        $completedTime = $order->getCompletedTime() ?? $order->getChangedTime();

        $transactions[] = [
          'date' => date('M j, Y', (int) $completedTime),
          'event' => implode(', ', array_values($data['events'])) ?: 'Unknown',
          'amount' => '$' . number_format($data['amount'], 2),
          'status' => 'Completed',
        ];
      }
    }
    catch (\Exception) {
      // Commerce may not be available.
    }

    return $transactions;
  }

}
