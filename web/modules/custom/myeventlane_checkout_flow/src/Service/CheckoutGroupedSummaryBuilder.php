<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_price\Price;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;

/**
 * Builds event-grouped checkout summary rows for presentation only.
 */
final class CheckoutGroupedSummaryBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CurrencyFormatter $currencyFormatter,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Builds variables for mel_checkout_order_summary_grouped.
   *
   * @return array{grouped_items: array<int, array<string, mixed>>, order_total: string}
   *   Template variables.
   */
  public function build(OrderInterface $order): array {
    $line_buckets = [];

    foreach ($order->getItems() as $item) {
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

    $events_map = [];

    foreach ($line_buckets as $bucket) {
      $eid = $bucket['event_id'];
      $group_key = $eid ?? 'other';

      if (!isset($events_map[$group_key])) {
        $events_map[$group_key] = [
          'title' => '',
          'date' => '',
          'location' => '',
          'items' => [],
        ];
        if ($eid !== NULL) {
          $node = $this->entityTypeManager->getStorage('node')->load($eid);
          if ($node instanceof NodeInterface && $node->bundle() === 'event') {
            $events_map[$group_key]['title'] = $node->label();
            $events_map[$group_key]['date'] = $this->formatEventDate($node);
            $events_map[$group_key]['location'] = $this->formatEventLocation($node);
          }
          else {
            $events_map[$group_key]['title'] = (string) $this->t('Event');
          }
        }
        else {
          $events_map[$group_key]['title'] = (string) $this->t('Additional items');
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

    $order_total = '';
    $total_price = $order->getTotalPrice();
    if ($total_price instanceof Price) {
      $order_total = $this->currencyFormatter->format(
        $total_price->getNumber(),
        $total_price->getCurrencyCode()
      );
    }

    return [
      'grouped_items' => $grouped_items,
      'order_total' => $order_total,
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
