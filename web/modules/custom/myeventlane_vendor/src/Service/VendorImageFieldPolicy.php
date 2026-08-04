<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Canonical organiser image fields and legacy fallbacks.
 */
final class VendorImageFieldPolicy {

  public const PUBLIC_LOGO_FIELDS = [
    'field_vendor_logo',
    'field_logo_image',
  ];

  public const EMAIL_LOGO_FIELDS = [
    'field_msg_logo',
    'field_vendor_logo',
    'field_logo_image',
  ];

  /**
   * Returns the canonical public logo field, with legacy schema fallback.
   */
  public static function canonicalPublicLogoField(ContentEntityInterface $vendor): string {
    foreach (self::PUBLIC_LOGO_FIELDS as $fieldName) {
      if ($vendor->hasField($fieldName)) {
        return $fieldName;
      }
    }

    return '';
  }

  /**
   * Returns the optional email override field, then public schema fallback.
   */
  public static function emailOverrideField(ContentEntityInterface $vendor): string {
    foreach (self::EMAIL_LOGO_FIELDS as $fieldName) {
      if ($vendor->hasField($fieldName)) {
        return $fieldName;
      }
    }

    return '';
  }

}
