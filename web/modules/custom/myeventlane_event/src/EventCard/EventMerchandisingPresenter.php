<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\EventCard;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Resolves a single primary merchandising signal for public event cards.
 *
 * Uses repository-backed signals only — no invented popularity labels.
 * Secondary stacks, urgency rows, and spotlight chips are intentionally omitted
 * so homepage cards show one body signal plus CTA.
 */
final class EventMerchandisingPresenter {

  use StringTranslationTrait;

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds merchandising presentation for a card context.
   *
   * Priority (one body signal): Sold out → Going → Tonight urgency →
   * Selling fast → Limited spots. Price appears only when no body signal
   * competes (via optional ticket pill on paid discovery cards).
   *
   * @param array{
   *   layout?: string,
   *   variant?: string,
   *   attendance?: int|null,
   *   is_sold_out?: bool,
   *   is_low_stock?: bool,
   *   capacity_hint?: string|null,
   *   is_promoted?: bool,
   *   price_label?: string|null,
   *   card_type?: string,
   *   tonight_urgency_label?: string|null,
   *   mel_source?: string|null,
   * } $context
   *
   * @return array{
   *   primary: array{label: string, modifier: string, signal_key: string}|null,
   *   secondary: null,
   *   urgency: null,
   *   hero_proof: array{label: string, modifier: string, signal_key: string}|null,
   *   spotlight_chip: null,
   *   image_badge_label: string|null,
   *   image_badge_modifier: string,
   *   show_ticket_type_pill: bool,
   *   ticket_pill_label: string|null,
   *   ticket_pill_type: string,
   *   defer_price_to_pill: bool,
   * }
   */
  public function present(array $context): array {
    $layout = (string) ($context['layout'] ?? '');
    $variant = (string) ($context['variant'] ?? '');
    $isHero = $layout === 'hero' || in_array($variant, ['editorial', 'featured_hero'], TRUE);
    $isSpotlight = $layout === 'spotlight';
    $isDiscovery = $layout === 'poster' || $isSpotlight;

    $attendance = isset($context['attendance']) && (int) $context['attendance'] > 0
      ? (int) $context['attendance']
      : NULL;
    $isSoldOut = !empty($context['is_sold_out']);
    $isLowStock = !empty($context['is_low_stock']);
    $capacityHint = trim(strip_tags((string) ($context['capacity_hint'] ?? '')));
    $capacityHint = $capacityHint !== '' ? $capacityHint : NULL;
    $isPromoted = !empty($context['is_promoted']);
    $priceLabel = trim(strip_tags((string) ($context['price_label'] ?? '')));
    $priceLabel = $priceLabel !== '' ? $priceLabel : NULL;
    $cardType = (string) ($context['card_type'] ?? 'none');
    $tonightLabel = trim((string) ($context['tonight_urgency_label'] ?? ''));
    $melSource = (string) ($context['mel_source'] ?? '');
    $showTonight = $melSource === 'homepage_tonight' && $tonightLabel !== '';

    $primary = $this->resolvePrimarySignal(
      $attendance,
      $isSoldOut,
      $isLowStock,
      $capacityHint,
      $showTonight ? $tonightLabel : NULL,
    );

    $heroProof = NULL;
    if ($isHero && !$isSoldOut) {
      if ($attendance !== NULL) {
        $heroProof = $this->signal(
          'attendance',
          (string) $this->formatGoing($attendance),
          'community',
        );
      }
      elseif ($isLowStock) {
        $heroProof = $this->signal('selling_fast', (string) $this->t('Selling fast'), 'urgency');
      }
      elseif ($capacityHint !== NULL) {
        $heroProof = $this->signal('capacity_hint', $capacityHint, 'urgency');
      }
    }

    $imageBadgeLabel = NULL;
    $imageBadgeModifier = 'apricot';
    if ($isSoldOut) {
      $imageBadgeLabel = (string) $this->t('Sold out');
      $imageBadgeModifier = 'warning';
    }
    elseif (($isHero || $isDiscovery) && $isPromoted) {
      $imageBadgeLabel = (string) $this->t('Spotlight');
    }

    $ticketPill = $this->resolveTicketPill($cardType, $priceLabel, $isSoldOut);
    // Price pill only when no competing body signal (RSVP action stays on CTA).
    $showTicketPill = $isDiscovery && $ticketPill !== NULL && $primary === NULL;

    return [
      'primary' => $primary,
      'secondary' => NULL,
      'urgency' => NULL,
      'hero_proof' => $heroProof,
      'spotlight_chip' => NULL,
      'image_badge_label' => $imageBadgeLabel,
      'image_badge_modifier' => $imageBadgeModifier,
      'show_ticket_type_pill' => $showTicketPill,
      'ticket_pill_label' => $showTicketPill ? ($ticketPill['label'] ?? NULL) : NULL,
      'ticket_pill_type' => $ticketPill['type'] ?? $cardType,
      'defer_price_to_pill' => $showTicketPill && $priceLabel !== NULL,
    ];
  }

  /**
   * @return array{label: string, modifier: string, signal_key: string}|null
   */
  private function resolvePrimarySignal(
    ?int $attendance,
    bool $isSoldOut,
    bool $isLowStock,
    ?string $capacityHint,
    ?string $tonightLabel,
  ): ?array {
    if ($isSoldOut) {
      return $this->signal('sold_out', (string) $this->t('Sold out'), 'warning');
    }
    if ($attendance !== NULL) {
      return $this->signal('attendance', (string) $this->formatGoing($attendance), 'community');
    }
    if ($tonightLabel !== NULL) {
      return $this->signal('tonight', $tonightLabel, 'tonight');
    }
    if ($isLowStock) {
      return $this->signal('selling_fast', (string) $this->t('Selling fast'), 'urgency');
    }
    if ($capacityHint !== NULL) {
      return $this->signal('capacity_hint', $capacityHint, 'urgency');
    }

    return NULL;
  }

  /**
   * @return array{label: string, type: string}|null
   */
  private function resolveTicketPill(string $cardType, ?string $priceLabel, bool $isSoldOut): ?array {
    if ($isSoldOut || $cardType !== 'paid') {
      return NULL;
    }
    if ($priceLabel !== NULL) {
      return [
        'label' => $priceLabel,
        'type' => 'paid',
      ];
    }

    return NULL;
  }

  private function formatGoing(int $count): string {
    return (string) $this->formatPlural($count, '1 going', '@count going');
  }

  /**
   * @return array{label: string, modifier: string, signal_key: string}
   */
  private function signal(string $key, string $label, string $modifier): array {
    return [
      'signal_key' => $key,
      'label' => $label,
      'modifier' => $modifier,
    ];
  }

}
