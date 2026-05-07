<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Canonical registry of MEL interaction contracts (IDs, categories, affinity).
 */
final class MelInteractionRegistry {

  /**
   * @return array<string, \Drupal\myeventlane_surface\InteractionDefinition>
   */
  public function all(): array {
    $publicCustomerVendor = [
      MelSurfaceId::Public->value,
      MelSurfaceId::Customer->value,
      MelSurfaceId::Vendor->value,
    ];
    $shells = [
      MelSurfaceId::Auth->value,
      MelSurfaceId::Customer->value,
      MelSurfaceId::Vendor->value,
    ];
    $commerceCustomer = array_merge($publicCustomerVendor, [MelSurfaceId::Staff->value]);

    $defs = [];

    foreach ([
      'modal_rsvp' => ['RSVP modal — ticket selection and confirmation steps.', $publicCustomerVendor, ['checkout_adjacent']],
      'modal_ticket' => ['Ticket detail / selection modal.', $publicCustomerVendor, []],
      'modal_onboarding' => ['Onboarding education modal (non-blocking where possible).', $shells, ['progressive']],
      'modal_confirmation' => ['Destructive or irreversible action confirmation.', $commerceCustomer, ['trust_sensitive']],
      'modal_support' => ['Support / help contact modal.', $commerceCustomer, ['checkout_safe']],
    ] as $name => [$desc, $affinity, $tags]) {
      $defs[$name] = new InteractionDefinition(
        machineName: $name,
        category: 'modal',
        description: $desc,
        surfaceAffinity: $affinity,
        tags: $tags,
      );
    }

    foreach ([
      'drawer_mobile_nav' => ['Mobile shell navigation drawer.', [MelSurfaceId::Customer->value, MelSurfaceId::Vendor->value], ['touch_first']],
      'drawer_attendee_details' => ['Attendee row detail drawer (vendor privacy scoped server-side).', [MelSurfaceId::Vendor->value], ['vendor_only_ui']],
      'drawer_analytics_detail' => ['Analytics drill-down drawer.', [MelSurfaceId::Vendor->value, MelSurfaceId::Staff->value], []],
      'drawer_vendor_quick_actions' => ['Compact operational quick actions.', [MelSurfaceId::Vendor->value], ['dense']],
    ] as $name => [$desc, $affinity, $tags]) {
      $defs[$name] = new InteractionDefinition(
        machineName: $name,
        category: 'drawer',
        description: $desc,
        surfaceAffinity: $affinity,
        tags: $tags,
      );
    }

    foreach ([
      'async_loading' => ['Generic loading — spinner or skeleton region.', $commerceCustomer, []],
      'async_saving' => ['Persist-in-flight — disable secondary actions.', $commerceCustomer, []],
      'async_success' => ['Completed operation — polite announcement.', $commerceCustomer, ['announce']],
      'async_retry' => ['Retry affordance after recoverable failure.', $commerceCustomer, []],
      'async_queued' => ['Background / queued work acknowledged.', $commerceCustomer, []],
      'async_processing' => ['Long-running processing — sustained busy state.', $commerceCustomer, []],
    ] as $name => [$desc, $affinity, $tags]) {
      $defs[$name] = new InteractionDefinition(
        machineName: $name,
        category: 'async',
        description: $desc,
        surfaceAffinity: $affinity,
        tags: $tags,
      );
    }

    foreach ([
      'notification_toast' => ['Transient toast stack (polite by default).', $commerceCustomer, ['stacking']],
      'notification_inline' => ['Inline persistent notice within a card or form region.', $commerceCustomer, []],
      'notification_warning_notice' => ['Warning notice — emphasised but not blocking.', $commerceCustomer, []],
      'notification_confirmation_notice' => ['Pre-submit confirmation copy region.', array_merge($publicCustomerVendor, [MelSurfaceId::Auth->value]), ['trust_sensitive']],
      'notification_workflow_notice' => ['Workflow / onboarding contextual notice.', $shells, []],
    ] as $name => [$desc, $affinity, $tags]) {
      $defs[$name] = new InteractionDefinition(
        machineName: $name,
        category: 'notification',
        description: $desc,
        surfaceAffinity: $affinity,
        tags: $tags,
      );
    }

    foreach ([
      'disclosure_advanced_settings' => ['Advanced settings progressive disclosure.', [MelSurfaceId::Vendor->value, MelSurfaceId::Staff->value], []],
      'disclosure_optional_ticket' => ['Optional ticket fields disclosure.', $publicCustomerVendor, []],
      'disclosure_vendor_only' => ['Vendor-only controls disclosure (permissions enforced server-side).', [MelSurfaceId::Vendor->value], ['vendor_only_ui']],
      'disclosure_analytics_drilldown' => ['Analytics detail disclosure.', [MelSurfaceId::Vendor->value, MelSurfaceId::Staff->value], []],
      'disclosure_onboarding_detail' => ['Onboarding supplementary detail sections.', $shells, ['progressive']],
    ] as $name => [$desc, $affinity, $tags]) {
      $defs[$name] = new InteractionDefinition(
        machineName: $name,
        category: 'disclosure',
        description: $desc,
        surfaceAffinity: $affinity,
        tags: $tags,
      );
    }

    return $defs;
  }

  public function get(string $machineName): ?InteractionDefinition {
    return $this->all()[$machineName] ?? NULL;
  }

}
