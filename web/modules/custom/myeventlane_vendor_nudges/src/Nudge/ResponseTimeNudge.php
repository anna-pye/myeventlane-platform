<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_nudges\Nudge;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\myeventlane_vendor\Entity\Vendor;

/**
 * Nudge encouraging timely responses to customer enquiries.
 *
 * Triggered when the vendor's aggregate response pattern suggests
 * there may be room for improvement. No metrics or thresholds are
 * shown to the vendor.
 */
final class ResponseTimeNudge implements VendorNudgeInterface {

  use StringTranslationTrait;

  /**
   * Internal threshold for average first response hours.
   *
   * Not exposed to the vendor. Used solely to determine applicability.
   */
  private const RESPONSE_HOURS_THRESHOLD = 36.0;

  /**
   * Minimum escalation count to form a meaningful signal.
   */
  private const MIN_SAMPLE_SIZE = 3;

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'response_time';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->t('Responding to enquiries');
  }

  /**
   * {@inheritdoc}
   */
  public function shouldApply(Vendor $vendor, array $context): bool {
    if (($context['escalation_count'] ?? 0) < self::MIN_SAMPLE_SIZE) {
      return FALSE;
    }

    $avgHours = $context['avg_first_response_hours'] ?? NULL;
    if ($avgHours === NULL) {
      return FALSE;
    }

    return $avgHours > self::RESPONSE_HOURS_THRESHOLD;
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'message' => (string) $this->t('Responding promptly to customer enquiries can help resolve issues before they escalate. Even a quick acknowledgement goes a long way.'),
      'learn_more_url' => NULL,
    ];
  }

}
