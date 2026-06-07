<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications;

use Drupal\myeventlane_notifications\Entity\MelNotification;

/**
 * Shared classification for in-app notifications and email templates.
 */
final class NotificationTaxonomy {

  /**
   * Maps legacy mel_notification.type (+ optional action_context) to context/domain.
   *
   * @return array{context: string, domain: string}
   */
  public static function fromLegacyType(string $type, string $actionContext = ''): array {
    $actionContext = trim($actionContext);

    if ($actionContext !== '') {
      $fromAction = self::fromActionContext($actionContext);
      if ($fromAction !== NULL) {
        return $fromAction;
      }
    }

    return match ($type) {
      MelNotification::TYPE_TICKET => [
        'context' => NotificationContext::PERSONAL,
        'domain' => NotificationDomain::TICKETS,
      ],
      MelNotification::TYPE_REMINDER => [
        'context' => NotificationContext::PERSONAL,
        'domain' => NotificationDomain::REMINDERS,
      ],
      MelNotification::TYPE_EVENT => [
        'context' => NotificationContext::PERSONAL,
        'domain' => NotificationDomain::EVENT_UPDATES,
      ],
      MelNotification::TYPE_PROMO => [
        'context' => NotificationContext::PLATFORM,
        'domain' => NotificationDomain::PLATFORM,
      ],
      MelNotification::TYPE_SYSTEM => [
        'context' => NotificationContext::PLATFORM,
        'domain' => NotificationDomain::PLATFORM,
      ],
      default => [
        'context' => NotificationContext::PLATFORM,
        'domain' => NotificationDomain::PLATFORM,
      ],
    };
  }

  /**
   * @return array{context: string, domain: string}|null
   */
  public static function fromActionContext(string $actionContext): ?array {
    return match ($actionContext) {
      'checkout_ticket_confirm' => [
        'context' => NotificationContext::PERSONAL,
        'domain' => NotificationDomain::TICKETS,
      ],
      'rsvp_attendee_confirm' => [
        'context' => NotificationContext::PERSONAL,
        'domain' => NotificationDomain::EVENT_UPDATES,
      ],
      'event_published_follower' => [
        'context' => NotificationContext::PERSONAL,
        'domain' => NotificationDomain::EVENT_UPDATES,
      ],
      'rsvp_vendor_alert' => [
        'context' => NotificationContext::BUSINESS,
        'domain' => NotificationDomain::RSVPS,
      ],
      'vendor_sale' => [
        'context' => NotificationContext::BUSINESS,
        'domain' => NotificationDomain::SALES,
      ],
      'refund_requested' => [
        'context' => NotificationContext::BUSINESS,
        'domain' => NotificationDomain::REFUNDS,
      ],
      'refund_approved' => [
        'context' => NotificationContext::BUSINESS,
        'domain' => NotificationDomain::REFUNDS,
      ],
      'refund_rejected' => [
        'context' => NotificationContext::BUSINESS,
        'domain' => NotificationDomain::REFUNDS,
      ],
      'refund_completed' => [
        'context' => NotificationContext::BUSINESS,
        'domain' => NotificationDomain::REFUNDS,
      ],
      'boost_purchased' => [
        'context' => NotificationContext::BUSINESS,
        'domain' => NotificationDomain::BOOSTS,
      ],
      'follower_gained' => [
        'context' => NotificationContext::BUSINESS,
        'domain' => NotificationDomain::FOLLOWERS,
      ],
      'event_approved' => [
        'context' => NotificationContext::BUSINESS,
        'domain' => NotificationDomain::EVENT_UPDATES,
      ],
      default => NULL,
    };
  }

