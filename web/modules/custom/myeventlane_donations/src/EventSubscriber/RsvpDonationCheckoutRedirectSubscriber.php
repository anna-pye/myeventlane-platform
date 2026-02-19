<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects rsvp_donation orders from checkout complete to RSVP thank-you page.
 *
 * Handles both onsite completion (form submit) and offsite return (Stripe).
 * Commerce checkout does not honour the destination param, so we redirect here.
 *
 * IMPORTANT: Only redirect when the order is placed (state !== draft). Otherwise
 * the user may land on thank-you with an unconfirmed order (e.g. direct nav to
 * complete, or a race). Let the Checkout controller handle draft orders.
 */
final class RsvpDonationCheckoutRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
    ];
  }

  /**
   * Redirects rsvp_donation complete step to thank-you page.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    if ($this->routeMatch->getRouteName() !== 'commerce_checkout.form') {
      return;
    }

    $step = $this->routeMatch->getParameter('step');
    if ($step !== 'complete') {
      return;
    }

    $order = $this->getOrder();
    if (!$order || $order->bundle() !== 'rsvp_donation') {
      return;
    }

    // Only redirect when order is placed. Draft = checkout not completed.
    if ($order->getState()->getId() === 'draft') {
      return;
    }

    $event_id = $this->getEventIdFromOrder($order);
    if (!$event_id || !\Drupal::moduleHandler()->moduleExists('myeventlane_rsvp')) {
      return;
    }

    $url = Url::fromRoute('myeventlane_rsvp.thankyou', [
      'event' => $event_id,
    ], [
      'query' => ['order' => $order->id()],
    ]);

    $event->setResponse(new RedirectResponse($url->toString()));
  }

  private function getOrder(): ?OrderInterface {
    $param = $this->routeMatch->getParameter('commerce_order');
    if ($param instanceof OrderInterface) {
      return $param;
    }
    $order_id = (int) $param;
    if ($order_id <= 0) {
      return NULL;
    }
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($order_id);
    return $order instanceof OrderInterface ? $order : NULL;
  }

  private function getEventIdFromOrder(OrderInterface $order): ?int {
    foreach ($order->getItems() as $item) {
      if ($item->bundle() !== 'rsvp_donation') {
        continue;
      }
      if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
        return (int) $item->get('field_target_event')->target_id;
      }
      if ($item->hasField('field_attendee_data') && !$item->get('field_attendee_data')->isEmpty()) {
        $data = json_decode((string) $item->get('field_attendee_data')->value, TRUE);
        if (!empty($data['event_id'])) {
          return (int) $data['event_id'];
        }
      }
    }
    return NULL;
  }

}
