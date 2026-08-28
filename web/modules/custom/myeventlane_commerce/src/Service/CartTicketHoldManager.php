<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_capacity\Exception\CapacityExceededException;
use Drupal\myeventlane_capacity\Service\CapacityOrderInspector;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;
use Drupal\node\NodeInterface;

/**
 * Keeps cart ticket reservations and their customer-facing timer in sync.
 */
final class CartTicketHoldManager {

  private const LOCK_TIMEOUT = 30.0;

  public function __construct(
    private readonly EventCapacityServiceInterface $capacity,
    private readonly CapacityOrderInspector $orderInspector,
    private readonly CartTicketAvailabilityInterface $ticketAvailability,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly Connection $database,
    private readonly CartTicketTierHoldStoreInterface $tierHolds,
    private readonly LockBackendInterface $lock,
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

    $eventIds = array_map('intval', array_keys($byVariation));
    sort($eventIds, SORT_NUMERIC);
    $acquiredLocks = [];
    foreach ($eventIds as $eventId) {
      $lockName = 'mel_ticket_capacity:' . $eventId;
      if (!$this->lock->acquire($lockName, self::LOCK_TIMEOUT)) {
        foreach ($acquiredLocks as $acquiredLock) {
          $this->lock->release($acquiredLock);
        }
        throw new CapacityExceededException(
          'Ticket availability is being updated. Please try again.',
        );
      }
      $acquiredLocks[] = $lockName;
    }

    try {
      ksort($byVariation, SORT_NUMERIC);
      $validatedEvents = [];
      foreach ($byVariation as $eventId => $eventVariations) {
        $event = $events[$eventId] ?? NULL;
        if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
          throw new CapacityExceededException('This event is no longer available.');
        }

        $requestedTotal = (int) ($eventTotals[$eventId] ?? 0);
        $validatedTierHolds = [];
        ksort($eventVariations, SORT_NUMERIC);
        foreach ($eventVariations as $variationId => $quantity) {
          $variation = $variations[$variationId] ?? NULL;
          if (!$variation instanceof ProductVariationInterface || $variation->getProduct() === NULL) {
            throw new CapacityExceededException('This ticket is no longer available.');
          }

          $tier = $this->ticketAvailability->resolveTierForVariation($event, $variation);
          if (!$tier instanceof TicketTypeInterface) {
            throw new CapacityExceededException('This ticket is no longer available.');
          }
          $tierId = (int) $tier->id();
          $tierReservationKey = CartTicketTierHoldStore::reservationKey(
            $orderId,
            (int) $eventId,
            $tierId,
          );
          $this->ticketAvailability->assertPaidVariationLineConstraints(
            $event,
            $variation->getProduct(),
            $variation,
            (int) $quantity,
            NULL,
            NULL,
            $tierReservationKey,
          );

          $capacityField = $tier->get('capacity');
          $capacityValues = $capacityField->getValue();
          $tierCapacity = $capacityField->isEmpty()
            ? NULL
            : (int) ($capacityValues[0]['value'] ?? 0);
          if ($tierCapacity !== NULL && $tierCapacity > 0) {
            $offerExpiry = $this->ticketAvailability
              ->getActiveWaitlistOfferExpiry($event, $variation);
            if (!isset($validatedTierHolds[$tierId])) {
              $validatedTierHolds[$tierId] = [
                'variation_id' => (int) $variationId,
                'quantity' => 0,
                'offer_expiry' => $offerExpiry,
              ];
            }
            $validatedTierHolds[$tierId]['quantity'] += (int) $quantity;
          }
        }
        $validatedEvents[(int) $eventId] = [
          'event' => $event,
          'quantity' => $requestedTotal,
          'tier_holds' => $validatedTierHolds,
        ];
      }

      // Only write reservations after every tier has passed validation. The
      // outer transaction also prevents a later event failure from leaving an
      // earlier event's hold refreshed.
      ksort($validatedEvents, SORT_NUMERIC);
      $transaction = $this->database->startTransaction();
      try {
        foreach ($validatedEvents as $eventId => $validatedEvent) {
          $eventReservationKey = self::reservationKey($orderId, $eventId);
          if ($this->capacity->getCapacityTotal($validatedEvent['event']) === NULL) {
            $this->capacity->releaseReservation($eventReservationKey);
          }
          else {
            $this->ticketAvailability->assertEventTotalBookable(
              $validatedEvent['event'],
              $validatedEvent['quantity'],
              $eventReservationKey,
            );
          }

          $validTierReservationKeys = [];
          foreach ($validatedEvent['tier_holds'] as $tierId => $tierHold) {
            $tierReservationKey = CartTicketTierHoldStore::reservationKey(
              $orderId,
              $eventId,
              $tierId,
            );
            $validTierReservationKeys[] = $tierReservationKey;
            $this->tierHolds->upsert(
              $orderId,
              $eventId,
              $tierId,
              $tierHold['variation_id'],
              $tierHold['quantity'],
              $tierHold['offer_expiry'] === NULL,
              $tierHold['offer_expiry'],
            );
          }
          $this->tierHolds->releaseStaleForEvent(
            $orderId,
            $eventId,
            $validTierReservationKeys,
          );
        }
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
    }
    finally {
      foreach ($acquiredLocks as $acquiredLock) {
        $this->lock->release($acquiredLock);
      }
    }
  }

