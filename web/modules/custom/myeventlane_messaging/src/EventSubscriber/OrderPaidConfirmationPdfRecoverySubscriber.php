<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\myeventlane_messaging\Service\MessageStorage;
use Drupal\myeventlane_messaging\Service\OrderConfirmationQueueBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Queues one order_confirmation resend after payment when tickets issue late.
 *
 * Fires only when a sent confirmation predates ticket entity creation, so the
 * first send could not include PDF attachments. Uses state to send at most once.
 */
final class OrderPaidConfirmationPdfRecoverySubscriber implements EventSubscriberInterface {

  /**
   * State API key prefix; order id is appended without separator ambiguity.
   *
   * @see self::recoveryStateKey()
   */
  private const STATE_PREFIX = 'myeventlane_messaging.oc_pdf_recovery_done.';

  /**
   * Stable state key for PDF recovery completion marker for one order.
   */
  public static function recoveryStateKey(int $orderId): string {
    return self::STATE_PREFIX . $orderId;
  }

  public function __construct(
    private readonly OrderConfirmationQueueBuilder $orderConfirmationQueue,
    private readonly MessageStorage $messageStorage,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // After TicketIssuer (0) and invoice email (-5).
      OrderEvents::ORDER_PAID => ['onOrderPaid', -15],
    ];
  }

  /**
   * Queues a single resend when tickets now exist but confirmation sent earlier.
   */
  public function onOrderPaid(OrderEvent $event): void {
    $order = $event->getOrder();
    if (!$order instanceof OrderInterface) {
      return;
    }

    $orderId = (int) $order->id();
    if ($orderId < 1) {
      return;
    }

    if ($this->state->get(self::recoveryStateKey($orderId), FALSE)) {
      return;
    }

    if (!$this->orderHasVariationLineItemsEligibleForTickets($order)) {
      return;
    }

    if (!$this->entityTypeManager->hasDefinition('myeventlane_ticket')) {
      return;
    }

    $mail = $order->getEmail();
    if (!$mail) {
      $customer = $order->getCustomer();
      $mail = $customer ? $customer->getEmail() : NULL;
    }
    if (!$mail || trim($mail) === '') {
      return;
    }

    $ticketIds = $this->entityTypeManager->getStorage('myeventlane_ticket')->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $orderId)
      ->execute();
    if (!$ticketIds || !is_array($ticketIds)) {
      return;
    }

    $minTicketCreated = $this->minTicketCreatedTimestamp($ticketIds);
    if ($minTicketCreated === NULL) {
      return;
    }

    $sentRows = $this->messageStorage->findSentOrderConfirmationsForOrder($orderId, trim($mail));
    $earliestSent = $this->earliestSentTimestamp($sentRows);
    if ($earliestSent === NULL || $earliestSent < 1) {
      return;
    }

    if ($minTicketCreated <= $earliestSent) {
      return;
    }

    $messageId = $this->orderConfirmationQueue->queue($order, trim($mail), TRUE);
    if ($messageId) {
      $this->state->set(self::recoveryStateKey($orderId), (int) time());
      $this->logger->info('ORDER_PAID: queued ticket PDF recovery order_confirmation resend for order @order_id message_id=@mid', [
        '@order_id' => (string) $orderId,
        '@mid' => $messageId,
        'order_id' => $orderId,
        'message_type' => 'order_confirmation',
      ]);
    }
    else {
      $this->logger->warning('ORDER_PAID: ticket PDF recovery resend not queued (duplicate or failure) for order @order_id', [
        '@order_id' => (string) $orderId,
        'order_id' => $orderId,
      ]);
    }
  }

  /**
   * @param object[] $rows
   *   Message rows from MessageStorage.
   */
  private function earliestSentTimestamp(array $rows): ?int {
    $min = NULL;
    foreach ($rows as $row) {
      $s = isset($row->sent) ? (int) $row->sent : 0;
      if ($s > 0 && ($min === NULL || $s < $min)) {
        $min = $s;
      }
    }
    return $min;
  }

  /**
   * @param string[]|int[] $ticketIds
   */
  private function minTicketCreatedTimestamp(array $ticketIds): ?int {
    $storage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    $tickets = $storage->loadMultiple($ticketIds);
    $min = NULL;
    foreach ($tickets as $ticket) {
      if (!$ticket->hasField('created') || $ticket->get('created')->isEmpty()) {
        continue;
      }
      $created = (int) $ticket->get('created')->value;
      if ($created > 0 && ($min === NULL || $created < $min)) {
        $min = $created;
      }
    }
    return $min;
  }

  /**
   * True when the order has commerce line items that TicketIssuer attempts.
   */
  private function orderHasVariationLineItemsEligibleForTickets(OrderInterface $order): bool {
    foreach ($order->getItems() as $item) {
      if ($item->bundle() === 'boost') {
        continue;
      }
      $purchased = $item->getPurchasedEntity();
      if ($purchased && $purchased->getEntityTypeId() === 'commerce_product_variation') {
        return TRUE;
      }
    }
    return FALSE;
  }

}
