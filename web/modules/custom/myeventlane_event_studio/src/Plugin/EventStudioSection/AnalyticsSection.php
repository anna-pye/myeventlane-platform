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
  weight: 230,
  icon: 'analytics',
  operationalArea: 'analytics',
  deferred: TRUE,
)]
final class AnalyticsSection extends EventStudioSectionBase {}
