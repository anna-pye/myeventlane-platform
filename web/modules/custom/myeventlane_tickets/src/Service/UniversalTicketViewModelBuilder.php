<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Ticket\QrCodeGenerator;
use Drupal\myeventlane_tickets\Ticket\TicketQrPayload;
use Drupal\node\NodeInterface;

/**
 * Builds normalized operational view models for issued ticket entitlements.
 */
final class UniversalTicketViewModelBuilder {

  public function __construct(
    private readonly TicketQrPayload $ticketQrPayload,
    private readonly QrCodeGenerator $qrCodeGenerator,
    private readonly TicketCapabilityManager $capabilityManager,
    private readonly RouteProviderInterface $routeProvider,
  ) {}

  /**
   * Builds one canonical operational model for an issued ticket.
   *
   * @return array<string, mixed>
   *   Normalized ticket view model for customer, PDF, wallet and scanner use.
   */
  public function build(Ticket $ticket): array {
    $ticket_code = $this->readString($ticket, 'ticket_code');
    $entitlement_type = $this->capabilityManager->getEntitlementType($ticket);
    $status = $this->readString($ticket, 'status', Ticket::STATUS_ISSUED_UNASSIGNED);
    $fulfilment_status = $ticket->getFulfilmentStatus();
    $qr_payload = $this->ticketQrPayload->buildForTicket($ticket);
    $redemption_limit = $ticket->getRedemptionLimit();
    $redemption_count = $ticket->getRedemptionCount();
    $remaining_redemptions = $this->capabilityManager->getRemainingRedemptions($ticket);
    $is_expired = $this->capabilityManager->isExpired($ticket);
    $can_scan = $this->capabilityManager->canBeScanned($ticket);

    return [
      'ticket' => [
        'id' => (int) $ticket->id(),
        'uuid' => (string) $ticket->uuid(),
        'code' => $ticket_code,
        'status' => $status,
        'status_label' => $this->statusLabel($status),
        'entitlement_type' => $entitlement_type,
        'entitlement_label' => $this->entitlementLabel($entitlement_type),
      ],
      'event' => $this->buildEvent($ticket),
      'holder' => [
        'name' => $this->readString($ticket, 'holder_name'),
        'email' => $this->readString($ticket, 'holder_email'),
        'purchaser_uid' => $this->readTargetId($ticket, 'purchaser_uid'),
      ],
      'qr' => [
        'payload' => $qr_payload,
        'data_uri' => $this->qrCodeGenerator->buildDataUri($qr_payload),
      ],
      'redemption' => [
        'limit' => $redemption_limit,
        'count' => $redemption_count,
        'remaining' => $remaining_redemptions,
        'multi_use' => $redemption_limit > 1,
      ],
      'expiry' => $this->buildExpiry($ticket, $is_expired),
      'fulfilment' => [
        'status' => $fulfilment_status,
        'status_label' => $this->fulfilmentLabel($fulfilment_status),
        'collect_location' => $this->readString($ticket, 'collect_location'),
        'collect_window' => $this->buildCollectWindow($ticket),
        'vehicle_registration' => $this->readString($ticket, 'vehicle_registration'),
        'metadata' => $this->readMap($ticket, 'metadata_json'),
      ],
      'vendor' => $this->buildVendor($ticket),
      'badges' => $this->buildBadges($entitlement_type, $status, $fulfilment_status, $is_expired),
      'actions' => [
        'wallet' => $this->buildWalletActions($ticket),
        'pdf' => $this->buildPdfActions($ticket_code),
      ],
      'scanner' => [
        'can_scan' => $can_scan,
        'status' => $this->scannerStatus($ticket, $can_scan, $is_expired),
        'message' => $this->scannerMessage($ticket, $can_scan, $is_expired),
      ],
    ];
  }

  /**
   * Builds canonical models for multiple issued tickets.
   *
   * @param iterable<\Drupal\myeventlane_tickets\Entity\Ticket> $tickets
   *   Ticket entities to normalize.
   *
   * @return list<array<string, mixed>>
   *   View models in input order.
   */
  public function buildMultiple(iterable $tickets): array {
    $models = [];
    foreach ($tickets as $ticket) {
      if ($ticket instanceof Ticket) {
        $models[] = $this->build($ticket);
      }
    }
    return $models;
  }

