<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\myeventlane_capacity\Exception\CapacityExceededException;
use Drupal\myeventlane_capacity\Service\CapacityOrderInspector;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\myeventlane_commerce\Service\TicketAvailabilityService;
use Drupal\node\NodeInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Serializes ticket capacity checks per event during order placement.
 *
 * Per-event locks are held until the HTTP request terminates so concurrent
 * checkouts cannot pass validation in the gap before the order completes.
 */
final class TicketCapacityOrderSubscriber implements EventSubscriberInterface {

  private const LOCK_TIMEOUT = 30;

  /**
   * Request attribute: list of acquired mel_ticket_capacity:* lock names.
   */
  private const CAPACITY_LOCK_ATTR = 'mel_ticket_capacity.locks';

  public function __construct(
    private readonly LockBackendInterface $lock,
    private readonly TicketAvailabilityService $ticketAvailability,
    private readonly LoggerInterface $logger,
    private readonly CapacityOrderInspector $orderInspector,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.place.pre_transition' => ['onOrderPlacePreTransition', 50],
      KernelEvents::TERMINATE => ['onKernelTerminate', -200],
    ];
  }

  public function onOrderPlacePreTransition(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if (!$order instanceof OrderInterface) {
      return;
    }

    $event_totals = $this->orderInspector->extractEventQuantities($order);
    if ($event_totals === []) {
      return;
    }

    $event_ids = array_keys($event_totals);
    sort($event_ids, SORT_NUMERIC);

    $acquired = [];
    foreach ($event_ids as $eid) {
      $name = 'mel_ticket_capacity:' . $eid;
      if (!$this->lock->acquire($name, self::LOCK_TIMEOUT)) {
        foreach ($acquired as $prior) {
          $this->lock->release($prior);
        }
        $this->logger->warning(
          'Ticket capacity lock not acquired for event @eid during order @oid placement.',
          ['@eid' => (string) $eid, '@oid' => (string) $order->id()]
        );
        throw new CapacityExceededException('Please retry');
      }
      $acquired[] = $name;
    }

    try {
      $by_variation = $this->orderInspector->extractEventVariationQuantities($order);
      $node_storage = $this->entityTypeManager->getStorage('node');
      $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');

      foreach ($by_variation as $event_id => $variations) {
        $event_node = $node_storage->load($event_id);
        if (!$event_node instanceof NodeInterface || $event_node->bundle() !== 'event') {
          $this->logger->notice(
            'Order @oid references missing or non-event node @nid for ticket lines.',
            ['@oid' => (string) $order->id(), '@nid' => (string) $event_id]
          );
          continue;
        }
        $requested_total = (int) ($event_totals[$event_id] ?? 0);
        foreach ($variations as $variation_id => $qty) {
          $variation = $variation_storage->load($variation_id);
          if ($variation instanceof ProductVariationInterface) {
            $variation_product = $variation->getProduct();
            if ($variation_product !== NULL
              && OperationalMerchandiseManager::isOperationalProductBundle($variation_product->bundle())) {
              continue;
            }
          }
          if (!$variation instanceof ProductVariationInterface) {
            $this->logger->error(
              'Orphan ticket variation @vid on order @oid (event @eid): variation entity missing.',
              [
                '@vid' => (string) $variation_id,
                '@oid' => (string) $order->id(),
                '@eid' => (string) $event_id,
              ]
            );
            throw new CapacityExceededException('This ticket is not available for this event.');
          }
          $product = $variation->getProduct();
          if (!$product) {
            $this->logger->error(
              'Variation @vid on order @oid has no product.',
              ['@vid' => (string) $variation_id, '@oid' => (string) $order->id()]
            );
            throw new CapacityExceededException('This ticket is not available for this event.');
          }
          try {
            $this->ticketAvailability->assertPaidLineAndEventTotal(
              $event_node,
              $product,
              $variation,
              (int) $qty,
              $requested_total
            );
          }
          catch (CapacityExceededException $e) {
            $this->logger->error(
              'Capacity validation failed at placement: order @oid event @eid variation @vid — @msg',
              [
                '@oid' => (string) $order->id(),
                '@eid' => (string) $event_id,
                '@vid' => (string) $variation_id,
                '@msg' => $e->getMessage(),
              ]
            );
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
          }
        }
      }

      $request = $this->requestStack->getCurrentRequest();
      if ($request) {
        $request->attributes->set(self::CAPACITY_LOCK_ATTR, $acquired);
        $acquired = [];
      }
      else {
        foreach ($acquired as $name) {
          $this->lock->release($name);
        }
        $acquired = [];
      }
    }
    catch (\Throwable $e) {
      foreach ($acquired as $name) {
        $this->lock->release($name);
      }
      throw $e;
    }
  }

  /**
   * Releases per-event capacity locks after the response is sent.
   */
  public function onKernelTerminate(TerminateEvent $event): void {
    $request = $event->getRequest();
    $locks = $request->attributes->get(self::CAPACITY_LOCK_ATTR);
    if (!is_array($locks) || $locks === []) {
      return;
    }
    foreach ($locks as $name) {
      if (is_string($name) && $name !== '') {
        $this->lock->release($name);
      }
    }
    $request->attributes->remove(self::CAPACITY_LOCK_ATTR);
  }

}
