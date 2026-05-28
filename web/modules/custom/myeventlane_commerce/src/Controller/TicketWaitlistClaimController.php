<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Controller;

use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\mel_ticket\Entity\TicketWaitlistEntry;
use Drupal\myeventlane_capacity\Exception\CapacityExceededException;
use Drupal\myeventlane_capacity\Service\CapacityOrderInspector;
use Drupal\myeventlane_commerce\Service\TicketAvailabilityService;
use Drupal\myeventlane_commerce\Service\TicketBookingSessionService;
use Drupal\myeventlane_commerce\Service\TicketCapacityService;
use Drupal\myeventlane_commerce\Service\TicketTierWaitlistService;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Activates a session-scoped waitlist purchase offer from a token link.
 */
final class TicketWaitlistClaimController extends ControllerBase {

  public function __construct(
    private readonly TicketTierWaitlistService $tierWaitlist,
    private readonly TicketBookingSessionService $bookingSession,
    private readonly CartProviderInterface $cartProvider,
    private readonly CartManagerInterface $cartManager,
    private readonly TicketAvailabilityService $ticketAvailability,
    private readonly CapacityOrderInspector $orderInspector,
    private readonly ?TicketCapacityService $ticketCapacity,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_commerce.ticket_tier_waitlist'),
      $container->get('myeventlane_commerce.ticket_booking_session'),
      $container->get('commerce_cart.cart_provider'),
      $container->get('commerce_cart.cart_manager'),
      $container->get('myeventlane_commerce.ticket_availability'),
      $container->get('myeventlane_capacity.order_inspector'),
      $container->has('myeventlane_commerce.ticket_capacity')
        ? $container->get('myeventlane_commerce.ticket_capacity')
        : NULL,
      $container->get('logger.channel.myeventlane_commerce'),
    );
  }

  /**
   * Claims a waitlist offer, adds reserved tickets to the cart, and redirects.
   */
  public function claim(NodeInterface $node, string $token): RedirectResponse {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }

    $bookUrl = Url::fromRoute('myeventlane_commerce.event_book', ['node' => $node->id()], ['absolute' => TRUE])->toString();

    $entry = $this->tierWaitlist->loadOfferByToken($token);
    if (!$entry instanceof TicketWaitlistEntry) {
      $this->messenger()->addError($this->t('This waitlist link is not valid. Request a new offer from the event page if tickets are still waitlisted.'));
      return new RedirectResponse($bookUrl);
    }

    if ((int) $entry->get('event')->target_id !== (int) $node->id()) {
      $this->messenger()->addError(
        $this->t('This waitlist link doesn’t match this event. Please return to the event page and request a new offer if needed.')
      );
      return new RedirectResponse($bookUrl);
    }

    if ($entry->get('status')->value !== TicketWaitlistEntry::STATUS_OFFERED) {
      $this->messenger()->addWarning($this->t('This waitlist offer is no longer active.'));
      return new RedirectResponse($bookUrl);
    }

    if (!$this->tierWaitlist->isOfferClaimable($entry)) {
      $this->messenger()->addWarning($this->t('This waitlist offer has expired. You can join the waitlist again from the event page if it is still available.'));
      return new RedirectResponse($bookUrl);
    }

    $this->bookingSession->setWaitlistClaimEntryId((int) $node->id(), (int) $entry->id());

    $result = $this->populateCartFromOffer($node, $entry);
    if ($result !== NULL && $result['tickets_ready']) {
      $this->messenger()->addStatus(
        $result['added']
          ? $this->t('Your reserved tickets were added to your cart. Complete checkout before your offer expires.')
          : $this->t('Your reserved tickets are already in your cart. Complete checkout before your offer expires.')
      );
      $checkoutUrl = Url::fromRoute('commerce_checkout.form', [
        'commerce_order' => $result['cart']->id(),
      ], ['absolute' => TRUE])->toString();
      return new RedirectResponse($checkoutUrl);
    }

    if ($result === NULL) {
      return new RedirectResponse($bookUrl);
    }

    $this->messenger()->addStatus(
      $this->t('Your waitlist spot is active for this event. Choose your reserved tickets on the booking page, then continue to checkout.')
    );
    return new RedirectResponse($bookUrl);
  }

  /**
   * Adds the waitlist offer tier and reserved quantity to the active cart.
   *
   * @return array{cart: \Drupal\commerce_order\Entity\OrderInterface, tickets_ready: bool, added: bool}|null
   *   Cart state when reserved tickets are in the cart; NULL on failure.
   */
  private function populateCartFromOffer(NodeInterface $event, TicketWaitlistEntry $entry): ?array {
    $tier = $entry->get('ticket_type')->entity;
    if (!$tier instanceof TicketTypeInterface) {
      $this->logger->error('Waitlist claim cart: missing ticket type for entry @id.', ['@id' => (string) $entry->id()]);
      return NULL;
    }

    if ($tier->get('commerce_variation')->isEmpty()) {
      $this->logger->error('Waitlist claim cart: tier @tid has no commerce variation.', ['@tid' => (string) $tier->id()]);
      return NULL;
    }

    $variation = $tier->get('commerce_variation')->entity;
    if (!$variation instanceof ProductVariationInterface || !$variation->isPublished()) {
      $this->logger->error('Waitlist claim cart: variation missing or unpublished for entry @id.', ['@id' => (string) $entry->id()]);
      return NULL;
    }

    $product = $variation->getProduct();
    if (!$product instanceof ProductInterface) {
      return NULL;
    }

    $stores = $product->getStores();
    if ($stores === []) {
      $this->logger->error('Waitlist claim cart: no store for product @pid.', ['@pid' => (string) $product->id()]);
      return NULL;
    }
    $store = reset($stores);

    $offerQuantity = (int) ($entry->get('offer_reserved')->value ?? 0);
    if ($offerQuantity < 1) {
      $offerQuantity = max(1, (int) ($entry->get('quantity')->value ?? 1));
    }
    if ($this->ticketCapacity instanceof TicketCapacityService) {
      $offerQuantity = min(
        $offerQuantity,
        $this->ticketCapacity->getBuyerLimitForOffer($event, $tier, (int) $variation->id(), $entry),
      );
    }
    if ($offerQuantity < 1) {
      $this->messenger()->addWarning($this->t('Your reserved tickets are no longer available. Please return to the event page.'));
      return NULL;
    }

    try {
      $cart = $this->cartProvider->getCart('default', $store)
        ?: $this->cartProvider->createCart('default', $store);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Waitlist claim cart: could not load cart for entry @id: @message', [
        '@id' => (string) $entry->id(),
        '@message' => $exception->getMessage(),
      ]);
      $this->messenger()->addError($this->t('We could not add your reserved tickets to the cart. Please try again from the booking page.'));
      return NULL;
    }

    $pending = $this->getPendingCartTicketTotals($event, $cart);
    $variationId = (int) $variation->id();
    $existingLine = (int) ($pending['variation'][$variationId] ?? 0);
    $quantityToAdd = max(0, $offerQuantity - $existingLine);
    $combinedLine = $existingLine + $quantityToAdd;

    try {
      $this->ticketAvailability->assertPaidVariationLineConstraints($event, $product, $variation, $combinedLine);
      $this->ticketAvailability->assertEventTotalBookable(
        $event,
        $pending['event_total'] + $quantityToAdd,
      );
    }
    catch (CapacityExceededException $exception) {
      $this->messenger()->addWarning($exception->getMessage());
      return NULL;
    }

    if ($quantityToAdd < 1) {
      if ($existingLine >= $offerQuantity && $existingLine > 0) {
        return [
          'cart' => $cart,
          'tickets_ready' => TRUE,
          'added' => FALSE,
        ];
      }
      $this->messenger()->addWarning($this->t('Your reserved tickets are no longer available. Please return to the event page.'));
      return NULL;
    }

    try {
      $orderItem = $this->cartManager->addEntity($cart, $variation, $quantityToAdd, TRUE);
      if ($orderItem === NULL) {
        $this->logger->error('Waitlist claim cart: addEntity returned no order item for entry @id.', [
          '@id' => (string) $entry->id(),
        ]);
        $this->messenger()->addError($this->t('We could not add your reserved tickets to the cart. Please try again from the booking page.'));
        return NULL;
      }
      if ($orderItem->hasField('field_target_event')) {
        $orderItem->set('field_target_event', ['target_id' => $event->id()]);
        $orderItem->save();
      }
      $cart->save();

      $after = $this->getPendingCartTicketTotals($event, $cart);
      $lineAfter = (int) ($after['variation'][$variationId] ?? 0);
      if ($lineAfter < $offerQuantity) {
        $this->logger->error('Waitlist claim cart: cart line quantity @qty below offer @offer for entry @id.', [
          '@qty' => (string) $lineAfter,
          '@offer' => (string) $offerQuantity,
          '@id' => (string) $entry->id(),
        ]);
        $this->messenger()->addError($this->t('We could not add your reserved tickets to the cart. Please try again from the booking page.'));
        return NULL;
      }

      return [
        'cart' => $cart,
        'tickets_ready' => TRUE,
        'added' => TRUE,
      ];
    }
    catch (\Throwable $exception) {
      $this->logger->error('Waitlist claim cart failed for entry @id: @message', [
        '@id' => (string) $entry->id(),
        '@message' => $exception->getMessage(),
      ]);
      $this->messenger()->addError($this->t('We could not add your reserved tickets to the cart. Please try again from the booking page.'));
      return NULL;
    }
  }

  /**
   * Ticket quantities already in the cart for this event.
   *
   * @return array{event_total: int, variation: array<int, int>}
   */
  private function getPendingCartTicketTotals(NodeInterface $event, OrderInterface $cart): array {
    $eid = (int) $event->id();
    $by_variation = $this->orderInspector->extractEventVariationQuantities($cart);
    $event_totals = $this->orderInspector->extractEventQuantities($cart);
    return [
      'event_total' => (int) ($event_totals[$eid] ?? 0),
      'variation' => $by_variation[$eid] ?? [],
    ];
  }

}
