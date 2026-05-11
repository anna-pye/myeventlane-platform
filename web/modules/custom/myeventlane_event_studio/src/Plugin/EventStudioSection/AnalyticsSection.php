<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Analytics section metadata.
 */
#[EventStudioSection(
  id: 'analytics',
  title: 'Analytics',
  group: 'Operations',
  routeName: 'myeventlane_event_studio.workspace_analytics',
  section_state: 'readonly',
  weight: 230,
  icon: 'analytics',
  renderTarget: 'readonly_summary',
  writable: FALSE,
  readiness_participant: FALSE,
  empty_state_type: 'readonly_empty',
  mobile_priority: 110,
  operationalArea: 'analytics',
)]
final class AnalyticsSection extends EventStudioSectionBase {}
