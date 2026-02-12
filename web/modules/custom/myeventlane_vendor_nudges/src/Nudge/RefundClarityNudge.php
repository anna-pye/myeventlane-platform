<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_nudges\Nudge;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\myeventlane_vendor\Entity\Vendor;

/**
 * Nudge suggesting clear refund and cancellation terms.
 *
 * Triggered when the vendor has order-linked escalations, suggesting
 * that clearer terms might help prevent misunderstandings. No specific
 * refund data or counts are shown.
 */
final class RefundClarityNudge implements VendorNudgeInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'refund_clarity';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->t('Refund and cancellation terms');
  }

  /**
   * {@inheritdoc}
   */
  public function shouldApply(Vendor $vendor, array $context): bool {
    if (($context['escalation_count'] ?? 0) < 1) {
      return FALSE;
    }

    return !empty($context['has_refund_correlations']);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'message' => (string) $this->t('Having clear refund and cancellation terms on your event pages helps set expectations and reduces enquiries. Consider reviewing your terms to ensure they are easy to find and understand.'),
      'learn_more_url' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getHelpCategory(): ?string {
    return 'Refunds';
  }

}
