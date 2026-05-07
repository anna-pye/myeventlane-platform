<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Canonical registry of MEL component contracts (IDs, categories, affinity).
 */
final class MelComponentRegistry {

  /**
   * @return array<string, \Drupal\myeventlane_surface\ComponentDefinition>
   *   Definitions keyed by theme hook / machine name.
   */
  public function all(): array {
    $publicCustomerVendor = [
      MelSurfaceId::Public->value,
      MelSurfaceId::Customer->value,
      MelSurfaceId::Vendor->value,
    ];
    $shells = [
      MelSurfaceId::Customer->value,
      MelSurfaceId::Vendor->value,
    ];

    $defs = [];

    $defs['mel_card'] = new ComponentDefinition(
      machineName: 'mel_card',
      category: 'card',
      description: 'Primary card shell — stack header, body, meta, actions, status, empty slots.',
      surfaceAffinity: $publicCustomerVendor,
    );
    foreach ([
      'mel_card_section' => 'Logical section inside a card or dashboard stack.',
      'mel_card_header' => 'Card header cluster (title + optional toolbar).',
      'mel_card_meta' => 'Secondary metadata row(s) inside a card.',
      'mel_card_actions' => 'Primary/secondary actions inside a card footer or body end.',
      'mel_card_status' => 'Inline operational status inside a card.',
      'mel_card_empty' => 'Inline empty placeholder inside a card body.',
    ] as $name => $desc) {
      $defs[$name] = new ComponentDefinition(
        machineName: $name,
        category: 'card',
        description: $desc,
        surfaceAffinity: $publicCustomerVendor,
      );
    }

    foreach ([
      'mel_status_panel' => 'Landmarked panel for persistent screen-level status.',
      'mel_alert' => 'Inline alert / feedback (success, warning, error, info).',
      'mel_empty_state' => 'Canonical empty state (what / why / next / optional CTA).',
      'mel_loading_state' => 'Loading feedback with live region semantics.',
      'mel_success_panel' => 'Success summary panel.',
      'mel_warning_panel' => 'Warning summary panel.',
      'mel_error_panel' => 'Error summary panel.',
    ] as $name => $desc) {
      $defs[$name] = new ComponentDefinition(
        machineName: $name,
        category: 'status',
        description: $desc,
        surfaceAffinity: [
          MelSurfaceId::Public->value,
          MelSurfaceId::Auth->value,
          MelSurfaceId::Customer->value,
          MelSurfaceId::Vendor->value,
        ],
      );
    }

    foreach ([
      'mel_cta_band' => 'Full-width call-to-action band.',
      'mel_inline_cta' => 'Inline contextual CTA cluster.',
      'mel_sticky_cta' => 'Sticky footer/bottom CTA safe region.',
      'mel_next_step_panel' => 'Guided next-step summary panel.',
    ] as $name => $desc) {
      $defs[$name] = new ComponentDefinition(
        machineName: $name,
        category: 'cta',
        description: $desc,
        surfaceAffinity: $publicCustomerVendor,
      );
    }

    foreach ([
      'mel_shell_nav' => 'Landmarked shell navigation list (customer/vendor/auth contracts).',
      'mel_shell_sidebar' => 'Sidebar chrome wrapper for shell navigation + optional aside.',
      'mel_shell_tabs' => 'Horizontal shell tabs for secondary IA.',
      'mel_mobile_shell_nav' => 'Compact mobile shell navigation disclosure region.',
    ] as $name => $desc) {
      $defs[$name] = new ComponentDefinition(
        machineName: $name,
        category: 'navigation',
        description: $desc,
        surfaceAffinity: array_merge([MelSurfaceId::Auth->value], $shells),
      );
    }

    foreach ([
      'mel_onboarding_step' => 'Single onboarding step surface.',
      'mel_progress_panel' => 'Progress / step indicator panel.',
      'mel_checklist_panel' => 'Checklist-style onboarding panel.',
      'mel_guided_next_step' => 'Guided “what’s next” onboarding block.',
    ] as $name => $desc) {
      $defs[$name] = new ComponentDefinition(
        machineName: $name,
        category: 'onboarding',
        description: $desc,
        surfaceAffinity: $publicCustomerVendor,
      );
    }

    $dataSurfaces = [
      MelSurfaceId::Public->value,
      MelSurfaceId::Auth->value,
      MelSurfaceId::Customer->value,
      MelSurfaceId::Vendor->value,
      MelSurfaceId::Staff->value,
    ];
    foreach ([
      'mel_data_table' => 'Governed operational table shell (Views body inside).',
      'mel_table_toolbar' => 'Table toolbar / filter presentation cluster.',
      'mel_table_empty' => 'Inline governed empty slot for table shells.',
      'mel_table_pagination' => 'Pagination presentation for table shells.',
      'mel_table_actions' => 'Row/bulk actions presentation for table shells.',
      'mel_table_status' => 'Selection / sync status row for table shells.',
    ] as $name => $desc) {
      $defs[$name] = new ComponentDefinition(
        machineName: $name,
        category: 'data',
        description: $desc,
        surfaceAffinity: $dataSurfaces,
      );
    }
    foreach ([
      'mel_metric_card' => 'Metric tile with trend / delta / status presentation.',
      'mel_metric_grid' => 'Responsive metric grid layout.',
      'mel_stat_panel' => 'Clustered stat presentation panel.',
      'mel_summary_bar' => 'Horizontal dashboard summary bar.',
    ] as $name => $desc) {
      $defs[$name] = new ComponentDefinition(
        machineName: $name,
        category: 'data',
        description: $desc,
        surfaceAffinity: $dataSurfaces,
      );
    }
    foreach ([
      'mel_analytics_frame' => 'Chart/graph presentation shell (engine-agnostic).',
    ] as $name => $desc) {
      $defs[$name] = new ComponentDefinition(
        machineName: $name,
        category: 'data',
        description: $desc,
        surfaceAffinity: $dataSurfaces,
      );
    }
    foreach ([
      'mel_activity_feed' => 'Activity feed landmark wrapper.',
      'mel_activity_item' => 'Single activity feed row.',
      'mel_timeline' => 'Timeline presentation wrapper.',
      'mel_event_log' => 'Event log presentation wrapper.',
    ] as $name => $desc) {
      $defs[$name] = new ComponentDefinition(
        machineName: $name,
        category: 'data',
        description: $desc,
        surfaceAffinity: $dataSurfaces,
      );
    }
    foreach ([
      'mel_export_panel' => 'Export actions / summaries presentation.',
      'mel_export_notice' => 'Export privacy and warning notices.',
    ] as $name => $desc) {
      $defs[$name] = new ComponentDefinition(
        machineName: $name,
        category: 'data',
        description: $desc,
        surfaceAffinity: $dataSurfaces,
      );
    }

    return $defs;
  }

}