  /**
   * Builds event metadata from the linked event entity.
   *
   * @return array<string, mixed>
   *   Normalized event metadata.
   */
  private function buildEvent(Ticket $ticket): array {
    $event = $ticket->get('event_id')->entity;
    $event_id = $this->readTargetId($ticket, 'event_id');
    if (!$event instanceof EntityInterface) {
      return [
        'id' => $event_id,
        'label' => '',
        'url' => '',
        'start' => $this->emptyDateValue(),
        'end' => $this->emptyDateValue(),
        'location' => '',
      ];
    }

    return [
      'id' => $event_id,
      'label' => $event->label(),
      'url' => $this->entityUrl($event),
      'start' => $this->dateFieldValue($event, 'field_event_start'),
      'end' => $this->dateFieldValue($event, 'field_event_end'),
      'location' => $this->eventLocation($event),
    ];
  }

  /**
   * Builds expiry metadata from the ticket expiry field.
   *
   * @return array<string, mixed>
   *   Expiry state.
   */
  private function buildExpiry(Ticket $ticket, bool $is_expired): array {
    $expiry = $this->dateFieldValue($ticket, 'expires_at');
    $expiry['expired'] = $is_expired;
    return $expiry;
  }

  /**
   * Builds collection window metadata.
   *
   * @return array<string, mixed>
   *   Collection window values.
   */
  private function buildCollectWindow(Ticket $ticket): array {
    if (!$ticket->hasField('collect_window') || $ticket->get('collect_window')->isEmpty()) {
      return [
        'start_raw' => '',
        'start_timestamp' => 0,
        'end_raw' => '',
        'end_timestamp' => 0,
      ];
    }

    $item = $ticket->get('collect_window')->first();
    $start = (string) ($item?->get('value')->getValue() ?? '');
    $end = (string) ($item?->get('end_value')->getValue() ?? '');

    return [
      'start_raw' => $start,
      'start_timestamp' => $this->timestamp($start),
      'end_raw' => $end,
      'end_timestamp' => $this->timestamp($end),
    ];
  }

  /**
   * Builds vendor display metadata.
   *
   * @return array<string, mixed>
   *   Vendor display data.
   */
  private function buildVendor(Ticket $ticket): array {
    if ($ticket->hasField('vendor_reference') && !$ticket->get('vendor_reference')->isEmpty()) {
      $vendor = $ticket->get('vendor_reference')->entity;
      if ($vendor instanceof EntityInterface) {
        return [
          'id' => (int) $vendor->id(),
          'label' => $vendor->label(),
          'source' => 'ticket',
        ];
      }
    }

    $event = $ticket->get('event_id')->entity;
    if ($event instanceof NodeInterface && $event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      $vendor = $event->get('field_event_vendor')->entity;
      if ($vendor instanceof EntityInterface) {
        return [
          'id' => (int) $vendor->id(),
          'label' => $vendor->label(),
          'source' => 'event',
        ];
      }
    }

    return [
      'id' => 0,
      'label' => '',
      'source' => '',
    ];
  }

  /**
   * Builds normalized operational badge data.
   *
   * @return list<array<string, string>>
   *   Badge token and label pairs.
   */
  private function buildBadges(string $entitlement_type, string $status, string $fulfilment_status, bool $is_expired): array {
    $badges = [
      [
        'type' => 'entitlement',
        'token' => $entitlement_type,
        'label' => $this->entitlementLabel($entitlement_type),
      ],
      [
        'type' => 'status',
        'token' => $status,
        'label' => $this->statusLabel($status),
      ],
    ];

    if ($fulfilment_status !== Ticket::FULFILMENT_PENDING) {
      $badges[] = [
        'type' => 'fulfilment',
        'token' => $fulfilment_status,
        'label' => $this->fulfilmentLabel($fulfilment_status),
      ];
    }
    if ($is_expired) {
      $badges[] = [
        'type' => 'expiry',
        'token' => 'expired',
        'label' => 'Expired',
      ];
    }

    return $badges;
  }

