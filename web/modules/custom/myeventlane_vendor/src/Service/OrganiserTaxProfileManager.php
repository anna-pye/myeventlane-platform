<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Resolves organiser tax declarations and synchronises Commerce tax state.
 */
final class OrganiserTaxProfileManager {

  public const STATUS_UNKNOWN = 'unknown';
  public const STATUS_NOT_REGISTERED = 'not_registered';
  public const STATUS_REGISTERED = 'registered';

  /**
   * Returns the declared GST registration status.
   */
  public function getGstStatus(FieldableEntityInterface $vendor): string {
    if (!$vendor->hasField('field_gst_registration_status') || $vendor->get('field_gst_registration_status')->isEmpty()) {
      return self::STATUS_UNKNOWN;
    }

    $status = (string) $vendor->get('field_gst_registration_status')->value;
    return in_array($status, [self::STATUS_NOT_REGISTERED, self::STATUS_REGISTERED], TRUE)
      ? $status
      : self::STATUS_UNKNOWN;
  }

  /**
   * Whether the organiser has completed the minimum supplier declaration.
   */
  public function isDeclared(FieldableEntityInterface $vendor): bool {
    $entityType = $vendor->hasField('field_tax_entity_type')
      ? trim((string) $vendor->get('field_tax_entity_type')->value)
      : '';
    $declaredAt = $vendor->hasField('field_tax_declaration_at')
      ? (int) $vendor->get('field_tax_declaration_at')->value
      : 0;

    return $entityType !== ''
      && $this->getGstStatus($vendor) !== self::STATUS_UNKNOWN
      && $declaredAt > 0;
  }

  /**
   * Synchronises the vendor declaration to Commerce Tax registration state.
   */
  public function syncStore(FieldableEntityInterface $vendor, StoreInterface $store): bool {
    $registrations = $this->getGstStatus($vendor) === self::STATUS_REGISTERED ? ['AU'] : [];
    $current = array_column($store->get('tax_registrations')->getValue(), 'value');
    $changed = $current !== $registrations;

    if ($changed) {
      $store->set('tax_registrations', $registrations);
    }
    if ($store->hasField('prices_include_tax') && !(bool) $store->get('prices_include_tax')->value) {
      // Event prices are entered as the customer-facing amount. This flag is
      // harmless for unregistered stores because no tax registration matches.
      $store->set('prices_include_tax', TRUE);
      $changed = TRUE;
    }

    return $changed;
  }

  /**
   * Validates an Australian Business Number checksum.
   */
  public function isValidAbn(string $abn): bool {
    $digits = preg_replace('/\D+/', '', $abn) ?? '';
    if (strlen($digits) !== 11) {
      return FALSE;
    }

    $weights = [10, 1, 3, 5, 7, 9, 11, 13, 15, 17, 19];
    $sum = ((int) $digits[0] - 1) * $weights[0];
    for ($index = 1; $index < 11; $index++) {
      $sum += (int) $digits[$index] * $weights[$index];
    }

    return $sum % 89 === 0;
  }

}