  /**
   * Builds an authoritative timer state for a cart or checkout order.
   *
   * Fully unlimited events return state=none because no inventory is held.
   * A finite ticket tier still receives a hold when global event capacity is
   * unlimited.
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

    $byVariation = $this->orderInspector->extractEventVariationQuantities($cart);
    $events = $this->entityTypeManager->getStorage('node')->loadMultiple(array_keys($eventTotals));
    $variationIds = [];
    foreach ($byVariation as $eventVariations) {
      $variationIds = array_merge($variationIds, array_keys($eventVariations));
    }
    $variations = $this->entityTypeManager
      ->getStorage('commerce_product_variation')
      ->loadMultiple(array_unique($variationIds));
    $activeReservations = [];
    $expectedHoldCount = 0;

    foreach ($eventTotals as $eventId => $quantity) {
      if ($quantity < 1) {
        continue;
      }
      $event = $events[$eventId] ?? NULL;
      if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
        continue;
      }
      if ($this->capacity->getCapacityTotal($event) !== NULL) {
        $expectedHoldCount++;
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

      $expectedTierHolds = [];
      foreach ($byVariation[$eventId] ?? [] as $variationId => $tierQuantity) {
        $variation = $variations[$variationId] ?? NULL;
        if (!$variation instanceof ProductVariationInterface) {
          continue;
        }
        $tier = $this->ticketAvailability->resolveTierForVariation($event, $variation);
        if (!$tier instanceof TicketTypeInterface || $tier->get('capacity')->isEmpty()) {
          continue;
        }
        $capacityValues = $tier->get('capacity')->getValue();
        $tierCapacity = (int) ($capacityValues[0]['value'] ?? 0);
        if ($tierCapacity < 1) {
          continue;
        }

        $tierId = (int) $tier->id();
        if (!isset($expectedTierHolds[$tierId])) {
          $expectedTierHolds[$tierId] = 0;
        }
        $expectedTierHolds[$tierId] += (int) $tierQuantity;
      }

      foreach ($expectedTierHolds as $tierId => $tierQuantity) {
        $expectedHoldCount++;
        $tierReservation = $this->tierHolds->getActive(
          $orderId,
          (int) $eventId,
          $tierId,
        );
        if ($tierReservation === NULL || $tierReservation['quantity'] !== (int) $tierQuantity) {
          return [
            ...$none,
            'state' => 'expired',
            'has_hold' => TRUE,
          ];
        }
        $activeReservations[] = $tierReservation;
      }
    }

    if ($expectedHoldCount === 0) {
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
      $this->tierHolds->releaseEvent($orderId, $eventId);
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