  /**
   * Builds PDF actions using the canonical ticket-code route.
   *
   * @return array<string, mixed>
   *   PDF action metadata.
   */
  private function buildPdfActions(string $ticket_code): array {
    return [
      'download' => [
        'label' => 'Download PDF',
        'route' => 'myeventlane_tickets.download_pdf_by_code',
        'url' => $ticket_code !== '' ? $this->routeUrl(
          'myeventlane_tickets.download_pdf_by_code',
          ['ticket_code' => $ticket_code],
          '/ticket/' . rawurlencode($ticket_code) . '/pdf',
        ) : '',
      ],
    ];
  }

  /**
   * Builds existing wallet route actions without changing wallet route contracts.
   *
   * @return array<string, mixed>
   *   Wallet action metadata.
   */
  private function buildWalletActions(Ticket $ticket): array {
    $order_item_id = $this->readTargetId($ticket, 'order_item_id');
    if ($order_item_id < 1) {
      return [
        'apple' => NULL,
        'google' => NULL,
      ];
    }

    return [
      'apple' => [
        'label' => 'Add to Apple Wallet',
        'route' => 'myeventlane_wallet.apple',
        'url' => $this->routeUrl('myeventlane_wallet.apple', ['order_item_id' => $order_item_id], '/wallet/apple/' . $order_item_id),
      ],
      'google' => [
        'label' => 'Add to Google Wallet',
        'route' => 'myeventlane_wallet.google',
        'url' => $this->routeUrl('myeventlane_wallet.google', ['order_item_id' => $order_item_id], '/wallet/google/' . $order_item_id),
      ],
    ];
  }

  /**
   * Determines the scanner status token.
   */
  private function scannerStatus(Ticket $ticket, bool $can_scan, bool $is_expired): string {
    $status = $this->readString($ticket, 'status', Ticket::STATUS_ISSUED_UNASSIGNED);
    if ($status === Ticket::STATUS_VOID) {
      return 'void';
    }
    if ($status === Ticket::STATUS_REFUNDED) {
      return 'refunded';
    }
    if ($is_expired) {
      return 'expired';
    }
    if ($this->capabilityManager->isTicket($ticket) && $status === Ticket::STATUS_CHECKED_IN) {
      return 'checked_in';
    }
    if (!$can_scan && $this->capabilityManager->getRemainingRedemptions($ticket) === 0) {
      return 'redemption_limit_reached';
    }
    if ($status === Ticket::STATUS_ISSUED_UNASSIGNED) {
      return 'unassigned';
    }
    return 'ready';
  }

  /**
   * Determines the scanner status message.
   */
  private function scannerMessage(Ticket $ticket, bool $can_scan, bool $is_expired): string {
    return match ($this->scannerStatus($ticket, $can_scan, $is_expired)) {
      'void' => 'Ticket is void and cannot be scanned.',
      'refunded' => 'Ticket was refunded and cannot be scanned.',
      'expired' => 'Entitlement has expired.',
      'checked_in' => 'Ticket is already checked in.',
      'redemption_limit_reached' => 'Redemption limit has already been reached.',
      'unassigned' => 'Ticket is issued but holder details are not assigned.',
      default => 'Ready to scan.',
    };
  }

  /**
   * Reads a simple string field.
   */
  private function readString(Ticket $ticket, string $field_name, string $default = ''): string {
    if (!$ticket->hasField($field_name) || $ticket->get($field_name)->isEmpty()) {
      return $default;
    }
    $value = trim((string) $ticket->get($field_name)->value);
    return $value !== '' ? $value : $default;
  }

  /**
   * Reads an entity reference target ID.
   */
  private function readTargetId(Ticket $ticket, string $field_name): int {
    if (!$ticket->hasField($field_name) || $ticket->get($field_name)->isEmpty()) {
      return 0;
    }
    return (int) $ticket->get($field_name)->target_id;
  }

  /**
   * Reads a map field into an array.
   *
   * @return array<string, mixed>
   *   Map value.
   */
  private function readMap(Ticket $ticket, string $field_name): array {
    if (!$ticket->hasField($field_name) || $ticket->get($field_name)->isEmpty()) {
      return [];
    }

    $value = $ticket->get($field_name)->first()?->getValue() ?? [];
    if (isset($value['value']) && is_array($value['value'])) {
      return $value['value'];
    }
    return is_array($value) ? $value : [];
  }

