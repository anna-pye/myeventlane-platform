<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\TicketLabelResolver;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sends an order receipt when an order is placed.
 *
 * Includes:
 * - Branded HTML receipt email
 * - Calendar (.ics) attachments (one per event)
 * - Clear separation of tickets vs donations
 * - Dedicated boost confirmation email for boost-only orders.
 */
final class OrderPlacedSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Placeholder vendor support URL until the support page is built.
   */
  private const VENDOR_SUPPORT_URL = 'https://myeventlane.com.au/contact';

  /**
   * Constructs OrderPlacedSubscriber.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\myeventlane_core\Service\TicketLabelResolver $ticketLabelResolver
   *   The ticket label resolver.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   * @param \Drupal\myeventlane_messaging\Service\MessagingManager $messagingManager
   *   The messaging manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TicketLabelResolver $ticketLabelResolver,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly MessagingManager $messagingManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.place.post_transition' => 'onPlace',
    ];
  }

  /**
   * Queues the order receipt email with ICS attachments.
   *
   * Detects boost-only orders and sends a dedicated boost confirmation
   * template instead of the generic order receipt.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The workflow transition event.
   */
  public function onPlace(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if (!$order instanceof OrderInterface) {
      return;
    }

    $orderId = (int) $order->id();

    $mail = $order->getEmail();
    if (!$mail) {
      $customer = $order->getCustomer();
      $mail = $customer ? $customer->getEmail() : NULL;
    }

    if (!$mail) {
      $this->logger->warning(
        'Order @order_id placed but no email address found for receipt.',
        [
          '@order_id' => $orderId,
          'order_id' => $orderId,
        ]
      );
      return;
    }

    // Route to boost confirmation if this is a boost-only order.
    if ($this->isBoostOnlyOrder($order)) {
      $this->sendBoostConfirmation($order, $mail);
      return;
    }

    $this->sendOrderReceipt($order, $mail);
  }

  /**
   * Checks if an order contains only boost items (no tickets, no donations).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return bool
   *   TRUE if all order items are boost items.
   */
  private function isBoostOnlyOrder(OrderInterface $order): bool {
    $items = $order->getItems();
    if (empty($items)) {
      return FALSE;
    }

    foreach ($items as $item) {
      if ($item->bundle() !== 'boost') {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Sends a dedicated boost confirmation email.
   *
   * Calculates boost dates from order data (product variation field_boost_days)
   * rather than reading field_promo_expires from the event, because the boost
   * has not yet been applied at order-place time (it fires on ORDER_PAID).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $mail
   *   The recipient email.
   */
  private function sendBoostConfirmation(OrderInterface $order, string $mail): void {
    $orderId = (int) $order->id();
    $customer = $order->getCustomer();
    $first_name = $customer ? $customer->getDisplayName() : 'there';

    // Extract boost details from all boost items in the order.
    $boostItems = $this->extractBoostItems($order);

    if (empty($boostItems)) {
      $this->logger->error(
        'Boost-only order @order_id has no extractable boost items. Falling back to generic receipt.',
        ['@order_id' => $orderId, 'order_id' => $orderId]
      );
      $this->sendOrderReceipt($order, $mail);
      return;
    }

    // Use the first boost item for primary context (most orders have one).
    $primaryBoost = reset($boostItems);

    // Build boost manage URL (link to the boost page for the event).
    $boostManageUrl = NULL;
    if ($primaryBoost['event_id']) {
      try {
        $boostManageUrl = Url::fromRoute('myeventlane_boost.boost_page', [
          'node' => $primaryBoost['event_id'],
        ], [
          'absolute' => TRUE,
        ])->toString(TRUE)->getGeneratedUrl();
      }
      catch (\Exception $e) {
        $this->logger->warning('Could not generate boost manage URL: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $context = [
      'first_name' => $first_name,
      'order_number' => $order->label(),
      'order_id' => $orderId,
      'order_email' => $mail,
      'event_name' => $primaryBoost['event_name'],
      'boost_days' => $primaryBoost['boost_days'],
      'boost_start_date' => $primaryBoost['boost_start_date'],
      'boost_end_date' => $primaryBoost['boost_end_date'],
      'total_paid' => $this->formatPrice((float) $order->getTotalPrice()->getNumber()),
      'boost_manage_url' => $boostManageUrl,
      'support_url' => self::VENDOR_SUPPORT_URL,
    ];

    if ($primaryBoost['event_id']) {
      $context['event_id'] = $primaryBoost['event_id'];
    }

    try {
      $this->messagingManager->queue('boost_confirmation', $mail, $context, [
        'langcode' => $order->language()->getId(),
      ]);

      $this->logger->info(
        'Boost confirmation queued for order @order_id to @email (event: @event, @days days)',
        [
          '@order_id' => $orderId,
          '@email' => $mail,
          '@event' => $primaryBoost['event_name'],
          '@days' => $primaryBoost['boost_days'],
          'order_id' => $orderId,
          'event_id' => $primaryBoost['event_id'],
          'message_type' => 'boost_confirmation',
        ]
      );
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Failed to queue boost confirmation for order @order_id: @message',
        [
          '@order_id' => $orderId,
          '@message' => $e->getMessage(),
          'order_id' => $orderId,
          'event_id' => $primaryBoost['event_id'],
          'message_type' => 'boost_confirmation',
        ]
      );
    }
  }

  /**
   * Extracts boost item data from an order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   Array of boost item data, each with keys:
   *   - event_id (int|null)
   *   - event_name (string)
   *   - boost_days (int)
   *   - boost_start_date (string): Formatted date.
   *   - boost_end_date (string): Formatted date.
   */
  private function extractBoostItems(OrderInterface $order): array {
    $items = [];
    $orderDate = new \DateTimeImmutable(
      $order->getPlacedTime()
        ? '@' . $order->getPlacedTime()
        : 'now',
      new \DateTimeZone('Australia/Sydney')
    );

    foreach ($order->getItems() as $item) {
      if ($item->bundle() !== 'boost') {
        continue;
      }

      $eventName = 'your event';
      $eventId = NULL;

      if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
        $event = $item->get('field_target_event')->entity;
        if ($event instanceof NodeInterface) {
          $eventName = $event->label();
          $eventId = (int) $event->id();
        }
      }

      // Get boost duration from the product variation.
      $boostDays = 7;
      $variation = $item->getPurchasedEntity();
      if ($variation && $variation->hasField('field_boost_days') && !$variation->get('field_boost_days')->isEmpty()) {
        $boostDays = (int) $variation->get('field_boost_days')->value;
        if ($boostDays < 1) {
          $boostDays = 7;
        }
      }

      // Calculate start and end dates from order placed date.
      $startDate = $orderDate->setTimezone(new \DateTimeZone('Australia/Sydney'));
      $endDate = $startDate->modify(sprintf('+%d days', $boostDays));

      $items[] = [
        'event_id' => $eventId,
        'event_name' => $eventName,
        'boost_days' => $boostDays,
        'boost_start_date' => $startDate->format('j F Y'),
        'boost_end_date' => $endDate->format('j F Y'),
      ];
    }

    return $items;
  }

  /**
   * Sends the standard order receipt email (for ticket/donation orders).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $mail
   *   The recipient email.
   */
  private function sendOrderReceipt(OrderInterface $order, string $mail): void {
    $orderId = (int) $order->id();
    $customer = $order->getCustomer();
    $first_name = $customer ? $customer->getDisplayName() : 'there';

    // Extract events, ticket items, and donations from order.
    $events = $this->extractEvents($order);
    $ticket_items = $this->extractTicketItems($order);
    $donation_total = $this->calculateDonationTotal($order);

    $primaryEventId = !empty($events) ? (int) reset($events)->id() : NULL;

    // Build ticket download URL.
    $tickets_url = NULL;
    try {
      $tickets_url = Url::fromRoute('myeventlane_checkout_flow.order_detail', [
        'commerce_order' => $orderId,
      ], [
        'absolute' => TRUE,
        'fragment' => 'tickets',
      ])->toString(TRUE)->getGeneratedUrl();
    }
    catch (\Exception $e) {
      // Fallback to order URL if route doesn't exist.
      $this->logger->warning('Could not generate tickets URL: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    // Build email context.
    $context = [
      'first_name' => $first_name,
      'order_number' => $order->label(),
      'order_id' => $orderId,
      // Customer-facing "My Tickets" order detail (not admin order view).
      'order_url' => Url::fromRoute('myeventlane_checkout_flow.order_detail', [
        'commerce_order' => $orderId,
      ], [
        'absolute' => TRUE,
      ])->toString(TRUE)->getGeneratedUrl(),
      'order_email' => $mail,
      'events' => $this->formatEventsForEmail($events),
      'ticket_items' => $this->formatTicketItemsForEmail($ticket_items),
      'donation_total' => $donation_total > 0 ? $this->formatPrice($donation_total) : NULL,
      'total_paid' => $this->formatPrice((float) $order->getTotalPrice()->getNumber()),
      'event_name' => !empty($events) ? reset($events)->label() : 'your event',
      // Add tickets download link for the email template.
      'tickets_url' => $tickets_url,
      'has_tickets' => !empty($ticket_items),
    ];
    if ($primaryEventId !== NULL) {
      $context['event_id'] = $primaryEventId;
    }

    // Generate ICS attachments.
    $attachments = $this->generateIcsAttachments($events);

    // Queue email with attachments.
    try {
      $this->messagingManager->queue('order_receipt', $mail, $context, [
        'langcode' => $order->language()->getId(),
        'attachments' => $attachments,
      ]);

      $this->logger->info(
        'Order receipt queued for order @order_id to @email',
        [
          '@order_id' => $orderId,
          '@email' => $mail,
          'order_id' => $orderId,
          'event_id' => $primaryEventId,
          'message_type' => 'order_receipt',
        ]
      );
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Failed to queue order receipt for order @order_id: @message',
        [
          '@order_id' => $orderId,
          '@message' => $e->getMessage(),
          'order_id' => $orderId,
          'event_id' => $primaryEventId,
          'message_type' => 'order_receipt',
        ]
      );
    }
  }

  /**
   * Extracts unique events from order items.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array<\Drupal\node\NodeInterface>
   *   Array of event nodes.
   */
  private function extractEvents(OrderInterface $order): array {
    $events = [];
    $event_ids = [];

    foreach ($order->getItems() as $item) {
      // Skip donation and Boost (admin product) items.
      if ($this->isDonationItem($item) || $item->bundle() === 'boost') {
        continue;
      }

      if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
        $event = $item->get('field_target_event')->entity;
        if ($event instanceof NodeInterface && $event->bundle() === 'event') {
          $event_id = (int) $event->id();
          if (!in_array($event_id, $event_ids, TRUE)) {
            $events[] = $event;
            $event_ids[] = $event_id;
          }
        }
      }
    }

    return $events;
  }

  /**
   * Extracts ticket items (excludes donations).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   Array of ticket order items.
   */
  private function extractTicketItems(OrderInterface $order): array {
    $ticket_items = [];

    foreach ($order->getItems() as $item) {
      if ($this->isDonationItem($item) || $item->bundle() === 'boost') {
        continue;
      }
      $ticket_items[] = $item;
    }

    return $ticket_items;
  }

  /**
   * Calculates total donation amount.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return float
   *   Total donation amount.
   */
  private function calculateDonationTotal(OrderInterface $order): float {
    $total = 0.0;

    foreach ($order->getItems() as $item) {
      if ($this->isDonationItem($item)) {
        $price = $item->getTotalPrice();
        if ($price) {
          $total += (float) $price->getNumber();
        }
      }
    }

    return $total;
  }

  /**
   * Checks if an order item is a donation.
   *
   * @param object $item
   *   The order item.
   *
   * @return bool
   *   TRUE if donation, FALSE otherwise.
   */
  private function isDonationItem($item): bool {
    $bundle = $item->bundle();
    return in_array($bundle, ['checkout_donation', 'platform_donation', 'rsvp_donation'], TRUE);
  }

  /**
   * Formats events for email template.
   *
   * @param array<\Drupal\node\NodeInterface> $events
   *   Event nodes.
   *
   * @return array
   *   Formatted event data.
   */
  private function formatEventsForEmail(array $events): array {
    $formatted = [];

    foreach ($events as $event) {
      $start_date = NULL;
      $end_date = NULL;
      $start_time = NULL;
      $end_time = NULL;
      $image_url = NULL;

      if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
        $start_timestamp = strtotime($event->get('field_event_start')->value);
        $start_date = date('F j, Y', $start_timestamp);
        $start_time = date('g:i A', $start_timestamp);
      }

      if ($event->hasField('field_event_end') && !$event->get('field_event_end')->isEmpty()) {
        $end_timestamp = strtotime($event->get('field_event_end')->value);
        $end_date = date('F j, Y', $end_timestamp);
        $end_time = date('g:i A', $end_timestamp);
      }

      if ($event->hasField('field_event_image') && !$event->get('field_event_image')->isEmpty()) {
        $file = $event->get('field_event_image')->entity;
        if ($file) {
          $image_url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
        }
      }

      $location = NULL;
      if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
        $address_field = $event->get('field_location')->first();
        if ($address_field) {
          $location = $this->formatAddressFieldValue($address_field->getValue());
        }
      }

      $formatted[] = [
        'title' => $event->label(),
        'image_url' => $image_url,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'start_time' => $start_time,
        'end_time' => $end_time,
        'venue_name' => $event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()
          ? $event->get('field_venue_name')->value
          : NULL,
        'location' => $location,
        'contact_email' => $event->hasField('field_contact_email') && !$event->get('field_contact_email')->isEmpty()
          ? $event->get('field_contact_email')->value
          : NULL,
        'contact_phone' => $event->hasField('field_contact_phone') && !$event->get('field_contact_phone')->isEmpty()
          ? $event->get('field_contact_phone')->value
          : NULL,
        'accessibility_contact' => $event->hasField('field_accessibility_contact') && !$event->get('field_accessibility_contact')->isEmpty()
          ? $event->get('field_accessibility_contact')->value
          : NULL,
      ];
    }

    return $formatted;
  }

  /**
   * Formats an Address field value to a single-line string.
   *
   * @param array $address
   *   Address field value array.
   *
   * @return string|null
   *   Formatted string, or NULL when empty.
   */
  private function formatAddressFieldValue(array $address): ?string {
    $parts = [];
    if (!empty($address['address_line1'])) {
      $parts[] = $address['address_line1'];
    }
    if (!empty($address['address_line2'])) {
      $parts[] = $address['address_line2'];
    }
    if (!empty($address['locality'])) {
      $parts[] = $address['locality'];
    }
    if (!empty($address['administrative_area'])) {
      $parts[] = $address['administrative_area'];
    }
    if (!empty($address['postal_code'])) {
      $parts[] = $address['postal_code'];
    }

    $value = trim(implode(', ', $parts));
    return $value !== '' ? $value : NULL;
  }

  /**
   * Formats ticket items for email template.
   *
   * @param array $ticket_items
   *   Ticket order items.
   *
   * @return array
   *   Formatted ticket item data.
   */
  private function formatTicketItemsForEmail(array $ticket_items): array {
    $formatted = [];

    foreach ($ticket_items as $item) {
      $attendees = [];
      if ($item->hasField('field_ticket_holder') && !$item->get('field_ticket_holder')->isEmpty()) {
        foreach ($item->get('field_ticket_holder')->referencedEntities() as $paragraph) {
          if ($paragraph instanceof ParagraphInterface) {
            $first_name = $paragraph->hasField('field_first_name') && !$paragraph->get('field_first_name')->isEmpty()
              ? $paragraph->get('field_first_name')->value : '';
            $last_name = $paragraph->hasField('field_last_name') && !$paragraph->get('field_last_name')->isEmpty()
              ? $paragraph->get('field_last_name')->value : '';
            $email = $paragraph->hasField('field_email') && !$paragraph->get('field_email')->isEmpty()
              ? $paragraph->get('field_email')->value : '';

            $attendees[] = [
              'name' => trim($first_name . ' ' . $last_name),
              'email' => $email,
            ];
          }
        }
      }

      $price = $item->getTotalPrice();
      $formatted[] = [
        'title' => $this->ticketLabelResolver->getTicketLabel($item),
        'quantity' => (int) $item->getQuantity(),
        'price' => $price ? $this->formatPrice((float) $price->getNumber()) : '$0.00',
        'attendees' => $attendees,
      ];
    }

    return $formatted;
  }

  /**
   * Generates ICS attachments for events.
   *
   * @param array<\Drupal\node\NodeInterface> $events
   *   Event nodes.
   *
   * @return array
   *   Array of attachment data for email.
   */
  private function generateIcsAttachments(array $events): array {
    $attachments = [];

    if (!\Drupal::hasService('myeventlane_rsvp.ics_generator')) {
      return $attachments;
    }

    $ics_generator = \Drupal::service('myeventlane_rsvp.ics_generator');

    foreach ($events as $event) {
      try {
        $ics_content = $ics_generator->generate($event);
        $filename = 'event-' . $event->id() . '-' . preg_replace('/[^a-z0-9]/i', '-', strtolower($event->label())) . '.ics';

        $attachments[] = [
          'filename' => $filename,
          'content' => $ics_content,
          'mime' => 'text/calendar',
        ];
      }
      catch (\Exception $e) {
        $this->logger->error(
          'Failed to generate ICS for event @event_id: @message',
          [
            '@event_id' => $event->id(),
            '@message' => $e->getMessage(),
            'event_id' => (int) $event->id(),
          ]
        );
      }
    }

    return $attachments;
  }

  /**
   * Formats a price value.
   *
   * @param float $amount
   *   The amount.
   *
   * @return string
   *   Formatted price string.
   */
  private function formatPrice(float $amount): string {
    return '$' . number_format($amount, 2);
  }

}
