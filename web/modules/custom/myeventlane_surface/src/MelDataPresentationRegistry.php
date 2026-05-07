<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Canonical registry of MEL data presentation contracts (presentation only).
 */
final class MelDataPresentationRegistry {

  public const TABLE_ATTENDEES = 'mel.table.attendees';

  public const TABLE_ORDERS = 'mel.table.orders';

  public const TABLE_RSVP = 'mel.table.rsvp';

  public const TABLE_VENDOR_EVENTS = 'mel.table.vendor_events';

  public const TABLE_SUPPORT = 'mel.table.support';

  public const TABLE_MODERATION = 'mel.table.moderation';

  public const METRIC_SALES = 'mel.metric.sales';

  public const METRIC_RSVP = 'mel.metric.rsvp';

  public const METRIC_ATTENDANCE = 'mel.metric.attendance';

  public const METRIC_ONBOARDING = 'mel.metric.onboarding';

  public const METRIC_CONVERSION = 'mel.metric.conversion';

  public const METRIC_DASHBOARD_SUMMARY = 'mel.metric.dashboard_summary';

  public const ANALYTICS_CHART = 'mel.analytics.chart';

  public const ANALYTICS_GRAPH = 'mel.analytics.graph';

  public const ANALYTICS_TREND = 'mel.analytics.trend';

  public const ANALYTICS_OPERATIONAL_SUMMARY = 'mel.analytics.operational_summary';

  public const ANALYTICS_INSIGHT = 'mel.analytics.insight';

  public const FEED_ATTENDEE_ACTIVITY = 'mel.feed.attendee_activity';

  public const FEED_ORDER_ACTIVITY = 'mel.feed.order_activity';

  public const FEED_VENDOR_ACTIVITY = 'mel.feed.vendor_activity';

  public const FEED_SUPPORT_ACTIVITY = 'mel.feed.support_activity';

  public const EXPORT_CSV_PANEL = 'mel.export.csv_panel';

  public const EXPORT_REPORT_UI = 'mel.export.report_ui';

  public const EXPORT_ATTENDEE_GUIDANCE = 'mel.export.attendee_guidance';

  /**
   * @return array<string, \Drupal\myeventlane_surface\DataPresentationDefinition>
   */
  public function all(): array {
    $allSurfaces = [
      MelSurfaceId::Public->value,
      MelSurfaceId::Auth->value,
      MelSurfaceId::Customer->value,
      MelSurfaceId::Vendor->value,
      MelSurfaceId::Staff->value,
    ];
    $shells = [
      MelSurfaceId::Customer->value,
      MelSurfaceId::Vendor->value,
      MelSurfaceId::Staff->value,
    ];
    $vendorStaff = [MelSurfaceId::Vendor->value, MelSurfaceId::Staff->value];
    $vendorOnly = [MelSurfaceId::Vendor->value];

    $defs = [];

    foreach ([
      self::TABLE_ATTENDEES => ['Attendee rosters and check-in tables (Views wraps body).', $vendorStaff],
      self::TABLE_ORDERS => ['Order summaries and commerce lists (server access unchanged).', $shells],
      self::TABLE_RSVP => ['RSVP operational lists.', array_merge([MelSurfaceId::Customer->value], $vendorStaff)],
      self::TABLE_VENDOR_EVENTS => ['Vendor event management lists.', $vendorOnly],
      self::TABLE_SUPPORT => ['Support ticket / case lists.', $vendorStaff],
      self::TABLE_MODERATION => ['Staff moderation queues.', [MelSurfaceId::Staff->value]],
    ] as $id => [$desc, $affinity]) {
      $defs[$id] = new DataPresentationDefinition($id, 'table', $desc, $affinity);
    }

    foreach ([
      self::METRIC_SALES => ['Sales and revenue headline metrics.', $vendorStaff],
      self::METRIC_RSVP => ['RSVP counts and funnel metrics.', $shells],
      self::METRIC_ATTENDANCE => ['Attendance and check-in metrics.', $vendorStaff],
      self::METRIC_ONBOARDING => ['Onboarding progress indicators.', $allSurfaces],
      self::METRIC_CONVERSION => ['Conversion summaries (no raw PII).', $vendorStaff],
      self::METRIC_DASHBOARD_SUMMARY => ['Dashboard summary tiles.', $shells],
    ] as $id => [$desc, $affinity]) {
      $defs[$id] = new DataPresentationDefinition($id, 'metric', $desc, $affinity);
    }

    foreach ([
      self::ANALYTICS_CHART => ['Chart.js / Recharts presentation shell.', $vendorStaff],
      self::ANALYTICS_GRAPH => ['Graph presentation shell.', $vendorStaff],
      self::ANALYTICS_TREND => ['Trend panels.', $vendorStaff],
      self::ANALYTICS_OPERATIONAL_SUMMARY => ['Operational narrative summaries.', $shells],
      self::ANALYTICS_INSIGHT => ['Dashboard insight callouts.', $shells],
    ] as $id => [$desc, $affinity]) {
      $defs[$id] = new DataPresentationDefinition($id, 'analytics', $desc, $affinity);
    }

    foreach ([
      self::FEED_ATTENDEE_ACTIVITY => ['Attendee-visible activity narratives.', [MelSurfaceId::Customer->value]],
      self::FEED_ORDER_ACTIVITY => ['Order lifecycle activity.', $shells],
      self::FEED_VENDOR_ACTIVITY => ['Vendor operational activity.', $vendorOnly],
      self::FEED_SUPPORT_ACTIVITY => ['Support activity timelines.', $vendorStaff],
    ] as $id => [$desc, $affinity]) {
      $defs[$id] = new DataPresentationDefinition($id, 'activity', $desc, $affinity);
    }

    foreach ([
      self::EXPORT_CSV_PANEL => ['CSV export affordances (permission-gated).', $vendorStaff],
      self::EXPORT_REPORT_UI => ['Report export UI chrome.', $vendorStaff],
      self::EXPORT_ATTENDEE_GUIDANCE => ['Attendee export privacy guidance.', $vendorStaff],
    ] as $id => [$desc, $affinity]) {
      $defs[$id] = new DataPresentationDefinition($id, 'export', $desc, $affinity);
    }

    return $defs;
  }

  public function get(string $machineName): ?DataPresentationDefinition {
    return $this->all()[$machineName] ?? NULL;
  }

}