  /**
   * Returns a URL for an entity if one is available.
   */
  private function entityUrl(EntityInterface $entity): string {
    try {
      return $entity->toUrl()->toString();
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Returns a URL for a route if the route exists.
   *
   * @param array<string, mixed> $parameters
   *   Route parameters.
   */
  private function routeUrl(string $route_name, array $parameters, string $fallback = ''): string {
    try {
      $this->routeProvider->getRouteByName($route_name);
      return Url::fromRoute($route_name, $parameters)->toString();
    }
    catch (\Throwable) {
      return $fallback;
    }
  }

  /**
   * Reads a datetime field into raw and timestamp values.
   *
   * @return array<string, mixed>
   *   Date metadata.
   */
  private function dateFieldValue(EntityInterface $entity, string $field_name): array {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return $this->emptyDateValue();
    }

    $raw = (string) ($entity->get($field_name)->value ?? '');
    return [
      'raw' => $raw,
      'timestamp' => $this->timestamp($raw),
    ];
  }

  /**
   * Empty date metadata.
   *
   * @return array<string, mixed>
   *   Empty date metadata.
   */
  private function emptyDateValue(): array {
    return [
      'raw' => '',
      'timestamp' => 0,
    ];
  }

  /**
   * Parses a Drupal UTC datetime string to a timestamp.
   */
  private function timestamp(string $raw): int {
    $raw = trim($raw);
    if ($raw === '') {
      return 0;
    }
    $timestamp = strtotime($raw . ' UTC');
    return $timestamp === FALSE ? 0 : $timestamp;
  }

  /**
   * Extracts a stable customer-facing event location string.
   */
  private function eventLocation(EntityInterface $event): string {
    foreach (['field_venue_name', 'field_location'] as $field_name) {
      if (!$event->hasField($field_name) || $event->get($field_name)->isEmpty()) {
        continue;
      }
      $item = $event->get($field_name)->first();
      if ($item === NULL) {
        continue;
      }
      $value = $item->getValue();
      if (isset($value['value']) && is_string($value['value'])) {
        $location = trim($value['value']);
        if ($location !== '') {
          return $location;
        }
      }
      $parts = [];
      foreach (['address_line1', 'address_line2', 'locality', 'administrative_area', 'postal_code'] as $key) {
        if (!empty($value[$key]) && is_scalar($value[$key])) {
          $parts[] = trim((string) $value[$key]);
        }
      }
      if ($parts !== []) {
        return implode(', ', array_filter($parts));
      }
    }

    return '';
  }

  /**
   * Returns a human-readable entitlement label.
   */
  private function entitlementLabel(string $type): string {
    return match ($type) {
      Ticket::ENTITLEMENT_MERCH => 'Merch pickup',
      Ticket::ENTITLEMENT_PARKING => 'Parking',
      Ticket::ENTITLEMENT_DRINK => 'Drink voucher',
      Ticket::ENTITLEMENT_FOOD => 'Food voucher',
      Ticket::ENTITLEMENT_VIP => 'VIP access',
      Ticket::ENTITLEMENT_ADDON => 'Event add-on',
      default => 'Ticket',
    };
  }

  /**
   * Returns a human-readable ticket status label.
   */
  private function statusLabel(string $status): string {
    return match ($status) {
      Ticket::STATUS_ASSIGNED => 'Assigned',
      Ticket::STATUS_CHECKED_IN => 'Checked in',
      Ticket::STATUS_REFUNDED => 'Refunded',
      Ticket::STATUS_VOID => 'Void',
      default => 'Issued',
    };
  }

  /**
   * Returns a human-readable fulfilment status label.
   */
  private function fulfilmentLabel(string $status): string {
    return match ($status) {
      Ticket::FULFILMENT_READY => 'Ready',
      Ticket::FULFILMENT_COLLECTED => 'Collected',
      Ticket::FULFILMENT_REDEEMED => 'Redeemed',
      Ticket::FULFILMENT_EXPIRED => 'Expired',
      Ticket::FULFILMENT_CANCELLED => 'Cancelled',
      default => 'Pending',
    };
  }

}
