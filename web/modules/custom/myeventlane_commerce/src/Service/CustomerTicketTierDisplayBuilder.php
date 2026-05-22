<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\mel_ticket\Entity\TicketType;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_event\Service\EventPaidTicketLoaderInterface;
use Drupal\myeventlane_commerce\Service\EventDefaultTicketResolverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;

/**
 * Read-only customer-visible ticket rows for display parity (book page, Studio).
 */
final class CustomerTicketTierDisplayBuilder {

  use StringTranslationTrait;

  /**
   * @param \Drupal\myeventlane_commerce\Service\TicketAvailabilityService $ticketAvailability
   *   Purchasability and buyer availability helpers.
   * @param \Drupal\myeventlane_event\Service\TicketTierLifecycleService $ticketTierLifecycle
   *   Ordered mel_ticket_type rows for an event.
   * @param \Drupal\commerce_price\CurrencyFormatter $currencyFormatter
   *   Formats variation prices for display.
   * @param \Drupal\myeventlane_commerce\Service\TicketTierWaitlistService $tierWaitlist
   *   Waitlist hold counts for availability copy.
   * @param \Drupal\myeventlane_commerce\Service\EventDefaultTicketResolverInterface $defaultTicketResolver
   *   Resolves default ticket ordering aligned with TicketSelectionForm.
   */
  public function __construct(
    private readonly TicketCustomerDisplayGatewayInterface $ticketAvailability,
    private readonly EventPaidTicketLoaderInterface $ticketTierLifecycle,
    private readonly CurrencyFormatter $currencyFormatter,
    private readonly TicketTierWaitlistHoldCounterInterface $tierWaitlist,
    private readonly EventDefaultTicketResolverInterface $defaultTicketResolver,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds customer-visible rows and vendor-only exclusion diagnostics.
   *
   * @return array{
   *   visible_rows: list<array{
   *     name: string,
   *     price: string,
   *     availability: string,
   *     short_description: string|null,
   *     is_best_value: bool,
   *     variation_id: int,
   *     ticket_id: int|null,
   *   }>,
   *   excluded_rows: list<array{name: string, diagnostic: string}>,
   *   cache_tags: list<string>,
   * }
   */
  public function buildForEvent(NodeInterface $event): array {
    $empty = [
      'visible_rows' => [],
      'excluded_rows' => [],
      'cache_tags' => $this->baseCacheTags($event),
    ];

    if ($event->bundle() !== 'event' || $event->isNew() || $event->id() === NULL) {
      return $empty;
    }

    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return $empty;
    }

    $product = $event->get('field_product_target')->entity;
    if (!$product instanceof ProductInterface) {
      return $empty;
    }

    $cache_tags = $this->baseCacheTags($event, $product);
    if (!$product->isPublished()) {
      return [
        'visible_rows' => [],
        'excluded_rows' => [[
          'name' => (string) $this->t('Ticket product'),
          'diagnostic' => (string) $this->t('Hidden from checkout: the linked ticket product is not published.'),
        ]],
        'cache_tags' => $cache_tags,
      ];
    }

    $context = $this->ticketAvailability->buildDefaultCustomerAccessContext($event);
    $purchasable = $this->ticketAvailability->filterPurchasableVariations($event, $product, $context);
    $purchasable = $this->sortPurchasableLikeBookForm($event, $purchasable);
    $best_value_variation_id = $this->resolveBestValueVariationId($event);
    $purchasable_variation_ids = [];
    foreach ($purchasable as $variation) {
      $purchasable_variation_ids[(int) $variation->id()] = $variation;
    }

    $label_map = $this->buildVariationLabelMap($event);
    $visible_rows = [];
    foreach ($purchasable as $variation) {
      $variation_id = (int) $variation->id();
      $tier = $this->ticketAvailability->resolveTierForVariation($event, $variation);
      $visible_rows[] = [
        'name' => $this->resolveCustomerLabel($variation, $label_map),
        'price' => $this->formatVariationPrice($variation),
        'availability' => $this->buyerFacingAvailabilityMessage($event, $tier, $variation_id),
        'short_description' => $this->resolveShortDescription($tier),
        'is_best_value' => $best_value_variation_id !== NULL && $variation_id === $best_value_variation_id,
        'variation_id' => $variation_id,
        'ticket_id' => $tier instanceof TicketTypeInterface ? (int) $tier->id() : NULL,
      ];
      if ($tier instanceof TicketTypeInterface) {
        $cache_tags = array_merge($cache_tags, $tier->getCacheTags());
      }
      $cache_tags[] = 'commerce_product_variation:' . (string) $variation_id;
    }

    $excluded_rows = [];
    foreach ($this->ticketTierLifecycle->loadOrderedTicketsForEvent($event) as $ticket) {
      if ($ticket->getTicketKind() !== 'paid' || $ticket->isArchived()) {
        continue;
      }
      $variation = $ticket->get('commerce_variation')->entity;
      $variation_id = $variation instanceof ProductVariationInterface ? (int) $variation->id() : 0;
      if ($variation_id > 0 && isset($purchasable_variation_ids[$variation_id])) {
        continue;
      }
      $excluded_rows[] = [
        'name' => $ticket->getTitle(),
        'diagnostic' => $this->ticketAvailability->explainVendorPreviewExclusion(
          $event,
          $product,
          $ticket,
          $variation instanceof ProductVariationInterface ? $variation : NULL,
          $context,
        ),
      ];
      $cache_tags = array_merge($cache_tags, $ticket->getCacheTags());
    }

    return [
      'visible_rows' => $visible_rows,
      'excluded_rows' => $excluded_rows,
      'cache_tags' => array_values(array_unique($cache_tags)),
    ];
  }

