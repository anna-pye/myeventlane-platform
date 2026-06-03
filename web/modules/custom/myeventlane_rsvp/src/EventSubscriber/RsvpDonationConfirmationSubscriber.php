<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\Core\Entity\EntityInterface;
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

      $context = $this->resolveDonationItemContext($item, $order);
      if ($context === NULL) {
        continue;
      }

      $submission = $context['submission'];
      $eventNode = $context['event'];

      if (!$submission->hasField('status')) {
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
          '@sid' => $submission->id(),
        ]);
      }
      catch (\Throwable $e) {
        $this->logger->warning('RSVP confirmation queue failed after donation checkout: @msg', [
          '@msg' => $e->getMessage(),
          'order_id' => $order->id(),
          'submission_id' => $submission->id(),
        ]);
      }
      break;
    }
  }

  /**
   * Resolves RSVP submission and event from a donation order item.
   *
   * @return array{submission: EntityInterface, event: NodeInterface}|null
   */
  private function resolveDonationItemContext(OrderItemInterface $item, OrderInterface $order): ?array {
    $submission_id = 0;
    $event_id = NULL;

    if ($item->hasField('field_rsvp_submission') && !$item->get('field_rsvp_submission')->isEmpty()) {
      $submission_id = (int) $item->get('field_rsvp_submission')->target_id;
    }
    elseif ($item->hasField('field_attendee_data') && !$item->get('field_attendee_data')->isEmpty()) {
      $raw = (string) $item->get('field_attendee_data')->value;
      $data = json_decode($raw, TRUE);
      $submission_id = isset($data['rsvp_submission_id']) ? (int) $data['rsvp_submission_id'] : 0;
      $event_id = isset($data['event_id']) ? (int) $data['event_id'] : NULL;
    }
    else {
      $this->logger->warning('RSVP donation order @id item @item_id: missing field_rsvp_submission and field_attendee_data, skipping confirmation', [
        '@id' => $order->id(),
        '@item_id' => $item->id(),
      ]);
      return NULL;
    }

    if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
      $event_id = (int) $item->get('field_target_event')->target_id;
    }

    if ($submission_id <= 0) {
      $this->logger->warning('RSVP donation order @id item @item_id: could not resolve RSVP submission id', [
        '@id' => $order->id(),
        '@item_id' => $item->id(),
      ]);
      return NULL;
    }

    $submission = $this->entityTypeManager->getStorage('rsvp_submission')->load($submission_id);
    if (!$submission instanceof EntityInterface) {
      return NULL;
    }

    if ($event_id === NULL || $event_id <= 0) {
      if ($submission->hasField('event_id') && !$submission->get('event_id')->isEmpty()) {
        $event_id = (int) $submission->get('event_id')->target_id;
      }
    }

    if ($event_id === NULL || $event_id <= 0) {
      $this->logger->warning('RSVP donation order @id item @item_id: could not resolve event id', [
        '@id' => $order->id(),
        '@item_id' => $item->id(),
      ]);
      return NULL;
    }

    $eventNode = $this->entityTypeManager->getStorage('node')->load($event_id);
    if (!$eventNode instanceof NodeInterface) {
      return NULL;
    }

    return [
      'submission' => $submission,
      'event' => $eventNode,
    ];
  }

}
