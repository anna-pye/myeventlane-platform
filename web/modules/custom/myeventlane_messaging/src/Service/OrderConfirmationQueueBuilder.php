<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_checkout_flow\Service\TaxInvoicePresentationBuilder;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_core\Service\TicketLabelResolver;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds context and queues the order_confirmation template (single entry point).
 */
final class OrderConfirmationQueueBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ?TicketLabelResolver $ticketLabelResolver,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly MessagingManager $messagingManager,
    private readonly LoggerInterface $logger,
    private readonly DomainDetector $domainDetector,
    private readonly TaxInvoicePresentationBuilder $taxInvoicePresentation,
    private readonly ?object $icsGenerator = NULL,
  ) {}

  /**
   * Queues order_confirmation for the given order and recipient.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $mail
   *   Recipient email.
   * @param bool $isResend
   *   When TRUE, adds a unique token so MessagingManager idempotency allows a
   *   second send for the same order.
   *
   * @return string|null
   *   Message UUID when queued, NULL when skipped or failed.
   */
  public function queue(OrderInterface $order, string $mail, bool $isResend = FALSE): ?string {
    $orderId = (int) $order->id();
    $customer = $order->getCustomer();
    $first_name = $customer ? $customer->getDisplayName() : 'there';

    $events = $this->extractEvents($order);
    $ticket_items = $this->extractTicketItems($order);
    $donation_total = $this->calculateDonationTotal($order);
    $has_tickets = $ticket_items !== [];
    $tickets_need_assignment = $has_tickets && $this->ticketItemsNeedAssignment($ticket_items);

    $primaryEventId = !empty($events) ? (int) reset($events)->id() : NULL;

    $order_detail_path = '/my-tickets/order/' . $orderId;
    $order_url = $this->buildPublicUrl($order_detail_path);
    $tickets_url = $order_url ? $order_url . '#tickets' : NULL;
    if (!$order_url) {
      try {
        $order_url = Url::fromRoute('myeventlane_checkout_flow.order_detail', [
          'commerce_order' => $orderId,
        ], ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl();
        $tickets_url = Url::fromRoute('myeventlane_checkout_flow.order_detail', [
          'commerce_order' => $orderId,
        ], ['absolute' => TRUE, 'fragment' => 'tickets'])->toString(TRUE)->getGeneratedUrl();
      }
      catch (\Exception $e) {
        $this->logger->warning('Could not generate order/tickets URL: @message', [
          '@message' => $e->getMessage(),
        ]);
        $order_url = NULL;
        $tickets_url = NULL;
      }
    }

    $invoice = $this->taxInvoicePresentation->build($order);
    $invoice_lines = [];
    foreach ($invoice['invoice_lines'] as $row) {
      $invoice_lines[] = [
        'title' => $row['title'],
        'quantity' => $row['quantity'],
        'unit_price' => $row['unit_price'],
        'line_total' => $row['line_total'],
      ];
    }

    $context = [
      'first_name' => $first_name,
      'order_number' => $order->label(),
      'order_id' => $orderId,
      'order_url' => $order_url,
      'order_email' => $mail,
      'events' => $this->formatEventsForEmail($events),
      'ticket_items' => $this->formatTicketItemsForEmail($ticket_items),
      'donation_total' => $donation_total > 0 ? $this->formatPrice($donation_total) : NULL,
      'total_paid' => $this->formatPrice((float) $order->getTotalPrice()->getNumber()),
      'vendor_name' => $invoice['vendor_name'],
      'vendor_abn' => $invoice['vendor_abn'],
      'order_total_gst' => $invoice['order_total_gst'],
      'order_total' => $invoice['order_total'],
      'invoice_lines' => $invoice_lines,
      'invoice_tax_lines' => $invoice['tax_lines'],
      'invoice_fee_lines' => $invoice['fee_lines'],
      'invoice_date_short' => $invoice['invoice_date_display'],
      'event_name' => !empty($events) ? reset($events)->label() : 'your event',
      'tickets_url' => $tickets_url,
      'has_tickets' => $has_tickets,
      'tickets_need_assignment' => $tickets_need_assignment,
    ];
    if ($primaryEventId !== NULL) {
      $context['event_id'] = $primaryEventId;
    }
    if ($isResend) {
      $context['resend_id'] = uniqid('resend_', TRUE);
    }

    $attachments = $this->generateIcsAttachments($events);

    try {
      $messageId = $this->messagingManager->queue('order_confirmation', $mail, $context, [
        'langcode' => $order->language()->getId(),
        'attachments' => $attachments,
      ]);

      $this->logger->info(
        'Order confirmation queued for order @order_id to @email',
        [
          '@order_id' => $orderId,
          '@email' => $mail,
          'order_id' => $orderId,
          'event_id' => $primaryEventId,
          'message_type' => 'order_confirmation',
          'resend' => $isResend,
          'message_id' => $messageId,
        ]
      );

      return $messageId;
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Failed to queue order confirmation for order @order_id: @message',
        [
          '@order_id' => $orderId,
          '@message' => $e->getMessage(),
          'order_id' => $orderId,
          'event_id' => $primaryEventId,
          'message_type' => 'order_confirmation',
        ]
      );
      return NULL;
    }
  }

  /**
   * Whether any ticket line still needs holder assignment.
   */
  private function ticketItemsNeedAssignment(array $ticket_items): bool {
    foreach ($ticket_items as $item) {
      if (!$item->hasField('field_ticket_holder') || $item->get('field_ticket_holder')->isEmpty()) {
        return TRUE;
      }
      $has_holder_email = FALSE;
      foreach ($item->get('field_ticket_holder')->referencedEntities() as $paragraph) {
        if ($paragraph instanceof ParagraphInterface
          && $paragraph->hasField('field_email')
          && !$paragraph->get('field_email')->isEmpty()
          && trim((string) $paragraph->get('field_email')->value) !== '') {
          $has_holder_email = TRUE;
          break;
        }
      }
      if (!$has_holder_email) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Extracts unique events from order items.
   *
   * @return array<\Drupal\node\NodeInterface>
   */
  private function extractEvents(OrderInterface $order): array {
    $events = [];
    $event_ids = [];

    foreach ($order->getItems() as $item) {
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

  private function isDonationItem($item): bool {
    $bundle = $item->bundle();
    return in_array($bundle, ['checkout_donation', 'platform_donation', 'rsvp_donation'], TRUE);
  }

  /**
   * @param array<\Drupal\node\NodeInterface> $events
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
      $label = $item->label();
      if ($this->ticketLabelResolver instanceof TicketLabelResolver) {
        $label = $this->ticketLabelResolver->getTicketLabel($item);
      }
      $formatted[] = [
        'title' => $label,
        'quantity' => (int) $item->getQuantity(),
        'price' => $price ? $this->formatPrice((float) $price->getNumber()) : '$0.00',
        'attendees' => $attendees,
      ];
    }

    return $formatted;
  }

  /**
   * @param array<\Drupal\node\NodeInterface> $events
   */
  private function generateIcsAttachments(array $events): array {
    $attachments = [];

    if (!$this->icsGenerator || !method_exists($this->icsGenerator, 'generate')) {
      return $attachments;
    }

    foreach ($events as $event) {
      try {
        $ics_content = $this->icsGenerator->generate($event);
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

  private function buildPublicUrl(string $path): ?string {
    try {
      return $this->domainDetector->buildDomainUrl($path, 'public');
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not build public domain URL: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  private function formatPrice(float $amount): string {
    return '$' . number_format($amount, 2);
  }

}
