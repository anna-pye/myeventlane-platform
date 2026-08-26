<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_capacity\Exception\CapacityExceededException;
use Drupal\myeventlane_capacity\Service\CapacityOrderInspector;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;
use Drupal\node\NodeInterface;

/**
 * Keeps cart ticket reservations and their customer-facing timer in sync.
 */
final class CartTicketHoldManager {

  public function __construct(
    private readonly EventCapacityServiceInterface $capacity,
    private readonly CapacityOrderInspector $orderInspector,
    private readonly CartTicketAvailabilityInterface $ticketAvailability,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly Connection $database,
  ) {}

  /**
   * Stable reservation key shared by cart, checkout, and completion cleanup.
   */
  public static function reservationKey(int $orderId, int $eventId): string {
    return 'cart:' . $orderId . ':event:' . $eventId;
  }

  /**
   * Revalidates every ticket line and refreshes limited-event reservations.
   *
   * @throws \Drupal\myeventlane_capacity\Exception\CapacityExceededException
   *   When an event or ticket tier can no longer satisfy the cart.
   */
  public function refresh(OrderInterface $cart): void {
    $orderId = (int) $cart->id();
    if ($orderId < 1) {
      throw new CapacityExceededException('We could not hold these tickets. Please try adding them again.');
    }

    $eventTotals = $this->orderInspector->extractEventQuantities($cart);
    $byVariation = $this->orderInspector->extractEventVariationQuantities($cart);
    if ($byVariation === []) {
      return;
    }

    $eventStorage = $this->entityTypeManager->getStorage('node');
    $variationStorage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $events = $eventStorage->loadMultiple(array_keys($byVariation));

    $variationIds = [];
    foreach ($byVariation as $variations) {
      $variationIds = array_merge($variationIds, array_keys($variations));
    }
    $variations = $variationStorage->loadMultiple(array_unique($variationIds));

    ksort($byVariation, SORT_NUMERIC);
    $validatedEvents = [];
    foreach ($byVariation as $eventId => $eventVariations) {
      $event = $events[$eventId] ?? NULL;
      if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
        throw new CapacityExceededException('This event is no longer available.');
      }

      $requestedTotal = (int) ($eventTotals[$eventId] ?? 0);
      ksort($eventVariations, SORT_NUMERIC);
      foreach ($eventVariations as $variationId => $quantity) {
        $variation = $variations[$variationId] ?? NULL;
        if (!$variation instanceof ProductVariationInterface || $variation->getProduct() === NULL) {
          throw new CapacityExceededException('This ticket is no longer available.');
        }

        $this->ticketAvailability->assertPaidVariationLineConstraints(
          $event,
          $variation->getProduct(),
          $variation,
          (int) $quantity,
        );
      }
      $validatedEvents[(int) $eventId] = [
        'event' => $event,
        'quantity' => $requestedTotal,
      ];
    }

    // Only write reservations after every tier has passed validation. The
    // outer transaction also prevents a later event failure from leaving an
    // earlier event's hold refreshed.
    ksort($validatedEvents, SORT_NUMERIC);
    $transaction = $this->database->startTransaction();
    try {
      foreach ($validatedEvents as $eventId => $validatedEvent) {
        $this->ticketAvailability->assertEventTotalBookable(
          $validatedEvent['event'],
          $validatedEvent['quantity'],
          self::reservationKey($orderId, $eventId),
        );
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Builds an authoritative timer state for a cart or checkout order.
   *
   * Unlimited-capacity events deliberately return state=none because no
   * inventory is being held for them.
   *
   * @return array{
   *   state: 'none'|'active'|'expired',
   *   has_hold: bool,
   *   server_now: int,
   *   duration: int,
   *   created_at: int|null,
   *   expires_at: int|null,
   *   seconds_remaining: int
   *   }
   */
  public function summary(OrderInterface $cart): array {
    $now = $this->time->getRequestTime();
    $duration = $this->capacity->getReservationTtl();
    $none = [
      'state' => 'none',
      'has_hold' => FALSE,
      'server_now' => $now,
      'duration' => $duration,
      'created_at' => NULL,
      'expires_at' => NULL,
      'seconds_remaining' => 0,
    ];

    $orderId = (int) $cart->id();
    $eventTotals = $this->orderInspector->extractEventQuantities($cart);
    if ($orderId < 1 || $eventTotals === []) {
      return $none;
    }

    $events = $this->entityTypeManager->getStorage('node')->loadMultiple(array_keys($eventTotals));
    $activeReservations = [];
    $limitedEventCount = 0;

    foreach ($eventTotals as $eventId => $quantity) {
      if ($quantity < 1) {
        continue;
      }
      $event = $events[$eventId] ?? NULL;
      if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
        continue;
      }
      if ($this->capacity->getCapacityTotal($event) === NULL) {
        continue;
      }

      $limitedEventCount++;
      $reservation = $this->capacity->getActiveReservation(
        self::reservationKey($orderId, (int) $eventId),
      );
      if ($reservation === NULL || $reservation['quantity'] !== (int) $quantity) {
        return [
          ...$none,
          'state' => 'expired',
          'has_hold' => TRUE,
        ];
      }
      $activeReservations[] = $reservation;
    }

    if ($limitedEventCount === 0) {
      return $none;
    }

    usort(
      $activeReservations,
      static fn (array $a, array $b): int => $a['expires'] <=> $b['expires'],
    );
    $earliest = $activeReservations[0];
    $secondsRemaining = max(0, $earliest['expires'] - $now);

    return [
      'state' => $secondsRemaining > 0 ? 'active' : 'expired',
      'has_hold' => TRUE,
      'server_now' => $now,
      'duration' => $duration,
      'created_at' => $earliest['created'],
      'expires_at' => $earliest['expires'],
      'seconds_remaining' => $secondsRemaining,
    ];
  }

  /**
   * Releases the reservation for one event on an order.
   */
  public function releaseEvent(OrderInterface $cart, int $eventId): void {
    $orderId = (int) $cart->id();
    if ($orderId > 0 && $eventId > 0) {
      $this->capacity->releaseReservation(self::reservationKey($orderId, $eventId));
    }
  }

  /**
   * Releases reservations represented by removed cart items.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   Cart whose reservations should be released.
   * @param \Drupal\commerce_order\Entity\OrderItemInterface[] $items
   *   Removed ticket order items.
   */
  public function releaseItems(OrderInterface $cart, array $items): void {
    $eventIds = [];
    foreach ($items as $item) {
      if (!$item instanceof OrderItemInterface
        || !$item->hasField('field_target_event')
        || $item->get('field_target_event')->isEmpty()) {
        continue;
      }
      $eventId = (int) $item->get('field_target_event')->target_id;
      if ($eventId > 0) {
        $eventIds[$eventId] = $eventId;
      }
    }
    foreach ($eventIds as $eventId) {
      $this->releaseEvent($cart, $eventId);
    }
  }

}
