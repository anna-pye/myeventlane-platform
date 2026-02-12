<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_nudges\Nudge;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\myeventlane_vendor\Entity\Vendor;

/**
 * Nudge encouraging proactive communication to reduce enquiries.
 *
 * Triggered when the vendor's health bucket indicates room for
 * improvement. No metrics, counts, or comparisons are shown.
 */
final class EscalationVolumeNudge implements VendorNudgeInterface {

  use StringTranslationTrait;

  /**
   * Minimum escalation count to form a meaningful signal.
   */
  private const MIN_SAMPLE_SIZE = 3;

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'escalation_volume';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->t('Keeping customers informed');
  }

  /**
   * {@inheritdoc}
   */
  public function shouldApply(Vendor $vendor, array $context): bool {
    if (($context['escalation_count'] ?? 0) < self::MIN_SAMPLE_SIZE) {
      return FALSE;
    }

    $bucket = $context['health_bucket'] ?? 'healthy';
    return in_array($bucket, ['attention', 'risk'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'message' => (string) $this->t('Proactive updates — such as event reminders, venue details, or schedule changes — can help reduce the number of customer enquiries you receive.'),
      'learn_more_url' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getHelpCategory(): ?string {
    return 'Communication';
  }

}
