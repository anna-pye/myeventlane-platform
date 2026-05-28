<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_commerce\Service\TicketBackedOrderItemClassifierInterface;
use Drupal\myeventlane_commerce\Service\TicketStatusEvaluator;
use Drupal\myeventlane_commerce\Service\TicketVariationSoldService;
use Drupal\myeventlane_event\Service\EventPaidTicketLoaderInterface;
use Drupal\node\NodeInterface;

/**
 * Read-only Event Studio commerce summaries (tickets vs operational extras).
 *
 * Ticket sold counts use {@see TicketBackedOrderItemClassifierInterface} on
 * completed Commerce orders only. Operational extras never contribute.
 */
final class EventStudioCommerceSalesSummaryBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventPaidTicketLoaderInterface $ticketTierLifecycle,
    private readonly TicketBackedOrderItemClassifierInterface $ticketBackedClassifier,
    private readonly TicketVariationSoldService $variationSold,
    private readonly CurrencyFormatter $currencyFormatter,
    private readonly TimeInterface $time,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds the read-only Ticket sales panel for Event Studio commerce surfaces.
   *
   * @return array<string, mixed>
   */
  public function buildTicketSalesPanel(NodeInterface $event): array {
    $event_id = (int) $event->id();
    if ($event_id < 1 || $event->bundle() !== 'event') {
      return $this->emptyPanel($event);
    }

    $sold_by_variation = $this->countTicketBackedSoldByVariation($event_id);
    $rows = [];
    $cache_tags = $event->getCacheTags();

    foreach ($this->ticketTierLifecycle->loadOrderedTicketsForEvent($event) as $tier) {
      if (!$tier instanceof TicketTypeInterface) {
        continue;
      }
      if ($tier->getTicketKind() !== 'paid') {
        continue;
      }

      $variation = $tier->get('commerce_variation')->entity;
      if (!$variation instanceof ProductVariationInterface) {
        continue;
      }

      $variation_id = (int) $variation->id();
      $sold = (int) ($sold_by_variation[$variation_id] ?? 0);
      $capacity = $this->resolveCapacity($tier, $variation);
      $remaining = $this->formatRemaining($capacity, $sold);
      $percent = $this->formatPercentSold($capacity, $sold);
      $price = $this->formatPrice($variation);
      $status = $this->resolveVendorStatus($tier, $event, $sold, $capacity);

      $rows[] = [
        'ticket_id' => (int) $tier->id(),
        'name' => $tier->label(),
        'price' => $price,
        'capacity' => $capacity,
        'capacity_label' => $this->formatCapacityLabel($capacity),
        'sold' => $sold,
        'remaining' => $remaining,
        'remaining_label' => $remaining['label'],
        'percent_sold' => $percent,
        'percent_sold_label' => $percent !== NULL ? (string) $this->t('@pct%', ['@pct' => number_format($percent, 1)]) : '—',
        'status' => $status['key'],
        'status_label' => $status['label'],
        'status_tone' => $status['tone'],
        'variation_id' => $variation_id,
      ];

      $cache_tags = array_merge($cache_tags, $tier->getCacheTags(), $variation->getCacheTags());
    }

    return [
      'has_rows' => $rows !== [],
      'rows' => $rows,
      'empty_message' => (string) $this->t('No paid ticket types are configured yet. Set up tickets to track attendance capacity and sales here.'),
      'helper_copy' => (string) $this->t('Tickets are attendance capacity. Merch and add-ons are tracked separately.'),
      'edit_tickets_url' => Url::fromRoute('myeventlane_event_studio.workspace_tickets', ['node' => $event_id])->toString(),
      'edit_tickets_label' => (string) $this->t('Edit tickets'),
      'sold_order_state' => 'completed',
      'sold_basis' => 'ticket_backed_order_items',
      'cache_tags' => array_values(array_unique($cache_tags)),
    ];
  }

  /**
   * Builds a themed render array for the read-only ticket sales panel.
   *
   * @return array<string, mixed>
   */
  public function buildTicketSalesPanelRenderArray(NodeInterface $event, int $weight = 0): array {
    $panel = $this->buildTicketSalesPanel($event);
    return [
      '#theme' => 'mel_event_studio_ticket_sales_panel',
      '#panel' => $panel,
      '#weight' => $weight,
      '#cache' => [
        'tags' => $panel['cache_tags'] ?? $event->getCacheTags(),
        'contexts' => ['user'],
      ],
    ];
  }

  /**
   * Completed-order quantities for ticket-backed variations only.
   *
   * @return array<int, int>
   *   Variation ID => sold quantity.
   */
  public function countTicketBackedSoldByVariation(int $event_id): array {
    if ($event_id < 1) {
      return [];
    }

    $order_item_storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $order_storage = $this->entityTypeManager->getStorage('commerce_order');
    $ids = $order_item_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_target_event', $event_id)
      ->execute();
    if (!$ids) {
      return [];
    }

    /** @var \Drupal\commerce_order\Entity\OrderItemInterface[] $items */
    $items = $order_item_storage->loadMultiple($ids);
    $sold = [];
    $completed_cache = [];

    foreach ($items as $item) {
      if (!$item instanceof OrderItemInterface) {
        continue;
      }
      if (!$this->ticketBackedClassifier->isTicketBackedOrderItem($item)) {
        continue;
      }
      if ($item->get('order_id')->isEmpty()) {
        continue;
      }
      $order_id = (int) $item->get('order_id')->target_id;
      if ($order_id < 1) {
        continue;
      }
      if (!array_key_exists($order_id, $completed_cache)) {
        $order = $order_storage->load($order_id);
        $completed_cache[$order_id] = $order && $order->getState()->getId() === 'completed';
      }
      if (!$completed_cache[$order_id]) {
        continue;
      }
      $purchased = $item->getPurchasedEntity();
      if (!$purchased instanceof ProductVariationInterface) {
        continue;
      }
      $vid = (int) $purchased->id();
      $sold[$vid] = ($sold[$vid] ?? 0) + (int) round((float) $item->getQuantity());
    }

    return $sold;
  }

  /**
   * @return array<string, mixed>
   */
  private function emptyPanel(NodeInterface $event): array {
    $event_id = (int) $event->id();
    return [
      'has_rows' => FALSE,
      'rows' => [],
      'empty_message' => (string) $this->t('No paid ticket types are configured yet. Set up tickets to track attendance capacity and sales here.'),
      'helper_copy' => (string) $this->t('Tickets are attendance capacity. Merch and add-ons are tracked separately.'),
      'edit_tickets_url' => $event_id > 0
        ? Url::fromRoute('myeventlane_event_studio.workspace_tickets', ['node' => $event_id])->toString()
        : '',
      'edit_tickets_label' => (string) $this->t('Edit tickets'),
      'sold_order_state' => 'completed',
      'sold_basis' => 'ticket_backed_order_items',
      'cache_tags' => $event->getCacheTags(),
    ];
  }

  private function resolveCapacity(TicketTypeInterface $tier, ProductVariationInterface $variation): ?int {
    if (!$tier->get('capacity')->isEmpty()) {
      $cap = (int) $tier->get('capacity')->value;
      return $cap > 0 ? $cap : NULL;
    }
    if ($variation->hasField('field_capacity') && !$variation->get('field_capacity')->isEmpty()) {
      $cap = (int) $variation->get('field_capacity')->value;
      return $cap > 0 ? $cap : NULL;
    }
    return NULL;
  }

  /**
   * @return array{value: int|null, label: string}
   */
  private function formatRemaining(?int $capacity, int $sold): array {
    if ($capacity === NULL) {
      return [
        'value' => NULL,
        'label' => (string) $this->t('Unlimited'),
      ];
    }
    $remaining = max(0, $capacity - $sold);
    return [
      'value' => $remaining,
      'label' => (string) $remaining,
    ];
  }

  private function formatCapacityLabel(?int $capacity): string {
    if ($capacity === NULL) {
      return (string) $this->t('No limit set');
    }
    return (string) $capacity;
  }

  private function formatPercentSold(?int $capacity, int $sold): ?float {
    if ($capacity === NULL || $capacity < 1) {
      return NULL;
    }
    return round(min(100.0, 100.0 * ($sold / $capacity)), 1);
  }

  private function formatPrice(ProductVariationInterface $variation): string {
    $price = $variation->getPrice();
    if ($price === NULL) {
      return '—';
    }
    return $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());
  }

  /**
   * @return array{key: string, label: string, tone: string}
   */
  private function resolveVendorStatus(
    TicketTypeInterface $tier,
    NodeInterface $event,
    int $sold,
    ?int $capacity,
  ): array {
    if ($tier->isArchived()) {
      return [
        'key' => 'hidden',
        'label' => (string) $this->t('Hidden'),
        'tone' => 'muted',
      ];
    }

    if ($tier->hasField('status') && (int) ($tier->get('status')->value ?? 0) === 0) {
      return [
        'key' => 'hidden',
        'label' => (string) $this->t('Hidden'),
        'tone' => 'muted',
      ];
    }

    if ($tier->hasField('visibility_mode') && !$tier->get('visibility_mode')->isEmpty()) {
      $mode = (string) $tier->get('visibility_mode')->value;
      if ($mode === 'hidden') {
        return [
          'key' => 'hidden',
          'label' => (string) $this->t('Hidden'),
          'tone' => 'muted',
        ];
      }
    }

    $evaluated = TicketStatusEvaluator::evaluate($tier, $this->variationSold, $this->time, $event);
    if ($capacity !== NULL && $capacity > 0 && $sold >= $capacity) {
      $evaluated = TicketStatusEvaluator::STATUS_SOLD_OUT;
    }

    return match ($evaluated) {
      TicketStatusEvaluator::STATUS_SOLD_OUT => [
        'key' => 'sold_out',
        'label' => (string) $this->t('Sold out'),
        'tone' => 'warning',
      ],
      TicketStatusEvaluator::STATUS_UPCOMING => [
        'key' => 'not_started',
        'label' => (string) $this->t('Not started'),
        'tone' => 'info',
      ],
      TicketStatusEvaluator::STATUS_ENDED => [
        'key' => 'ended',
        'label' => (string) $this->t('Ended'),
        'tone' => 'muted',
      ],
      TicketStatusEvaluator::STATUS_INACTIVE, TicketStatusEvaluator::STATUS_ARCHIVED => [
        'key' => 'hidden',
        'label' => (string) $this->t('Hidden'),
        'tone' => 'muted',
      ],
      default => [
        'key' => 'on_sale',
        'label' => (string) $this->t('On sale'),
        'tone' => 'success',
      ],
    };
  }

}
