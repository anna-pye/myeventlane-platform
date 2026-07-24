<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_core\Service\EntityIdNormalizer;
use Drupal\myeventlane_event_studio\Service\EventRepository;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface;
use Drupal\myeventlane_vendor\Service\TicketSalesService;
use Drupal\myeventlane_vendor\Service\VendorPaymentsHealthService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Payouts controller for vendor console.
 *
 * Deep page under Payments Hub — ticket earnings history and Stripe balances.
 */
final class VendorPayoutsController extends VendorConsoleBaseController implements ContainerInjectionInterface {

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly TicketSalesService $ticketSalesService,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityIdNormalizer $entityIdNormalizer,
    private readonly ?EventRepository $eventRepository,
    private readonly CurrentVendorResolverInterface $vendorResolver,
    private readonly VendorPaymentsHealthService $paymentsHealth,
    private readonly ?object $stripePayout = NULL,
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
      $container->get('myeventlane_vendor.service.ticket_sales'),
      $container->get('entity_type.manager'),
      $container->get('myeventlane_core.entity_id_normalizer'),
      $container->has('myeventlane_event_studio.repository') ? $container->get('myeventlane_event_studio.repository') : NULL,
      $container->get('myeventlane_vendor.current_vendor_resolver'),
      $container->get('myeventlane_vendor.payments_health'),
      $container->has('myeventlane_stripe.vendor_payout') ? $container->get('myeventlane_stripe.vendor_payout') : NULL,
    );
  }

  /**
   * Displays payout history and Stripe status.
   */
  public function payouts(): array {
    $userId = (int) $this->currentUser->id();
    $vendor = $this->vendorResolver->resolveFromCurrentUser();
    $health = $this->paymentsHealth->buildForCurrentUser($vendor);
    $store = $this->paymentsHealth->resolveStore($vendor);

    $stripeManageUrl = NULL;
    try {
      $stripeManageUrl = Url::fromRoute('myeventlane_vendor.stripe_manage')->toString();
    }
    catch (\Exception) {
      // Route may not exist.
    }

    $vendorRevenue = $this->ticketSalesService->getManagedVendorRevenue($userId);
    $available = '$0.00';
    $pending = '$0.00';
    $lastPayoutLabel = '—';
    if ($store !== NULL && $this->stripePayout !== NULL
      && method_exists($this->stripePayout, 'getAvailableBalanceFormatted')) {
      $available = $this->stripePayout->getAvailableBalanceFormatted($store);
      if (method_exists($this->stripePayout, 'getPendingBalanceFormatted')) {
        $pending = $this->stripePayout->getPendingBalanceFormatted($store);
      }
      if (method_exists($this->stripePayout, 'getLatestPayoutSummary')) {
        $last = $this->stripePayout->getLatestPayoutSummary($store);
        if (is_array($last)) {
          $lastPayoutLabel = trim(($last['amount'] ?? '') . ' · ' . ($last['date_label'] ?? '') . ' · ' . ($last['status'] ?? ''), ' ·');
        }
      }
    }

    $summary = [
      'total_sales' => $vendorRevenue['gross'] ?? '$0.00',
      'total_fees' => $vendorRevenue['fees'] ?? '$0.00',
      'net_earnings' => $vendorRevenue['net'] ?? '$0.00',
      'pending_payout' => $pending,
      'balance' => $available,
      'next_payout' => !empty($health['payouts_enabled'])
        ? (string) $this->t("On Stripe's usual schedule")
        : (string) $this->t('Once payouts are enabled'),
      'last_payout' => $lastPayoutLabel,
    ];

    $history = $this->getRecentTransactions($userId);
    $paymentsHubUrl = NULL;
    try {
      $paymentsHubUrl = Url::fromRoute('myeventlane_vendor.console.payments')->toString();
    }
    catch (\Exception) {
    }

    $headerActions = [];
    if ($paymentsHubUrl) {
      $headerActions[] = [
        'label' => (string) $this->t('Payments overview'),
        'url' => $paymentsHubUrl,
        'class' => 'mel-btn--secondary',
      ];
    }
    if ($stripeManageUrl) {
      $headerActions[] = [
        'label' => (string) $this->t('Open Stripe'),
        'url' => $stripeManageUrl,
        'class' => 'mel-btn--secondary',
        'external' => TRUE,
      ];
    }

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => (string) $this->t('Payouts'),
      'header_actions' => $headerActions,
      'body' => [
        '#theme' => 'myeventlane_vendor_payouts',
        '#summary' => $summary,
        '#history' => $history,
        '#stripe_manage_url' => $stripeManageUrl,
        '#health' => $health,
        '#empty' => $history === [] && ($available === '$0.00'),
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
      // Include unpublished managed events so historical sales stay visible.
      $managedEventIds = $this->ticketSalesService->getManagedEventNidsForUser($userId);
      $normalized = $this->entityIdNormalizer->normalizeNodeIds($managedEventIds);
      if ($normalized === []) {
        return [];
      }

      $eventTitles = [];
      if ($this->eventRepository !== NULL) {
        foreach ($this->eventRepository->loadMany($normalized) as $dto) {
          $eventTitles[$dto->id] = $dto->title;
        }
      }

      // Get order items for these events.
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => $normalized,
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
          'date' => date('j M Y', (int) $completedTime),
          'event' => implode(', ', array_values($data['events'])) ?: (string) $this->t('Event'),
          'amount' => '$' . number_format($data['amount'], 2),
          'status' => (string) $this->t('Completed'),
        ];
      }
    }
    catch (\Exception) {
      // Commerce may not be available.
    }

    return $transactions;
  }

}
