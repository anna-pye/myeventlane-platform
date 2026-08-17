<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\myeventlane_messaging\Service\OrderConfirmationQueueBuilder;
use Drupal\node\NodeInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Queues order confirmation when an order is placed.
 *
 * Includes:
 * - Branded HTML confirmation (order_confirmation template)
 * - Calendar (.ics) attachments (one per event)
 * - Ticket PDFs merged at send time (see MessagingManager + myeventlane_tickets)
 * - Clear separation of tickets vs donations
 * - Dedicated boost confirmation email for boost-only orders.
 */
final class OrderPlacedSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Placeholder vendor support URL until the support page is built.
   */
  private const VENDOR_SUPPORT_URL = 'https://myeventlane.com.au/contact';

  public function __construct(
    private readonly MessagingManager $messagingManager,
    private readonly LoggerInterface $logger,
    private readonly OrderConfirmationQueueBuilder $orderConfirmationQueue,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.place.post_transition' => 'onPlace',
    ];
  }

  /**
   * Queues the order confirmation email with ICS attachments.
   *
   * Detects boost-only orders and sends a dedicated boost confirmation
   * template instead of the generic order confirmation.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The workflow transition event.
   */
  public function onPlace(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if (!$order instanceof OrderInterface) {
      return;
    }

    $orderId = (int) $order->id();

    $mail = $order->getEmail();
    if (!$mail) {
      $customer = $order->getCustomer();
      $mail = $customer ? $customer->getEmail() : NULL;
    }

    if (!$mail) {
      $this->logger->warning(
        'Order @order_id placed but no email address found for receipt.',
        [
          '@order_id' => $orderId,
          'order_id' => $orderId,
        ]
      );
      return;
    }

    if ($this->isBoostOnlyOrder($order)) {
      $this->sendBoostConfirmation($order, $mail);
      return;
    }

    if ($this->isProOnlyOrder($order)) {
      $this->sendProSubscriptionStarted($order, $mail);
      return;
    }

    $this->orderConfirmationQueue->queue($order, $mail, FALSE);
  }

  /**
   * Checks if an order contains only boost items (no tickets, no donations).
   */
  private function isBoostOnlyOrder(OrderInterface $order): bool {
    $items = $order->getItems();
    if (empty($items)) {
      return FALSE;
    }

    foreach ($items as $item) {
      if ($item->bundle() !== 'boost') {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Checks if an order contains only MyEventLane Pro subscription inventory.
   */
  private function isProOnlyOrder(OrderInterface $order): bool {
    $items = $order->getItems();
    if ($items === []) {
      return FALSE;
    }

    foreach ($items as $item) {
      $variation = $item->getPurchasedEntity();
      if (!$variation instanceof ProductVariationInterface
        || $variation->bundle() !== 'mel_pro_subscription_variation') {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Queues the organiser-only Pro trial/subscription confirmation.
   */
  private function sendProSubscriptionStarted(OrderInterface $order, string $mail): void {
    $orderId = (int) $order->id();
    $customer = $order->getCustomer();
    $firstName = $customer ? $customer->getDisplayName() : 'there';
    $items = $order->getItems();
    $firstItem = reset($items) ?: NULL;
    $variation = $firstItem?->getPurchasedEntity();
    $price = $variation instanceof ProductVariationInterface ? $variation->getPrice() : NULL;
    $monthlyPrice = $price !== NULL
      ? $this->formatCurrency($price->getNumber(), $price->getCurrencyCode())
      : 'the displayed monthly price';
    $trialDays = max(0, (int) $this->configFactory->get('myeventlane_pro.settings')->get('trial_days'));
    $chargedToday = (float) ($order->getTotalPrice()?->getNumber() ?? 0);
    $trialApplies = $trialDays > 0 && $chargedToday <= 0.0;

    try {
      $manageUrl = Url::fromRoute('myeventlane_pro.manage', [], ['absolute' => TRUE])
        ->toString(TRUE)
        ->getGeneratedUrl();
    }
    catch (\Exception $exception) {
      $this->logger->warning('Could not generate Pro manage URL: @message', [
        '@message' => $exception->getMessage(),
      ]);
      $manageUrl = '/vendor/pro/manage';
    }

    $context = [
      'first_name' => $firstName,
      'order_number' => $order->label(),
      'order_id' => $orderId,
      'order_email' => $mail,
      'trial_days' => $trialDays,
      'trial_applies' => $trialApplies,
      'monthly_price' => $monthlyPrice,
      'charged_today' => $this->formatCurrency(
        $order->getTotalPrice()?->getNumber() ?? '0',
        $order->getTotalPrice()?->getCurrencyCode() ?? 'AUD',
      ),
      'pro_manage_url' => $manageUrl,
      'support_url' => self::VENDOR_SUPPORT_URL,
    ];

    $messageId = $this->messagingManager->queue('pro_subscription_started', $mail, $context, [
      'langcode' => $order->language()->getId(),
      'idempotency_key' => sprintf('order:%d:pro_subscription_started', $orderId),
    ]);

    if ($messageId !== NULL) {
      $this->logger->info('Pro subscription confirmation queued for order @order_id to @email.', [
        '@order_id' => $orderId,
        '@email' => $mail,
        'order_id' => $orderId,
        'message_type' => 'pro_subscription_started',
      ]);
    }
  }

  /**
   * Sends a dedicated boost confirmation email.
   */
  private function sendBoostConfirmation(OrderInterface $order, string $mail): void {
    $orderId = (int) $order->id();
    $customer = $order->getCustomer();
    $first_name = $customer ? $customer->getDisplayName() : 'there';

    $boostItems = $this->extractBoostItems($order);

    if (empty($boostItems)) {
      $this->logger->error(
        'Boost-only order @order_id has no extractable boost items. Falling back to generic order confirmation.',
        ['@order_id' => $orderId, 'order_id' => $orderId]
      );
      $this->orderConfirmationQueue->queue($order, $mail, FALSE);
      return;
    }

    $primaryBoost = reset($boostItems);

    $boostManageUrl = NULL;
    if ($primaryBoost['event_id']) {
      try {
        $boostManageUrl = Url::fromRoute('myeventlane_boost.boost_page', [
          'node' => $primaryBoost['event_id'],
        ], [
          'absolute' => TRUE,
        ])->toString(TRUE)->getGeneratedUrl();
      }
      catch (\Exception $e) {
        $this->logger->warning('Could not generate boost manage URL: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $context = [
      'first_name' => $first_name,
      'order_number' => $order->label(),
      'order_id' => $orderId,
      'order_email' => $mail,
      'event_name' => $primaryBoost['event_name'],
      'boost_days' => $primaryBoost['boost_days'],
      'boost_start_date' => $primaryBoost['boost_start_date'],
      'boost_end_date' => $primaryBoost['boost_end_date'],
      'total_paid' => $this->formatCurrency(
        $order->getTotalPrice()?->getNumber() ?? '0',
        $order->getTotalPrice()?->getCurrencyCode() ?? 'AUD',
      ),
      'boost_manage_url' => $boostManageUrl,
      'support_url' => self::VENDOR_SUPPORT_URL,
    ];

    if ($primaryBoost['event_id']) {
      $context['event_id'] = $primaryBoost['event_id'];
    }

    try {
      $this->messagingManager->queue('boost_confirmation', $mail, $context, [
        'langcode' => $order->language()->getId(),
        'idempotency_key' => sprintf('order:%d:boost_confirmation', $orderId),
      ]);

      $this->logger->info(
        'Boost confirmation queued for order @order_id to @email (event: @event, @days days)',
        [
          '@order_id' => $orderId,
          '@email' => $mail,
          '@event' => $primaryBoost['event_name'],
          '@days' => $primaryBoost['boost_days'],
          'order_id' => $orderId,
          'event_id' => $primaryBoost['event_id'],
          'message_type' => 'boost_confirmation',
        ]
      );
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Failed to queue boost confirmation for order @order_id: @message',
        [
          '@order_id' => $orderId,
          '@message' => $e->getMessage(),
          'order_id' => $orderId,
          'event_id' => $primaryBoost['event_id'],
          'message_type' => 'boost_confirmation',
        ]
      );
    }
  }

  /**
   * Extracts boost item data from an order.
   *
   * @return array<int, array<string, mixed>>
   */
  private function extractBoostItems(OrderInterface $order): array {
    $items = [];
    $orderDate = new \DateTimeImmutable(
      $order->getPlacedTime()
        ? '@' . $order->getPlacedTime()
        : 'now',
      new \DateTimeZone('Australia/Sydney')
    );

    foreach ($order->getItems() as $item) {
      if ($item->bundle() !== 'boost') {
        continue;
      }

      $eventName = 'your event';
      $eventId = NULL;

      if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
        $event = $item->get('field_target_event')->entity;
        if ($event instanceof NodeInterface) {
          $eventName = $event->label();
          $eventId = (int) $event->id();
        }
      }

      $boostDays = 7;
      $variation = $item->getPurchasedEntity();
      if ($variation && $variation->hasField('field_boost_days') && !$variation->get('field_boost_days')->isEmpty()) {
        $boostDays = (int) $variation->get('field_boost_days')->value;
        if ($boostDays < 1) {
          $boostDays = 7;
        }
      }

      $startDate = $orderDate->setTimezone(new \DateTimeZone('Australia/Sydney'));
      $endDate = $startDate->modify(sprintf('+%d days', $boostDays));

      $items[] = [
        'event_id' => $eventId,
        'event_name' => $eventName,
        'boost_days' => $boostDays,
        'boost_start_date' => $startDate->format('j F Y'),
        'boost_end_date' => $endDate->format('j F Y'),
      ];
    }

    return $items;
  }

  private function formatCurrency(string $amount, string $currency): string {
    $prefix = strtoupper($currency) === 'AUD' ? 'A$' : strtoupper($currency) . ' ';
    return $prefix . number_format((float) $amount, 2);
  }

}