  /**
   * Buyer-facing availability line aligned with TicketSelectionForm.
   */
  public function buyerFacingAvailabilityMessage(
    NodeInterface $event,
    ?TicketTypeInterface $tier,
    int $variationId,
  ): string {
    if (!$tier instanceof TicketTypeInterface) {
      return (string) $this->t('Available');
    }
    if ($tier->get('capacity')->isEmpty()) {
      return (string) $this->t('Available');
    }
    $cap = (int) $tier->get('capacity')->value;
    if ($cap < 1) {
      return (string) $this->t('Available');
    }
    $eid = (int) $event->id();
    $sold = $this->ticketAvailability->countCompletedSoldForVariation($eid, $variationId);
    $held = $this->tierWaitlist->sumActiveOfferReserved($eid, (int) $tier->id());
    $pool = max(0, $cap - $sold - $held);
    if ($pool < 1) {
      return (string) $this->t('Limited availability');
    }
    if ($pool === 1) {
      return (string) $this->t('Only 1 left');
    }
    if ($pool <= 10) {
      return (string) $this->t('Only @count left', ['@count' => (string) $pool]);
    }
    return (string) $this->t('Available');
  }

  /**
   * @return array<string, string>
   */
  private function buildVariationLabelMap(NodeInterface $event): array {
    $map = [];
    if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return $map;
    }
    foreach ($event->get('field_ticket_types')->referencedEntities() as $ticket) {
      if (!$ticket instanceof TicketTypeInterface || $ticket->isArchived()) {
        continue;
      }
      if ($ticket instanceof TicketType && !$ticket->get('commerce_variation')->isEmpty()) {
        $variation = $ticket->get('commerce_variation')->entity;
        if ($variation instanceof ProductVariationInterface) {
          $map[$variation->uuid()] = $ticket->label();
        }
      }
    }
    return $map;
  }

  /**
   * @param array<string, string> $label_map
   */
  private function resolveCustomerLabel(
    ProductVariationInterface $variation,
    array $label_map,
  ): string {
    $label = $label_map[$variation->uuid()] ?? $variation->label();
    if (strpos($label, ' – ') !== FALSE) {
      $parts = explode(' – ', $label, 2);
      $label = $parts[1] ?? $label;
    }
    return $label;
  }

  /**
   * Aligns purchasable variation order with TicketSelectionForm.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface[] $variations
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface[]
   */
  private function sortPurchasableLikeBookForm(NodeInterface $event, array $variations): array {
    $default_tier = $this->defaultTicketResolver->getDefaultTicket($event);
    $default_variation_id = $this->resolveDefaultVariationId($default_tier);
    if ($default_variation_id === NULL) {
      return $variations;
    }
    return $this->sortVariationsDefaultFirst($variations, $default_variation_id);
  }

  private function resolveDefaultVariationId(?TicketTypeInterface $tier): ?int {
    if (!$tier instanceof TicketTypeInterface
      || !$tier->hasField('commerce_variation')
      || $tier->get('commerce_variation')->isEmpty()) {
      return NULL;
    }
    $variation_id = (int) $tier->get('commerce_variation')->target_id;
    return $variation_id > 0 ? $variation_id : NULL;
  }

  /**
   * First mel_ticket_type flagged field_is_best_value, matching TicketSelectionForm.
   */
  private function resolveBestValueVariationId(NodeInterface $event): ?int {
    if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return NULL;
    }

    foreach ($event->get('field_ticket_types')->referencedEntities() as $ticket) {
      if (!$ticket instanceof TicketTypeInterface || $ticket->isArchived()) {
        continue;
      }
      if (!$ticket->hasField('field_is_best_value') || !$ticket->isBestValueTicket()) {
        continue;
      }
      if (!$ticket->hasField('commerce_variation') || $ticket->get('commerce_variation')->isEmpty()) {
        continue;
      }
      $variation_id = (int) $ticket->get('commerce_variation')->target_id;
      return $variation_id > 0 ? $variation_id : NULL;
    }

    return NULL;
  }

  /**
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface[] $variations
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface[]
   */
  private function sortVariationsDefaultFirst(array $variations, int $defaultVariationId): array {
    usort($variations, static function (ProductVariationInterface $a, ProductVariationInterface $b) use ($defaultVariationId): int {
      $a_default = (int) $a->id() === $defaultVariationId;
      $b_default = (int) $b->id() === $defaultVariationId;
      if ($a_default === $b_default) {
        return 0;
      }
      return $a_default ? -1 : 1;
    });
    return $variations;
  }

  private function resolveShortDescription(?TicketTypeInterface $tier): ?string {
    if (!$tier instanceof TicketTypeInterface) {
      return NULL;
    }
    if (!$tier->hasField('short_description') || $tier->get('short_description')->isEmpty()) {
      return NULL;
    }
    $text = trim((string) $tier->get('short_description')->value);
    return $text !== '' ? $text : NULL;
  }

  private function formatVariationPrice(ProductVariationInterface $variation): string {
    $price = $variation->getPrice();
    if (!$price instanceof Price) {
      return (string) $this->t('Free');
    }
    if ($price->isZero()) {
      return (string) $this->t('Free');
    }
    return $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());
  }

  /**
   * @return list<string>
   */
  private function baseCacheTags(NodeInterface $event, ?ProductInterface $product = NULL): array {
    $tags = $event->getCacheTags();
    if ($product instanceof ProductInterface) {
      $tags = array_merge($tags, $product->getCacheTags());
      foreach ($product->getVariations() as $variation) {
        $tags[] = 'commerce_product_variation:' . (string) $variation->id();
      }
    }
    foreach ($this->ticketTierLifecycle->loadOrderedTicketsForEvent($event) as $ticket) {
      $tags = array_merge($tags, $ticket->getCacheTags());
    }
    return array_values(array_unique($tags));
  }

}
