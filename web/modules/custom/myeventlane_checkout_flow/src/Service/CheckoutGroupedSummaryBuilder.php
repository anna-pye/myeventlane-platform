<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_price\Price;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\myeventlane_commerce\Service\OrderItemClassifier;
use Drupal\myeventlane_event\Service\BookingFlowResolver;
use Drupal\myeventlane_surface\MelReadinessHelper;
use Drupal\node\NodeInterface;

/**
 * Builds event-grouped checkout summary rows for presentation only.
 */
final class CheckoutGroupedSummaryBuilder implements CheckoutGroupedSummaryBuilderInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CurrencyFormatter $currencyFormatter,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly OrderPricingBreakdownBuilder $orderPricingBreakdown,
    private readonly BookingFlowResolver $bookingFlowResolver,
    private readonly OrderItemClassifier $orderItemClassifier,
    private readonly MelReadinessHelper $readinessHelper,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Builds variables for mel_checkout_order_summary_grouped.
   *
   * @return array<string, mixed>
   *   Template variables including GST-aware totals.
   */
  public function build(OrderInterface $order): array {
    $line_buckets = [];
    $donationTotals = [];
    $cacheTags = $order->getCacheTags();

    foreach ($order->getItems() as $item) {
      if ($this->orderItemClassifier->isDonation($item)) {
        $donationPrice = $item->getTotalPrice();
        if ($donationPrice instanceof Price && !$donationPrice->isZero()) {
          $currencyCode = $donationPrice->getCurrencyCode();
          $donationTotals[$currencyCode] = isset($donationTotals[$currencyCode])
            ? $donationTotals[$currencyCode]->add($donationPrice)
            : $donationPrice;
        }
        continue;
      }

      $event_id = $this->resolveEventId($item);
      $purchased = $item->getPurchasedEntity();
      $line_key = $purchased ? 'p:' . $purchased->id() : 'i:' . $item->id();
      $bucket_key = ($event_id ?? 'other') . ':' . $line_key;

      $ticket_type = $purchased ? $purchased->label() : $item->label();

      if (!isset($line_buckets[$bucket_key])) {
        $line_buckets[$bucket_key] = [
          'event_id' => $event_id,
          'ticket_type' => $ticket_type,
          'quantity' => 0,
          'total' => NULL,
        ];
      }

      $line_buckets[$bucket_key]['quantity'] += (int) $item->getQuantity();
      $line_price = $item->getTotalPrice();
      if ($line_price instanceof Price) {
        $current = $line_buckets[$bucket_key]['total'];
        $line_buckets[$bucket_key]['total'] = $current instanceof Price
          ? $current->add($line_price)
          : $line_price;
      }
    }

    // Skip adjustments when donation line items already contributed (migration overlap).
    if ($donationTotals === []) {
      foreach ($order->getAdjustments() as $adjustment) {
        if ((string) $adjustment->getLabel() !== 'Contribution') {
          continue;
        }
        $donationPrice = $adjustment->getAmount();
        if ($donationPrice instanceof Price && !$donationPrice->isZero()) {
          $currencyCode = $donationPrice->getCurrencyCode();
          $donationTotals[$currencyCode] = isset($donationTotals[$currencyCode])
            ? $donationTotals[$currencyCode]->add($donationPrice)
            : $donationPrice;
        }
      }
    }

    $donationFormatted = '';
    if ($donationTotals !== []) {
      $donationFormatted = implode(' + ', array_map(
        fn (Price $price): string => $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode()),
        array_values($donationTotals)
      ));
    }

    $events_map = [];
    $labels = $this->readinessHelper->customerCheckoutOrderSummarySurfaceLabels();

    foreach ($line_buckets as $bucket) {
      $eid = $bucket['event_id'];
      $group_key = $eid ?? 'other';

      if (!isset($events_map[$group_key])) {
        $events_map[$group_key] = [
          'title' => '',
          'date' => '',
          'location' => '',
          'display_pricing' => '',
          'thumbnail_url' => '',
          'items' => [],
        ];
        if ($eid !== NULL) {
          $node = $this->entityTypeManager->getStorage('node')->load($eid);
          if ($node instanceof NodeInterface && $node->bundle() === 'event') {
            $displayPricing = $this->bookingFlowResolver->getDisplayPricing($node);
            $cacheTags = Cache::mergeTags($cacheTags, $node->getCacheTags());
            $events_map[$group_key]['title'] = $node->label();
            $events_map[$group_key]['date'] = $this->formatEventDate($node);
            $events_map[$group_key]['location'] = $this->formatEventLocation($node);
            $events_map[$group_key]['display_pricing'] = is_array($displayPricing)
              ? (string) ($displayPricing['label'] ?? '')
              : '';
            $events_map[$group_key]['thumbnail_url'] = $this->resolveEventThumbnailUrl($node);
          }
          else {
            $events_map[$group_key]['title'] = $labels['event_fallback_title'];
            $events_map[$group_key]['thumbnail_url'] = '';
          }
        }
        else {
          $events_map[$group_key]['title'] = $labels['additional_items_title'];
          $events_map[$group_key]['thumbnail_url'] = '';
        }
      }

      $total_price = '';
      if ($bucket['total'] instanceof Price) {
        $total_price = $this->currencyFormatter->format(
          $bucket['total']->getNumber(),
          $bucket['total']->getCurrencyCode()
        );
      }

      $events_map[$group_key]['items'][] = [
        'quantity' => $bucket['quantity'],
        'ticket_type' => $bucket['ticket_type'],
        'total_price' => $total_price,
      ];
    }

    $grouped_items = array_values($events_map);
    usort($grouped_items, static function (array $a, array $b): int {
      return strcasecmp($a['title'], $b['title']);
    });

    if ($grouped_items === [] && $donationFormatted !== '') {
      $grouped_items = [[
        'title' => $this->readinessHelper->customerCheckoutContributionSummaryGroupTitle(),
        'date' => '',
        'location' => '',
        'display_pricing' => '',
        'thumbnail_url' => '',
        'items' => [[
          'quantity' => 1,
          'ticket_type' => $this->readinessHelper->customerCheckoutContributionLineLabel(),
          'total_price' => $donationFormatted,
        ]],
      ]];
    }

    $order_total = '';
    $total_price = $order->getTotalPrice();
    if ($total_price instanceof Price) {
      $order_total = $this->currencyFormatter->format(
        $total_price->getNumber(),
        $total_price->getCurrencyCode()
      );
    }

    $breakdown = $this->orderPricingBreakdown->build($order);

    return [
      'grouped_items' => $grouped_items,
      'order_total' => $order_total,
      'subtotal_formatted' => $breakdown['subtotal_formatted'],
      'optional_donation_formatted' => $donationFormatted,
      'tax_rows' => $breakdown['tax_rows'],
      'fee_rows' => $breakdown['fee_rows'],
      'platform_fee_absorbed' => $breakdown['platform_fee_absorbed'],
      'show_includes_gst_note' => $breakdown['show_includes_gst_note'],
      'cache_tags' => $cacheTags,
    ];
  }

  /**
   * Resolves event node ID from an order item, if known.
   */
  private function resolveEventId(OrderItemInterface $item): ?int {
    if (method_exists($item, 'hasField') && $item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
      return (int) $item->get('field_target_event')->target_id;
    }
    if (!method_exists($item, 'getPurchasedEntity')) {
      return NULL;
    }
    $purchased = $item->getPurchasedEntity();
    if ($purchased && $purchased->hasField('field_event') && !$purchased->get('field_event')->isEmpty()) {
      return (int) $purchased->get('field_event')->target_id;
    }
    return NULL;
  }

  private function formatEventDate(NodeInterface $node): string {
    if (!$node->hasField('field_event_start') || $node->get('field_event_start')->isEmpty()) {
      return '';
    }
    $date = $node->get('field_event_start')->date;
    if (!$date) {
      return '';
    }
    return $this->dateFormatter->format($date->getTimestamp(), 'medium');
  }

  private function resolveEventThumbnailUrl(NodeInterface $node): string {
    if (!$node->hasField('field_event_image') || $node->get('field_event_image')->isEmpty()) {
      return '';
    }
    $files = $node->get('field_event_image')->referencedEntities();
    $file = !empty($files) ? reset($files) : NULL;
    if (!$file || !method_exists($file, 'getFileUri')) {
      return '';
    }
    return $this->fileUrlGenerator->generateString($file->getFileUri());
  }

  private function formatEventLocation(NodeInterface $node): string {
    $parts = [];
    if ($node->hasField('field_venue_name') && !$node->get('field_venue_name')->isEmpty()) {
      $parts[] = trim((string) $node->get('field_venue_name')->value);
    }
    if ($node->hasField('field_venue_address') && !$node->get('field_venue_address')->isEmpty()) {
      $item = $node->get('field_venue_address')->first();
      if ($item) {
        $values = $item->getValue();
        $line_parts = array_filter([
          !empty($values['address_line1']) ? trim((string) $values['address_line1']) : '',
          !empty($values['locality']) ? trim((string) $values['locality']) : '',
        ], static fn(string $s): bool => $s !== '');
        if ($line_parts !== []) {
          $parts[] = implode(', ', $line_parts);
        }
      }
    }
    return implode(' · ', array_filter($parts, static fn(string $s): bool => $s !== ''));
  }

}
