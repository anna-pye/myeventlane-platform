<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\myeventlane_checkout_flow\Service\OrderPricingBreakdownBuilder;
use Drupal\myeventlane_checkout_flow\Service\TaxInvoicePresentationBuilder;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_core\Service\TicketLabelResolver;
use Drupal\myeventlane_legal\Service\LegalSettingsService;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_wallet\Service\WalletActionBuilder;
use Drupal\myeventlane_wallet\Service\WalletDownloadAccessChecker;
use Drupal\myeventlane_wallet\Service\WalletTicketResolver;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Builds context and queues the order_confirmation template (single entry point).
 *
 * ACE Phase 2 ownership: attendee booking confirmation email only.
 * Tax invoice remains order_invoice (OrderPaidInvoiceSubscriber).
 * Boost-only orders use boost_confirmation (OrderPlacedSubscriber).
 */
final class OrderConfirmationQueueBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ?TicketLabelResolver $ticketLabelResolver,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly CurrencyFormatterInterface $currencyFormatter,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly MessagingManager $messagingManager,
    private readonly LoggerInterface $logger,
    private readonly DomainDetector $domainDetector,
    private readonly OrderPricingBreakdownBuilder $orderPricingBreakdown,
    private readonly TaxInvoicePresentationBuilder $taxInvoicePresentation,
    private readonly ?object $icsGenerator = NULL,
    private readonly ?LegalSettingsService $legalSettings = NULL,
    private readonly ?WalletActionBuilder $walletActionBuilder = NULL,
    private readonly ?WalletTicketResolver $walletTicketResolver = NULL,
    private readonly ?WalletDownloadAccessChecker $walletDownloadAccess = NULL,
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

    $primaryEvent = !empty($events) ? reset($events) : NULL;
    $primaryEventId = $primaryEvent instanceof NodeInterface ? (int) $primaryEvent->id() : NULL;

    // Guest checkout still has customer uid 0 at place.post_transition (before
    // Commerce guest_new_account assignment on checkout completion). Do not put
    // authenticated-only /my-tickets/order/{id} in guest emails — anonymous
    // clicks receive 403 (MyTicketsOrderAccess).
    $is_guest = (int) $order->getCustomerId() === 0;
    $is_paid = $order->isPaid();

    $order_url = NULL;
    $tickets_url = NULL;
    if (!$is_guest) {
      $order_detail_path = '/my-tickets/order/' . $orderId;
      $order_url = $this->buildPublicUrl($order_detail_path);
      $tickets_url = $order_url ? $order_url . '#mel-pass-entry' : NULL;
      if (!$order_url) {
        try {
          $order_url = Url::fromRoute('myeventlane_checkout_flow.order_detail', [
            'commerce_order' => $orderId,
          ], ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl();
          $tickets_url = Url::fromRoute('myeventlane_checkout_flow.order_detail', [
            'commerce_order' => $orderId,
          ], ['absolute' => TRUE, 'fragment' => 'mel-pass-entry'])->toString(TRUE)->getGeneratedUrl();
        }
        catch (\Exception $e) {
          $this->logger->warning('Could not generate order/tickets URL: @message', [
            '@message' => $e->getMessage(),
          ]);
          $order_url = NULL;
          $tickets_url = NULL;
        }
      }
    }

    $pricing = $this->orderPricingBreakdown->build($order);
    $invoice = $this->taxInvoicePresentation->build($order);
    $booking_total = $pricing['total_formatted'] !== ''
      ? $pricing['total_formatted']
      : $this->formatPrice(
        (float) $order->getTotalPrice()->getNumber(),
        $order->getTotalPrice()->getCurrencyCode(),
      );

    // Customer presentation only — Commerce label() is "Order {number}".
    $order_number = trim((string) $order->getOrderNumber());
    if ($order_number === '') {
      $order_number = (string) $orderId;
    }

    $event_url = NULL;
    $organiser_name = NULL;
    $organiser_url = NULL;
    if ($primaryEvent instanceof NodeInterface) {
      try {
        $event_url = $primaryEvent->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl();
      }
      catch (\Exception $e) {
        $this->logger->warning('Could not generate event URL for order confirmation: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
      $organiser = $this->resolveOrganiserFromEvent($primaryEvent);
      $organiser_name = $organiser['name'];
      $organiser_url = $organiser['url'];
    }

    $help_urls = $this->buildHelpUrls();
    $pass_actions = $this->buildDigitalPassEmailActions($order, $is_guest, $primaryEventId);

    $context = [
      'first_name' => $first_name,
      'order_number' => $order_number,
      'order_id' => $orderId,
      // Canonical Digital Pass URL (authenticated customers only).
      'order_url' => $order_url,
      'digital_pass_url' => $order_url,
      'order_email' => $mail,
      'is_guest' => $is_guest,
      'is_paid' => $is_paid,
      'events' => $this->formatEventsForEmail($events),
      'ticket_items' => $this->formatTicketItemsForEmail(
        $ticket_items,
        $order->getTotalPrice()->getCurrencyCode(),
      ),
      'donation_total' => $donation_total > 0
        ? $this->formatPrice(
          $donation_total,
          $order->getTotalPrice()->getCurrencyCode(),
      )
        : NULL,
      'order_subtotal_formatted' => $pricing['subtotal_formatted'],
      'order_tax_rows' => $pricing['tax_rows'],
      'order_fee_rows' => $pricing['fee_rows'],
      'order_platform_fee_absorbed' => $pricing['platform_fee_absorbed'],
      'total_paid' => $booking_total,
      'booking_total' => $booking_total,
      'show_includes_gst_note' => $pricing['show_includes_gst_note'],
      'vendor_name' => $invoice['vendor_name'],
      'vendor_abn' => $invoice['vendor_abn'],
      'document_title' => $invoice['document_title'],
      'is_tax_invoice' => $invoice['is_tax_invoice'],
      'order_total_gst' => $invoice['order_total_gst'],
      'order_total' => $invoice['order_total'],
      'invoice_lines' => $invoice['invoice_lines'],
      'invoice_tax_lines' => $invoice['tax_lines'],
      'invoice_fee_lines' => $invoice['fee_lines'],
      'platform_name' => $invoice['platform_name'],
      'platform_abn' => $invoice['platform_abn'],
      'platform_fee_lines' => $invoice['platform_fee_lines'],
      'platform_total_gst' => $invoice['platform_total_gst'],
      'tax_lines' => $invoice['tax_lines'],
      'invoice_lines_include_gst_column' => $invoice['invoice_lines_include_gst_column'] ?? FALSE,
      'invoice_date_short' => $invoice['invoice_date_display'],
      'event_name' => $primaryEvent instanceof NodeInterface ? $primaryEvent->label() : NULL,
      'event_url' => $event_url,
      'organiser_name' => $organiser_name,
      'organiser_url' => $organiser_url,
      'help_centre_url' => $help_urls['help_centre_url'],
      'refund_policy_url' => $help_urls['refund_policy_url'],
      'support_url' => $help_urls['support_url'],
      'tickets_url' => $tickets_url,
      'has_tickets' => $has_tickets,
      'tickets_need_assignment' => $tickets_need_assignment,
      'apple_wallet_url' => $pass_actions['apple_wallet_url'],
      'google_wallet_url' => $pass_actions['google_wallet_url'],
      'apple_wallet_badge_url' => $pass_actions['apple_wallet_badge_url'],
      'google_wallet_badge_url' => $pass_actions['google_wallet_badge_url'],
      'pdf_url' => $pass_actions['pdf_url'],
      'manage_booking_url' => $pass_actions['manage_booking_url'],
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
      $image_alt = NULL;
      $event_url = NULL;

      if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
        $start_timestamp = strtotime($event->get('field_event_start')->value);
        $start_date = $this->dateFormatter->format($start_timestamp, 'custom', 'j F Y');
        $start_time = $this->dateFormatter->format($start_timestamp, 'custom', 'g:i a T');
      }

      if ($event->hasField('field_event_end') && !$event->get('field_event_end')->isEmpty()) {
        $end_timestamp = strtotime($event->get('field_event_end')->value);
        $end_date = $this->dateFormatter->format($end_timestamp, 'custom', 'j F Y');
        $end_time = $this->dateFormatter->format($end_timestamp, 'custom', 'g:i a T');
      }

      if ($event->hasField('field_event_image') && !$event->get('field_event_image')->isEmpty()) {
        $image_item = $event->get('field_event_image')->first();
        $file = $image_item?->entity;
        if ($file) {
          $image_url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
          $alt = trim((string) ($image_item->alt ?? ''));
          $image_alt = $alt !== '' ? $alt : $event->label();
        }
      }

      try {
        $event_url = $event->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl();
      }
      catch (\Exception $e) {
        $event_url = NULL;
      }

      $location = NULL;
      if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
        $address_field = $event->get('field_location')->first();
        if ($address_field) {
          $location = $this->formatAddressFieldValue($address_field->getValue());
        }
      }

      $organiser = $this->resolveOrganiserFromEvent($event);

      $formatted[] = [
        'title' => $event->label(),
        'url' => $event_url,
        'image_url' => $image_url,
        'image_alt' => $image_alt,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'start_time' => $start_time,
        'end_time' => $end_time,
        'venue_name' => $event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()
          ? $event->get('field_venue_name')->value
          : NULL,
        'location' => $location,
        'organiser_name' => $organiser['name'],
        'organiser_url' => $organiser['url'],
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
   * Resolves public organiser name/URL from field_event_vendor when available.
   *
   * @return array{name: string|null, url: string|null}
   */
  private function resolveOrganiserFromEvent(NodeInterface $event): array {
    $result = ['name' => NULL, 'url' => NULL];
    if (!$event->hasField('field_event_vendor') || $event->get('field_event_vendor')->isEmpty()) {
      return $result;
    }
    $vendor = $event->get('field_event_vendor')->entity;
    if (!$vendor || $vendor->getEntityTypeId() !== 'myeventlane_vendor') {
      return $result;
    }

    $name = trim((string) $vendor->label());
    if ($name === '') {
      return $result;
    }
    $result['name'] = $name;

    try {
      $url = Url::fromRoute('entity.myeventlane_vendor.canonical', [
        'myeventlane_vendor' => $vendor->id(),
      ], ['absolute' => TRUE]);
      // Email recipients may be anonymous — only expose publicly accessible profiles.
      $anonymous = User::getAnonymousUser();
      if ($anonymous instanceof AccountInterface && $url->access($anonymous)) {
        $result['url'] = $url->toString(TRUE)->getGeneratedUrl();
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not resolve organiser URL for event @event_id: @message', [
        '@event_id' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
    }

    return $result;
  }

  /**
   * Absolute help / policy / support links for the confirmation email.
   *
   * @return array{help_centre_url: string|null, refund_policy_url: string|null, support_url: string|null}
   */
  private function buildHelpUrls(): array {
    $help = $this->buildPublicUrl('/help');
    if (!$help) {
      try {
        $help = Url::fromRoute('myeventlane_help_centre.home', [], ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl();
      }
      catch (\Exception $e) {
        $help = NULL;
      }
    }

    $refund = NULL;
    if ($this->legalSettings instanceof LegalSettingsService) {
      $configured = trim($this->legalSettings->getRefundPolicyUrl());
      if ($configured !== '') {
        if (preg_match('#^https?://#i', $configured)) {
          $refund = $configured;
        }
        else {
          $refund = $this->buildPublicUrl('/' . ltrim($configured, '/'));
        }
      }
    }
    if (!$refund) {
      $refund = $this->buildPublicUrl('/help/policies/refund-policy');
    }

    $support = $this->buildPublicUrl('/support');
    if (!$support) {
      try {
        $support = Url::fromRoute('myeventlane_support.page', [], ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl();
      }
      catch (\Exception $e) {
        $support = NULL;
      }
    }

    return [
      'help_centre_url' => $help,
      'refund_policy_url' => $refund,
      'support_url' => $support,
    ];
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

  private function formatTicketItemsForEmail(
    array $ticket_items,
    string $orderCurrencyCode,
  ): array {
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
        'price' => $price
          ? $this->formatPrice(
            (float) $price->getNumber(),
            $price->getCurrencyCode(),
        )
          : $this->formatPrice(0.0, $orderCurrencyCode),
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

  /**
   * Digital Pass email actions via canonical WalletActionBuilder.
   *
   * Guests receive PDF/ICS attachments only (no authenticated pass/wallet URLs).
   * Wallet links appear only when the gate emits email CTAs — never placeholders.
   *
   * @return array{
   *   apple_wallet_url: string|null,
   *   google_wallet_url: string|null,
   *   apple_wallet_badge_url: string|null,
   *   google_wallet_badge_url: string|null,
   *   pdf_url: string|null,
   *   manage_booking_url: string|null
   * }
   */
  private function buildDigitalPassEmailActions(OrderInterface $order, bool $is_guest, ?int $primary_event_id = NULL): array {
    $actions = [
      'apple_wallet_url' => NULL,
      'google_wallet_url' => NULL,
      'apple_wallet_badge_url' => NULL,
      'google_wallet_badge_url' => NULL,
      'pdf_url' => NULL,
      'manage_booking_url' => NULL,
    ];

    if ($is_guest) {
      return $actions;
    }

    $manage = $this->buildPublicUrl('/my-tickets');
    if (!$manage) {
      try {
        $manage = Url::fromRoute('myeventlane_checkout_flow.my_tickets', [], [
          'absolute' => TRUE,
        ])->toString(TRUE)->getGeneratedUrl();
      }
      catch (\Exception $e) {
        $this->logger->warning('Could not generate My Bookings URL: @message', [
          '@message' => $e->getMessage(),
        ]);
        $manage = NULL;
      }
    }
    $actions['manage_booking_url'] = $manage;

    $ticket = $this->resolvePrimaryIssuedTicketForOrder($order, $primary_event_id);
    if (!$ticket instanceof Ticket) {
      return $actions;
    }

    $ticket_code = trim((string) ($ticket->get('ticket_code')->value ?? ''));
    if ($ticket_code !== '') {
      $pdf_path = '/ticket/' . rawurlencode($ticket_code) . '/pdf';
      $actions['pdf_url'] = $this->buildPublicUrl($pdf_path);
      if (!$actions['pdf_url']) {
        try {
          $actions['pdf_url'] = Url::fromRoute('myeventlane_tickets.download_pdf_by_code', [
            'ticket_code' => $ticket_code,
          ], ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl();
        }
        catch (\Exception $e) {
          $this->logger->warning('Could not generate Digital Pass PDF URL: @message', [
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }

    if (!$this->walletActionBuilder instanceof WalletActionBuilder) {
      return $actions;
    }

    $order_item_id = 0;
    if ($ticket->hasField('order_item_id') && !$ticket->get('order_item_id')->isEmpty()) {
      $order_item_id = (int) $ticket->get('order_item_id')->target_id;
    }
    if ($order_item_id < 1) {
      return $actions;
    }

    $wallet = $this->walletActionBuilder->buildForOrderItem(
      $order_item_id,
      WalletActionBuilder::SURFACE_EMAIL,
      TRUE,
    );

    if (is_array($wallet['apple'] ?? NULL)) {
      $actions['apple_wallet_url'] = $this->walletEmailUrl(
        $this->absoluteWalletUrl(
          'myeventlane_wallet.apple',
          ['order_item_id' => $order_item_id],
          '/wallet/apple/' . $order_item_id,
        ),
        $wallet['apple']['url'] ?? NULL,
      );
      $badge = $wallet['apple']['badge']['src'] ?? NULL;
      $actions['apple_wallet_badge_url'] = $this->walletEmailBadgeUrl($badge);
    }
    if (is_array($wallet['google'] ?? NULL)) {
      $actions['google_wallet_url'] = $this->walletEmailUrl(
        $this->absoluteWalletUrl(
          'myeventlane_wallet.google',
          ['order_item_id' => $order_item_id],
          '/wallet/google/' . $order_item_id,
        ),
        $wallet['google']['url'] ?? NULL,
      );
      $badge = $wallet['google']['badge']['src'] ?? NULL;
      $actions['google_wallet_badge_url'] = $this->walletEmailBadgeUrl($badge);
    }

    return $actions;
  }

  /**
   * Resolves one issued ticket for email CTAs (wallet + PDF).
   *
   * Prefer the same primary event shown in confirmation copy
   * (first ticketable line's field_target_event). If that event has no issued
   * ticket yet (common with staggered per-line issuance), fall back to any
   * ready ticket on the order so PDF/wallet links are not omitted. Reuses
   * WalletTicketResolver per matching order item.
   */
  private function resolvePrimaryIssuedTicketForOrder(OrderInterface $order, ?int $primary_event_id = NULL): ?Ticket {
    $customer = $order->getCustomer();
    $preferred_event_id = ($primary_event_id !== NULL && $primary_event_id > 0)
      ? $primary_event_id
      : NULL;

    if ($this->walletTicketResolver instanceof WalletTicketResolver
      && $customer instanceof AccountInterface) {
      $ticket = $this->resolveTicketFromOrderItems($order, $customer, $preferred_event_id);
      if ($ticket instanceof Ticket) {
        return $ticket;
      }
      // Primary event has no ticket yet — use any other issued line.
      if ($preferred_event_id !== NULL) {
        $ticket = $this->resolveTicketFromOrderItems($order, $customer, NULL);
        if ($ticket instanceof Ticket) {
          return $ticket;
        }
      }
    }

    $order_id = (int) $order->id();
    if ($order_id < 1 || !$this->entityTypeManager->hasDefinition('myeventlane_ticket')) {
      return NULL;
    }

    $ticket = $this->loadFirstTicketForOrder($order_id, $preferred_event_id);
    if ($ticket instanceof Ticket) {
      return $ticket;
    }
    if ($preferred_event_id !== NULL) {
      return $this->loadFirstTicketForOrder($order_id, NULL);
    }
    return NULL;
  }

  /**
   * Walks ticketable order items; optionally restricts to one event.
   */
  private function resolveTicketFromOrderItems(
    OrderInterface $order,
    AccountInterface $customer,
    ?int $event_id,
  ): ?Ticket {
    if (!$this->walletTicketResolver instanceof WalletTicketResolver) {
      return NULL;
    }

    foreach ($order->getItems() as $item) {
      if (!$item instanceof OrderItemInterface) {
        continue;
      }
      if ($this->isDonationItem($item) || $item->bundle() === 'boost') {
        continue;
      }
      if ($event_id !== NULL && $this->orderItemEventId($item) !== $event_id) {
        continue;
      }
      $ticket = $this->walletTicketResolver->resolvePrimaryTicketForOrderItem($item, $customer);
      if ($ticket instanceof Ticket && $this->isPassLinkEligible($ticket)) {
        return $ticket;
      }
    }

    return NULL;
  }

  /**
   * Event node ID from a ticketable order item, or 0 when absent.
   */
  private function orderItemEventId(OrderItemInterface $item): int {
    if (!$item->hasField('field_target_event') || $item->get('field_target_event')->isEmpty()) {
      return 0;
    }
    $event = $item->get('field_target_event')->entity;
    if ($event instanceof NodeInterface && $event->bundle() === 'event') {
      return (int) $event->id();
    }
    return 0;
  }

  /**
   * Loads the lowest-ID wallet-eligible ticket for an order, optionally by event.
   */
  private function loadFirstTicketForOrder(int $order_id, ?int $event_id): ?Ticket {
    try {
      $query = $this->entityTypeManager->getStorage('myeventlane_ticket')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('order_id', $order_id)
        ->condition('status', [Ticket::STATUS_VOID, Ticket::STATUS_REFUNDED], 'NOT IN')
        ->sort('id');
      if ($event_id !== NULL && $event_id > 0) {
        $query->condition('event_id', $event_id);
      }
      $ids = $query->execute();
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not resolve ticket for Digital Pass email actions: @message', [
        '@message' => $e->getMessage(),
        'order_id' => $order_id,
      ]);
      return NULL;
    }

    if (!$ids) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    foreach ($ids as $id) {
      $ticket = $storage->load($id);
      if ($ticket instanceof Ticket && $this->isPassLinkEligible($ticket)) {
        return $ticket;
      }
    }

    return NULL;
  }

  /**
   * Whether a ticket may be linked for PDF / wallet CTAs in confirmation email.
   *
   * Aligns with WalletDownloadAccessChecker::isWalletBlockedStatus() so email
   * never points at a pass that wallet routes will deny.
   */
  private function isPassLinkEligible(Ticket $ticket): bool {
    if ($this->walletDownloadAccess instanceof WalletDownloadAccessChecker) {
      return !$this->walletDownloadAccess->isWalletBlockedStatus($ticket);
    }

    $status = '';
    if ($ticket->hasField('status') && !$ticket->get('status')->isEmpty()) {
      $status = trim((string) $ticket->get('status')->getString());
    }
    if (in_array($status, [Ticket::STATUS_VOID, Ticket::STATUS_REFUNDED], TRUE)) {
      return FALSE;
    }
    return $ticket->getFulfilmentStatus() !== Ticket::FULFILMENT_CANCELLED;
  }

  /**
   * Absolute public URL for a canonical wallet route.
   *
   * @param array<string, int|string> $route_params
   */
  private function absoluteWalletUrl(string $route_name, array $route_params, string $fallback_path): ?string {
    $url = $this->buildPublicUrl($fallback_path);
    if ($url) {
      return $url;
    }
    try {
      return Url::fromRoute($route_name, $route_params, [
        'absolute' => TRUE,
      ])->toString(TRUE)->getGeneratedUrl();
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not generate wallet URL for @route: @message', [
        '@route' => $route_name,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Selects an email-safe wallet URL.
   *
   * WalletActionBuilder may return its path fallback when route generation
   * fails. Host-relative paths are valid on rendered site pages but invalid in
   * email clients, so omit that CTA unless an absolute HTTP(S) URL exists.
   */
  private function walletEmailUrl(?string $canonical_url, mixed $fallback_url): ?string {
    if ($this->isAbsoluteHttpUrl($canonical_url)) {
      return $canonical_url;
    }

    if (is_string($fallback_url) && $this->isAbsoluteHttpUrl($fallback_url)) {
      return $fallback_url;
    }

    if (is_string($fallback_url) && $fallback_url !== '') {
      $this->logger->warning('Omitting wallet email CTA because its fallback URL is not absolute: @url', [
        '@url' => $fallback_url,
      ]);
    }
    return NULL;
  }

  /**
   * Determines whether a URL is usable by an email client.
   */
  private function isAbsoluteHttpUrl(?string $url): bool {
    if ($url === NULL || $url === '') {
      return FALSE;
    }

    $parts = parse_url($url);
    return is_array($parts)
      && isset($parts['host'])
      && isset($parts['scheme'])
      && in_array(strtolower($parts['scheme']), ['http', 'https'], TRUE);
  }

  /**
   * Selects an email-safe badge URL on the public domain.
   *
   * WalletActionBuilder generates base: URLs against the active host. An
   * email may be queued while handling a vendor or admin request, so resolve
   * the asset path through the configured public domain instead. SVG is
   * deliberately excluded because it is not reliably rendered by email
   * clients; the template then uses its accessible text CTA fallback.
   */
  private function walletEmailBadgeUrl(mixed $badge_url): ?string {
    if (!is_string($badge_url) || $badge_url === '') {
      return NULL;
    }

    $path = parse_url($badge_url, PHP_URL_PATH);
    if (!is_string($path) || !str_starts_with($path, '/')) {
      $this->logger->warning('Omitting wallet email badge because its asset path is invalid: @url', [
        '@url' => $badge_url,
      ]);
      return NULL;
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp'], TRUE)) {
      return NULL;
    }

    $public_url = $this->buildPublicUrl($path);
    if ($this->isAbsoluteHttpUrl($public_url)) {
      return $public_url;
    }

    $this->logger->warning('Omitting wallet email badge because its public URL could not be generated: @url', [
      '@url' => $badge_url,
    ]);
    return NULL;
  }

  /**
   * Formats an amount using the Commerce currency formatter.
   */
  private function formatPrice(float $amount, string $currencyCode): string {
    return $this->currencyFormatter->format((string) $amount, $currencyCode);
  }

}
