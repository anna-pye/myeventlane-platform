<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Adaptive guidance profiles — contextual labels only (no duplicated onboarding logic).
 */
final class MelAdaptiveGuidanceHelper {

  /**
   * @param array<string, bool|int|string> $merged
   * @param array<string, mixed> $experienceAttachments
   *
   * @return array<string, string>
   *   machine keys for downstream theme/JS (not end-user copy).
   */
  public function resolveProfiles(MelWorkflowContext $context, array $merged, array $experienceAttachments): array {
    $profiles = [];

    if ($context->surface === MelSurfaceId::Vendor) {
      $profiles['vendor'] = !empty($merged['vendor.onboarding.completed'])
        ? 'vendor_experienced'
        : 'vendor_beginner';
    }

    if ($context->surface === MelSurfaceId::Customer) {
      $has_orders = !empty($merged['customer.flags.has_orders']);
      $profiles['customer'] = $has_orders
        ? 'customer_repeat_attendee'
        : 'customer_first_time_attendee';
    }

    $primary = $experienceAttachments['primary_experience_id'] ?? NULL;
    if (is_string($primary) && $primary !== '') {
      $profiles['primary_experience_id'] = $primary;
    }

    return $profiles;
  }

}
