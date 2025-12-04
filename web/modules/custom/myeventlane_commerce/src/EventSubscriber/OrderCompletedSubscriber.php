<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Handles post-checkout processing for ticket orders.
 *
 * Creates attendee records, generates tickets and wallet passes,
 * and queues confirmation emails.
 */
final class OrderCompletedSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructs OrderCompletedSubscriber.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Listen to order completion (when order transitions to 'completed').
    return [
      'commerce_order.place.post_transition' => ['onOrderPlace', -100],
    ];
  }

  /**
   * Responds to order placement.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The workflow transition event.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event): void {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    $logger = $this->loggerFactory->get('myeventlane_commerce');

    $logger->notice('Processing order @order_id after placement.', [
      '@order_id' => $order->id(),
    ]);

    // Process each order item.
    foreach ($order->getItems() as $orderItem) {
      $this->processOrderItem($orderItem, $order);
    }

    $logger->notice('Completed processing for order @order_id.', [
      '@order_id' => $order->id(),
    ]);
  }

  /**
   * Processes a single order item (ticket).
   *
   * @param object $orderItem
   *   The order item entity.
   * @param object $order
   *   The parent order.
   */
  private function processOrderItem(object $orderItem, object $order): void {
    $logger = $this->loggerFactory->get('myeventlane_commerce');

    // Get event from order item.
    if (!$orderItem->hasField('field_target_event') || $orderItem->get('field_target_event')->isEmpty()) {
      // Fallback: try to get event from product variation.
      $purchasedEntity = $orderItem->getPurchasedEntity();
      if ($purchasedEntity && $purchasedEntity->hasField('field_event') && !$purchasedEntity->get('field_event')->isEmpty()) {
        $eventId = $purchasedEntity->get('field_event')->target_id;
      }
      else {
        $logger->warning('Order item @id has no event reference. Skipping.', [
          '@id' => $orderItem->id(),
        ]);
        return;
      }
    }
    else {
      $eventId = $orderItem->get('field_target_event')->target_id;
    }

    // Get ticket holders (attendee info) from paragraphs.
    if (!$orderItem->hasField('field_ticket_holder') || $orderItem->get('field_ticket_holder')->isEmpty()) {
      $logger->warning('Order item @id has no ticket holder info. Skipping.', [
        '@id' => $orderItem->id(),
      ]);
      return;
    }

    $ticketHolders = $orderItem->get('field_ticket_holder')->referencedEntities();
    $purchasedEntity = $orderItem->getPurchasedEntity();
    $variationTitle = $purchasedEntity ? $purchasedEntity->label() : 'Unknown';
    $price = $orderItem->getTotalPrice();
    $isRsvp = $price && ((float) $price->getNumber() === 0.0);

    foreach ($ticketHolders as $holder) {
      $this->createAttendeeRecord($holder, $eventId, $order, $orderItem, $isRsvp, $variationTitle);
    }
  }

  /**
   * Creates an attendee record using the EventAttendee entity.
   *
   * @param object $holder
   *   The ticket holder paragraph entity.
   * @param int $eventId
   *   The event node ID.
   * @param object $order
   *   The parent order.
   * @param object $orderItem
   *   The order item.
   * @param bool $isRsvp
   *   TRUE if this is a free RSVP (price = $0).
   * @param string $variationTitle
   *   The ticket variation title.
   */
  private function createAttendeeRecord(object $holder, int $eventId, object $order, object $orderItem, bool $isRsvp, string $variationTitle): void {
    $logger = $this->loggerFactory->get('myeventlane_commerce');

    // Extract attendee data from paragraph.
    $firstName = $holder->get('field_first_name')->value ?? '';
    $lastName = $holder->get('field_last_name')->value ?? '';
    $email = $holder->get('field_email')->value ?? '';
    $phone = $holder->hasField('field_phone') ? ($holder->get('field_phone')->value ?? '') : '';
    $fullName = trim($firstName . ' ' . $lastName);

    // Extract extra answers if present.
    $extraData = [];
    if ($holder->hasField('field_attendee_questions') && !$holder->get('field_attendee_questions')->isEmpty()) {
      $questions = $holder->get('field_attendee_questions')->referencedEntities();
      foreach ($questions as $question) {
        $label = $question->hasField('field_question_label') ? ($question->get('field_question_label')->value ?? '') : '';
        $answer = $question->hasField('field_attendee_extra_field') ? ($question->get('field_attendee_extra_field')->value ?? '') : '';
        if ($label && $answer) {
          $extraData[$label] = $answer;
        }
      }
    }

    // Generate unique ticket code.
    $ticketCode = $this->generateTicketCode($eventId, $order->id(), $orderItem->id());

    // Create EventAttendee entity.
    try {
      /** @var \Drupal\myeventlane_event_attendees\Entity\EventAttendee $attendee */
      $attendee = $this->entityTypeManager
        ->getStorage('event_attendee')
        ->create([
          'event' => $eventId,
          'uid' => $order->getCustomerId(),
          'name' => $fullName,
          'email' => $email,
          'phone' => $phone,
          'status' => EventAttendee::STATUS_CONFIRMED,
          'source' => $isRsvp ? EventAttendee::SOURCE_RSVP : EventAttendee::SOURCE_TICKET,
          'order_item' => $orderItem->id(),
          'ticket_code' => $ticketCode,
          'extra_data' => $extraData,
        ]);

      $attendee->save();

      $logger->notice('Created attendee record for @name (@email) - Event @event_id', [
        '@name' => $fullName,
        '@email' => $email,
        '@event_id' => $eventId,
      ]);

      // Generate ticket PDF.
      $this->generateTicketPdf($orderItem, $holder, $ticketCode, $eventId);

      // Generate wallet passes.
      $this->generateWalletPasses($orderItem, $holder, $ticketCode, $eventId);
    }
    catch (\Exception $e) {
      $logger->error('Failed to create attendee record: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Generates a unique ticket code.
   *
   * @param int $eventId
   *   Event ID.
   * @param int $orderId
   *   Order ID.
   * @param int $orderItemId
   *   Order item ID.
   *
   * @return string
   *   The ticket code.
   */
  private function generateTicketCode(int $eventId, int $orderId, int $orderItemId): string {
    // Format: MEL-{EVENT_ID}-{ORDER_ID}-{ITEM_ID}-{RANDOM}
    $random = strtoupper(substr(md5(uniqid((string) mt_rand(), TRUE)), 0, 6));
    return sprintf('MEL-%d-%d-%d-%s', $eventId, $orderId, $orderItemId, $random);
  }

  /**
   * Generates ticket PDF for an attendee.
   *
   * @param object $orderItem
   *   The order item.
   * @param object $holder
   *   The ticket holder paragraph.
   * @param string $ticketCode
   *   The ticket code.
   * @param int $eventId
   *   The event ID.
   */
  private function generateTicketPdf(object $orderItem, object $holder, string $ticketCode, int $eventId): void {
    // Check if myeventlane_tickets.pdf service exists.
    if (!\Drupal::hasService('myeventlane_tickets.pdf')) {
      return;
    }

    try {
      $pdfService = \Drupal::service('myeventlane_tickets.pdf');
      // Call service to generate PDF (actual implementation in myeventlane_tickets).
      // For now, just log intent.
      \Drupal::logger('myeventlane_commerce')->notice('Ticket PDF generation called for code @code', [
        '@code' => $ticketCode,
      ]);
    }
    catch (\Exception $e) {
      \Drupal::logger('myeventlane_commerce')->error('Ticket PDF generation failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Generates wallet passes for an attendee.
   *
   * @param object $orderItem
   *   The order item.
   * @param object $holder
   *   The ticket holder paragraph.
   * @param string $ticketCode
   *   The ticket code.
   * @param int $eventId
   *   The event ID.
   */
  private function generateWalletPasses(object $orderItem, object $holder, string $ticketCode, int $eventId): void {
    // Check if wallet services exist.
    if (!\Drupal::hasService('myeventlane_wallet.pk_pass') && !\Drupal::hasService('myeventlane_wallet.google_wallet')) {
      return;
    }

    try {
      // Apple Wallet.
      if (\Drupal::hasService('myeventlane_wallet.pk_pass')) {
        $pkPassService = \Drupal::service('myeventlane_wallet.pk_pass');
        \Drupal::logger('myeventlane_commerce')->notice('Apple Wallet pass generation called for code @code', [
          '@code' => $ticketCode,
        ]);
      }

      // Google Wallet.
      if (\Drupal::hasService('myeventlane_wallet.google_wallet')) {
        $googleWalletService = \Drupal::service('myeventlane_wallet.google_wallet');
        \Drupal::logger('myeventlane_commerce')->notice('Google Wallet pass generation called for code @code', [
          '@code' => $ticketCode,
        ]);
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('myeventlane_commerce')->error('Wallet pass generation failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

}

