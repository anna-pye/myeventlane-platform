<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\myeventlane_commerce\Service\TicketAvailabilityService;
use Drupal\myeventlane_event\Service\BookingFlowResolver;
use Drupal\myeventlane_event\Service\TicketTypeManager;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Vendor-console-only presentation flags for ticket commerce integrity.
 *
 * Uses the same public mapping entrypoint as checkout enforcement
 * (TicketAvailabilityService::resolveTierForVariation) without altering purchase
 * behaviour. Paid price display uses TicketTypeManager price loading plus
 * BookingFlowResolver booking mode — not BookingFlowResolver::getDisplayPricing(),
 * to avoid duplicate watchdog warnings on vendor page loads.
 */
final class VendorEventPresentationAlertsBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly TicketAvailabilityService $ticketAvailability,
    private readonly TicketTypeManager $ticketTypeManager,
    private readonly BookingFlowResolver $bookingFlowResolver,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * @return array<string, mixed>
   *   Keys: ticket_mapping_broken, paid_price_display_broken (bool),
   *   items (list of chip-shaped arrays: key, severity, label).
   */
  public function buildChipSummary(NodeInterface $event, bool $hasProduct, string $eventType): array {
    $ticketMappingBroken = FALSE;
    $paidPriceDisplayBroken = FALSE;
    $items = [];

    try {
      $storedType = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
        ? (string) $event->get('field_event_type')->value
        : '';

      if (in_array($storedType, ['paid', 'both'], TRUE) && !$this->hasValidTicketProductReference($event)) {
        $items[] = [
          'key' => 'missing_ticket_product',
          'severity' => 'error',
          'label' => (string) $this->t('Ticket product missing'),
        ];
      }

      if ($hasProduct && in_array($eventType, ['paid', 'both'], TRUE)) {
        $ticketMappingBroken = $this->hasUnmappedPublishedTicketVariation($event);
        if ($ticketMappingBroken) {
          $items[] = [
            'key' => 'ticket_mapping',
            'severity' => 'error',
            'label' => (string) $this->t('Ticket mapping issue'),
          ];
        }
      }

      if (in_array($eventType, ['paid', 'both'], TRUE)) {
        $mode = $this->bookingFlowResolver->getBookingMode($event);
        if ($mode === BookingFlowResolver::MODE_PAID) {
          $prices = $this->ticketTypeManager->loadPublishedPaidTicketPrices($event);
          if ($prices === []) {
            $paidPriceDisplayBroken = TRUE;
            $items[] = [
              'key' => 'paid_price_display',
              'severity' => 'warning',
              'label' => (string) $this->t('Paid price not displaying'),
            ];
          }
        }
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Presentation alerts evaluation failed for event @nid: @message', [
        '@nid' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
    }

    return [
      'ticket_mapping_broken' => $ticketMappingBroken,
      'paid_price_display_broken' => $paidPriceDisplayBroken,
      'items' => $items,
    ];
  }

  /**
   * Rich alerts for the event workspace shell.
   *
   * @return list<array<string, mixed>>
   */
  public function buildWorkspaceAlerts(
    NodeInterface $event,
    bool $hasProduct,
    string $eventType,
    ?Url $editUrl,
    ?Url $advancedTicketsUrl,
  ): array {
    $summary = $this->buildChipSummary($event, $hasProduct, $eventType);
    $out = [];

    $storedType = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? (string) $event->get('field_event_type')->value
      : '';

    if (in_array($storedType, ['paid', 'both'], TRUE) && !$this->hasValidTicketProductReference($event)) {
      $out[] = [
        'key' => 'missing_ticket_product',
        'severity' => 'error',
        'title' => (string) $this->t('Ticket product missing'),
        'message' => (string) $this->t('This paid event cannot sell tickets until its ticket product is created or relinked.'),
        'action_label' => $editUrl
          ? (string) $this->t('Review ticket setup')
          : ($advancedTicketsUrl ? (string) $this->t('Open advanced ticket manager') : (string) $this->t('Repair needed by admin')),
        'action_url' => $editUrl ?: $advancedTicketsUrl,
      ];
    }

    if ($summary['ticket_mapping_broken']) {
      $out[] = [
        'key' => 'ticket_mapping',
        'severity' => 'error',
        'title' => (string) $this->t('Tickets cannot be purchased yet'),
        'message' => (string) $this->t('At least one published ticket variation is not linked to a ticket type on this event. Review ticket setup in Event Studio or the advanced ticket manager. Contact support if this persists after saving.'),
        'action_label' => $advancedTicketsUrl
          ? (string) $this->t('Open advanced ticket manager')
          : ($editUrl ? (string) $this->t('Review ticket setup') : (string) $this->t('Repair needed by admin')),
        'action_url' => $advancedTicketsUrl ?: $editUrl,
      ];
    }

    if ($summary['paid_price_display_broken']) {
      $out[] = [
        'key' => 'paid_price_display',
        'severity' => 'warning',
        'title' => (string) $this->t('Paid prices may not show correctly'),
        'message' => (string) $this->t('No published paid ticket prices were found for this event. Review ticket setup and tier publish/pricing in Event Studio.'),
        'action_label' => $editUrl ? (string) $this->t('Review ticket setup') : (string) $this->t('Repair needed by admin'),
        'action_url' => $editUrl,
      ];
    }

    return $out;
  }

  private function hasValidTicketProductReference(NodeInterface $event): bool {
    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return FALSE;
    }
    $product = $event->get('field_product_target')->entity;
    return $product instanceof ProductInterface && $product->bundle() === 'ticket';
  }

  private function hasUnmappedPublishedTicketVariation(NodeInterface $event): bool {
    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return FALSE;
    }
    $product = $event->get('field_product_target')->entity;
    if (!$product instanceof ProductInterface) {
      return FALSE;
    }
    foreach ($product->getVariations() as $variation) {
      if (!$variation->isPublished()) {
        continue;
      }
      if ($variation->bundle() !== 'ticket_variation') {
        continue;
      }
      if ($this->ticketAvailability->resolveTierForVariation($event, $variation) === NULL) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
