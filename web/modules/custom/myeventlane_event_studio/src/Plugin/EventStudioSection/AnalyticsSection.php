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
  group: 'Workspace',
  routeName: 'myeventlane_event_studio.workspace_analytics',
  section_state: 'readonly',
  weight: 100,
  icon: 'analytics',
  renderTarget: 'readonly_summary',
  writable: FALSE,
  readiness_participant: FALSE,
  empty_state_type: 'readonly_empty',
  mobile_priority: 90,
  operationalArea: 'analytics',
)]
final class AnalyticsSection extends EventStudioSectionBase {}
