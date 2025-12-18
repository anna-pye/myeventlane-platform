<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Scheduler;

use Drupal\Component\Datetime\TimeInterface as DrupalTimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Psr\Log\LoggerInterface;

/**
 * Finds carts inactive for > N minutes (default 60) and sends one nudge.
 */
final class CartAbandonedScheduler {

  /**
   * Constructs a CartAbandonedScheduler.
   */
  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly DrupalTimeInterface $time,
    private readonly EntityTypeManagerInterface $etm,
    private readonly QueueFactory $queue,
    private readonly StateInterface $state,
    private readonly MessagingManager $messaging,
  ) {}

  /**
   * Scans for abandoned carts and queues reminder emails.
   *
   * @param int $minutes
   *   Minutes of inactivity to consider a cart abandoned.
   */
  public function scan(int $minutes = 60): void {
    $threshold = $this->time->getRequestTime() - ($minutes * 60);

    // Commerce order has 'cart' flag; use accessCheck(FALSE) for system scan.
    $q = $this->etm->getStorage('commerce_order')->getQuery()
      ->condition('cart', 1)
      ->condition('changed', $threshold, '<=')
      ->accessCheck(FALSE);

    $ids = $q->execute();
    if (!$ids) {
      $this->logger->notice('Cart abandoned scan: no candidates.');
      return;
    }

    $orders = $this->etm->getStorage('commerce_order')->loadMultiple($ids);
    $count = 0;

    foreach ($orders as $order) {
      $mail = $order->getEmail() ?: ($order->getCustomer() ? $order->getCustomer()->getEmail() : NULL);
      if (!$mail) {
        continue;
      }

      $state_key = "mel.msg.cart.sent.{$order->id()}";
      if ($this->state->get($state_key)) {
        // Already nudged once.
        continue;
      }

      $url = Url::fromRoute('commerce_cart.page', [], ['absolute' => TRUE])
        ->toString(TRUE)
        ->getGeneratedUrl();

      $this->messaging->queue('cart_abandoned', $mail, [
        'first_name' => $order->getCustomer() ? $order->getCustomer()->getDisplayName() : 'there',
        'cart_url' => $url,
      ], ['langcode' => $order->language()->getId()]);

      $this->state->set($state_key, $this->time->getRequestTime());
      $count++;
    }

    $this->logger->info('CartAbandonedScheduler: queued @n messages.', ['@n' => $count]);
  }

}
