<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Value;

/**
 * Defines canonical event-commerce purchasable classifications.
 *
 * These constants are metadata contracts only. They do not create products,
 * mutate carts, alter checkout, or infer behavior from Commerce bundles.
 */
final class EventCommercePurchasableType {

  public const TICKET = 'ticket';
  public const ADDON = 'addon';
  public const MERCHANDISE = 'merchandise';
  public const DONATION = 'donation';
  public const UPGRADE = 'upgrade';
  public const BUNDLE_FUTURE = 'bundle_future';

  /**
   * Returns canonical classification definitions.
   *
   * @return array<string, array<string, mixed>>
   */
  public static function definitions(): array {
    return [
      self::TICKET => [
        'label' => 'Ticket',
        'domain' => 'ticket',
        'description' => 'Attendance access purchasable governed by the ticket domain.',
        'attendance_access' => TRUE,
        'fulfilment_required' => FALSE,
      ],
      self::ADDON => [
        'label' => 'Add-on',
        'domain' => 'commerce_product',
        'description' => 'Optional event-linked purchasable that does not mutate ticket access.',
        'attendance_access' => FALSE,
        'fulfilment_required' => FALSE,
      ],
      self::MERCHANDISE => [
        'label' => 'Merchandise',
        'domain' => 'commerce_product',
        'description' => 'Physical or digital product sold alongside an event without becoming a ticket.',
        'attendance_access' => FALSE,
        'fulfilment_required' => TRUE,
      ],
      self::DONATION => [
        'label' => 'Donation',
        'domain' => 'commerce_product',
        'description' => 'Voluntary Commerce purchasable that remains a separate order item boundary.',
        'attendance_access' => FALSE,
        'fulfilment_required' => FALSE,
      ],
      self::UPGRADE => [
        'label' => 'Upgrade',
        'domain' => 'commerce_product',
        'description' => 'Purchasable enhancement linked to an event without replacing ticket lifecycle logic.',
        'attendance_access' => FALSE,
        'fulfilment_required' => FALSE,
      ],
      self::BUNDLE_FUTURE => [
        'label' => 'Future bundle',
        'domain' => 'commerce_product',
        'description' => 'Reserved classification for future governed bundle architecture.',
        'attendance_access' => FALSE,
        'fulfilment_required' => FALSE,
      ],
    ];
  }

  /**
   * Returns TRUE when the supplied classification is canonical.
   */
  public static function isValid(string $classification): bool {
    return array_key_exists($classification, self::definitions());
  }

  /**
   * Returns metadata for a canonical classification.
   *
   * @return array<string, mixed>
   */
  public static function definition(string $classification): array {
    return self::definitions()[$classification] ?? [];
  }

}
