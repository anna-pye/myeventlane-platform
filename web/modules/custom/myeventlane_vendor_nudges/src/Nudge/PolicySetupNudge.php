<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_nudges\Nudge;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\myeventlane_vendor\Entity\Vendor;

/**
 * Nudge encouraging vendors to complete their profile and policies.
 *
 * Checks for the presence of key fields on the vendor entity that
 * indicate profile and policy completeness. No metrics or comparisons
 * are shown — only a friendly suggestion to fill in missing details.
 */
final class PolicySetupNudge implements VendorNudgeInterface {

  use StringTranslationTrait;

  /**
   * Vendor fields to check for completeness.
   *
   * These are common profile/policy fields. If any exist on the entity
   * and are empty, the nudge applies.
   */
  private const PROFILE_FIELDS = [
    'field_refund_policy',
    'field_contact_email',
    'field_description',
  ];

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'policy_setup';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->t('Completing your profile');
  }

  /**
   * {@inheritdoc}
   */
  public function shouldApply(Vendor $vendor, array $context): bool {
    foreach (self::PROFILE_FIELDS as $fieldName) {
      if ($vendor->hasField($fieldName) && $vendor->get($fieldName)->isEmpty()) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'message' => (string) $this->t('A complete organiser profile — including contact details and event policies — helps build trust with your attendees and can reduce the number of support enquiries.'),
      'learn_more_url' => NULL,
    ];
  }

}
