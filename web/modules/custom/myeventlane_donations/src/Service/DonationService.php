<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for donation-related operations and analytics.
 */
final class DonationService {

  /**
   * Constructs a DonationService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Gets donation statistics for a specific event.
   *
   * @param int $eventId
   *   The event node ID.
   *
   * @return array
   *   Array with 'total' (float) and 'count' (int) keys.
   */
  public function getEventDonationStats(int $eventId): array {
    $total = 0.0;
    $count = 0;

    try {
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');

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
            $order = $this->entityTypeManager
              ->getStorage('commerce_order')
              ->load($order_id);
            if ($order && $order->getState()->getId() === 'completed') {
              $totalPrice = $orderItem->getTotalPrice();
              if ($totalPrice) {
                $total += (float) $totalPrice->getNumber();
                $count++;
              }
            }
          }
          catch (\Exception $e) {
            $this->loggerFactory->get('myeventlane_donations')->warning('Failed to process donation order item: @message', [
              '@message' => $e->getMessage(),
            ]);
            continue;
          }
        }
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('myeventlane_donations')->error('Failed to get event donation stats: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return [
      'total' => $total,
      'count' => $count,
    ];
  }

  /**
   * Gets platform donation statistics.
   *
   * @return array
   *   Array with 'total' (float) and 'count' (int) keys.
   */
  public function getPlatformDonationStats(): array {
    $total = 0.0;
    $count = 0;

    try {
      $orderStorage = $this->entityTypeManager->getStorage('commerce_order');
      $orderIds = $orderStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'platform_donation')
        ->condition('state', 'completed')
        ->execute();

      if (!empty($orderIds)) {
        $orders = $orderStorage->loadMultiple($orderIds);
        foreach ($orders as $order) {
          $totalPrice = $order->getTotalPrice();
          if ($totalPrice) {
            $total += (float) $totalPrice->getNumber();
            $count++;
          }
        }
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('myeventlane_donations')->error('Failed to get platform donation stats: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return [
      'total' => $total,
      'count' => $count,
    ];
  }

  /**
   * Gets RSVP donation statistics.
   *
   * @return array
   *   Array with 'total' (float) and 'count' (int) keys.
   */
  public function getRsvpDonationStats(): array {
    $total = 0.0;
    $count = 0;

    try {
      $orderStorage = $this->entityTypeManager->getStorage('commerce_order');
      $orderIds = $orderStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'rsvp_donation')
        ->condition('state', 'completed')
        ->execute();

      if (!empty($orderIds)) {
        $orders = $orderStorage->loadMultiple($orderIds);
        foreach ($orders as $order) {
          $totalPrice = $order->getTotalPrice();
          if ($totalPrice) {
            $total += (float) $totalPrice->getNumber();
            $count++;
          }
        }
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('myeventlane_donations')->error('Failed to get RSVP donation stats: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return [
      'total' => $total,
      'count' => $count,
    ];
  }

}
