<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_core\PlatformFeeDefaults;

/**
 * Resolves ticket and operational extras platform fee percentages from config.
 */
final class PlatformFeeSettings {

  use StringTranslationTrait;

  /**
   * When extras fee is unset, it defaults to ticket fee × this multiplier.
   */
  public const EXTRAS_FEE_TICKET_MULTIPLIER = 2.0;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Ticket platform fee percentage (0–100).
   */
  public function getTicketFeePercent(): float {
    return PlatformFeeDefaults::normalizePercent(
      $this->configFactory->get('myeventlane_core.settings')->get('platform_fee_percent')
    );
  }

  /**
   * Operational extras platform fee percentage (0–100).
   *
   * Uses {@code operational_extras_platform_fee_percent} when set; otherwise
   * double the ticket fee (capped at 100%).
   */
  public function getOperationalExtrasFeePercent(): float {
    $raw = $this->configFactory->get('myeventlane_core.settings')->get('operational_extras_platform_fee_percent');
    if ($raw === NULL || $raw === '') {
      $derived = $this->getTicketFeePercent() * self::EXTRAS_FEE_TICKET_MULTIPLIER;
      return PlatformFeeDefaults::normalizePercent($derived);
    }
    return PlatformFeeDefaults::normalizePercent($raw);
  }

  /**
   * Whether the effective extras fee equals double the ticket fee (for vendor copy).
   */
  public function extrasFeeIsDoubleTicketFee(): bool {
    $ticket = $this->getTicketFeePercent();
    $extras = $this->getOperationalExtrasFeePercent();
    if ($ticket <= 0) {
      return FALSE;
    }
    return abs($extras - ($ticket * self::EXTRAS_FEE_TICKET_MULTIPLIER)) < 0.01;
  }

  /**
   * Human-readable percent label (e.g. "1.5%" or "3%").
   */
  public function formatPercentLabel(float $percent): string {
    $label = $percent === (float) (int) $percent
      ? (string) (int) $percent
      : number_format($percent, 1);
    return $label . '%';
  }

  /**
   * Vendor-facing sentence for the Studio Merch & add-ons tab.
   */
  public function buildExtrasStudioFeeCopy(): string {
    $extras_label = $this->formatPercentLabel($this->getOperationalExtrasFeePercent());
    $base = (string) $this->t(
      'Merch and add-ons use the same checkout. The MyEventLane site fee for extras is @fee.',
      ['@fee' => $extras_label]
    );
    if ($this->extrasFeeIsDoubleTicketFee()) {
      $ticket_label = $this->formatPercentLabel($this->getTicketFeePercent());
      return $base . ' ' . (string) $this->t(
        'Extras have a higher site fee than tickets (@ticket) because they include product stock and order handling.',
        ['@ticket' => $ticket_label]
      );
    }
    return $base;
  }

}
