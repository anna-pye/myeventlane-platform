<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_rsvp\Service\RsvpMailer;
use Drupal\node\NodeInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sends RSVP confirmation email when an rsvp_donation order is placed or paid.
 *
 * Listens to both place transition and ORDER_PAID so confirmation is queued
 * regardless of thank-you page access or Stripe redirect flow. Idempotent:
 * email is sent only when the submission transitions to confirmed (not when
 * already confirmed), and MessagingManager::queue dedupes identical payloads.
 */
final class RsvpDonationConfirmationSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RsvpMailer $mailer,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.place.post_transition' => ['onOrderPlace', -50],
      OrderEvents::ORDER_PAID => ['onOrderPaid', -50],
    ];
  }

  /**
   * Sends RSVP confirmation when order is placed.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    $this->processRsvpDonationOrder($order);
  }

  /**
   * Sends RSVP confirmation when order is paid (fallback for payment-first flows).
   */
  public function onOrderPaid(OrderEvent $event): void {
    $this->processRsvpDonationOrder($event->getOrder());
  }

  /**
   * Processes an rsvp_donation order: confirms submission and sends email.
   */
  private function processRsvpDonationOrder($order): void {
    if (!$order instanceof OrderInterface || $order->bundle() !== 'rsvp_donation') {
      return;
    }

    // Re-load order to ensure items have latest field data (avoids stale cache).
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($order->id());
    if (!$order instanceof OrderInterface) {
      return;
    }

    foreach ($order->getItems() as $item) {
      if ($item->bundle() !== 'rsvp_donation') {
        continue;
      }
      if (!$item->hasField('field_attendee_data') || $item->get('field_attendee_data')->isEmpty()) {
        $this->logger->warning('RSVP donation order @id item @item_id: missing field_attendee_data, skipping confirmation', [
          '@id' => $order->id(),
          '@item_id' => $item->id(),
        ]);
        continue;
      }
      $raw = (string) $item->get('field_attendee_data')->value;
      $data = json_decode($raw, TRUE);
      $sid = isset($data['rsvp_submission_id']) ? (int) $data['rsvp_submission_id'] : 0;
      $eventId = isset($data['event_id']) ? (int) $data['event_id'] : NULL;
      if ($sid <= 0 || !$eventId) {
        $this->logger->warning('RSVP donation order @id item @item_id: missing rsvp_submission_id or event_id in field_attendee_data', [
          '@id' => $order->id(),
          '@item_id' => $item->id(),
        ]);
        continue;
      }

      $submission = $this->entityTypeManager->getStorage('rsvp_submission')->load($sid);
      if (!$submission || !$submission->hasField('status')) {
        continue;
      }
      $eventNode = $this->entityTypeManager->getStorage('node')->load($eventId);
      if (!$eventNode instanceof NodeInterface) {
        continue;
      }

      $current = (string) $submission->get('status')->value;
      if ($current === 'confirmed') {
        break;
      }

      $submission->set('status', 'confirmed');
      $submission->save();

      try {
        $this->mailer->sendConfirmation($submission, $eventNode);
        $this->logger->info('RSVP confirmation queued for donation order @order_id, submission @sid', [
          '@order_id' => $order->id(),
          '@sid' => $sid,
        ]);
      }
      catch (\Throwable $e) {
        $this->logger->warning('RSVP confirmation queue failed after donation checkout: @msg', [
          '@msg' => $e->getMessage(),
          'order_id' => $order->id(),
          'submission_id' => $sid,
        ]);
      }
      break;
    }
  }

}