  /**
   * Maps myeventlane_messaging template IDs to context/domain.
   *
   * @return array{context: string, domain: string}
   */
  public static function fromEmailTemplate(string $templateId): array {
    return match ($templateId) {
      'order_confirmation',
      'order_receipt',
      'order_invoice',
      'assign_tickets_buyer',
      'ticket_tier_waitlist_offer' => [
        'context' => NotificationContext::PERSONAL,
        'domain' => NotificationDomain::TICKETS,
      ],
      'cart_abandoned' => [
        'context' => NotificationContext::PERSONAL,
        'domain' => NotificationDomain::ORDERS,
      ],
      'event_reminder',
      'event_reminder_24h',
      'event_reminder_7d' => [
        'context' => NotificationContext::PERSONAL,
        'domain' => NotificationDomain::REMINDERS,
      ],
      'rsvp_confirmation' => [
        'context' => NotificationContext::PERSONAL,
        'domain' => NotificationDomain::RSVPS,
      ],
      'rsvp_vendor_copy' => [
        'context' => NotificationContext::BUSINESS,
        'domain' => NotificationDomain::RSVPS,
      ],
      'vendor_event_update',
      'vendor_event_important_change',
      'vendor_event_cancellation' => [
        'context' => NotificationContext::BUSINESS,
        'domain' => NotificationDomain::EVENT_UPDATES,
      ],
      'boost_confirmation',
      'boost_reminder' => [
        'context' => NotificationContext::BUSINESS,
        'domain' => NotificationDomain::BOOSTS,
      ],
      'refund_requested_vendor',
      'refund_approved_vendor',
      'refund_rejected_vendor',
      'refund_completed_vendor',
      'refund_failed_vendor' => [
        'context' => NotificationContext::BUSINESS,
        'domain' => NotificationDomain::REFUNDS,
      ],
      'refund_requested_buyer',
      'refund_approved_buyer',
      'refund_rejected_buyer',
      'refund_completed_buyer',
      'refund_failed_buyer' => [
        'context' => NotificationContext::PERSONAL,
        'domain' => NotificationDomain::ORDERS,
      ],
      'refund_completed_admin',
      'refund_failed_admin' => [
        'context' => NotificationContext::PLATFORM,
        'domain' => NotificationDomain::PLATFORM,
      ],
      default => [
        'context' => NotificationContext::PLATFORM,
        'domain' => NotificationDomain::PLATFORM,
      ],
    };
  }

  /**
   * Preference category key for a context/domain pair.
   */
  public static function preferenceKey(string $context, string $domain): string {
    if ($context === NotificationContext::PLATFORM) {
      return NotificationPreferenceService::PLATFORM_SECURITY;
    }
    if ($context === NotificationContext::BUSINESS) {
      return match ($domain) {
        NotificationDomain::SALES => NotificationPreferenceService::BUSINESS_SALES,
        NotificationDomain::REFUNDS => NotificationPreferenceService::BUSINESS_REFUNDS,
        NotificationDomain::RSVPS => NotificationPreferenceService::BUSINESS_RSVPS,
        NotificationDomain::FOLLOWERS => NotificationPreferenceService::BUSINESS_FOLLOWERS,
        NotificationDomain::EVENT_UPDATES => NotificationPreferenceService::BUSINESS_EVENT_UPDATES,
        NotificationDomain::BOOSTS => NotificationPreferenceService::BUSINESS_BOOSTS,
        default => NotificationPreferenceService::BUSINESS_SALES,
      };
    }
    return match ($domain) {
      NotificationDomain::TICKETS => NotificationPreferenceService::PERSONAL_TICKET_PURCHASES,
      NotificationDomain::ORDERS => NotificationPreferenceService::PERSONAL_ORDER_UPDATES,
      NotificationDomain::REMINDERS => NotificationPreferenceService::PERSONAL_EVENT_REMINDERS,
      default => NotificationPreferenceService::PERSONAL_ORDER_UPDATES,
    };
  }

  /**
   * Migrates legacy preference JSON keys to the new structure.
   *
   * @param array<string, mixed> $decoded
   *
   * @return array<string, array{enabled: bool, surface: string}>
   */
  public static function migrateLegacyPreferences(array $decoded): array {
    $map = [
      'tickets' => NotificationPreferenceService::PERSONAL_TICKET_PURCHASES,
      'events' => NotificationPreferenceService::BUSINESS_EVENT_UPDATES,
      'reminders' => NotificationPreferenceService::PERSONAL_EVENT_REMINDERS,
      'platform' => NotificationPreferenceService::PLATFORM_SYSTEM,
      'promo' => NotificationPreferenceService::PLATFORM_SYSTEM,
    ];
    $out = [];
    foreach ($map as $legacy => $modern) {
      if (!isset($decoded[$legacy]) || !is_array($decoded[$legacy])) {
        continue;
      }
      $entry = $decoded[$legacy];
      if (!isset($out[$modern])) {
        $out[$modern] = [
          'enabled' => (bool) ($entry['enabled'] ?? TRUE),
          'surface' => (string) ($entry['surface'] ?? NotificationSurface::BELL_INBOX),
        ];
        continue;
      }
      $out[$modern]['enabled'] = $out[$modern]['enabled'] || (bool) ($entry['enabled'] ?? TRUE);
    }
    return $out;
  }

}
