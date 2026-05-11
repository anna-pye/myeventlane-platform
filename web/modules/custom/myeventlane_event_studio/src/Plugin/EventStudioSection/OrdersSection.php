<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Orders section metadata.
 */
#[EventStudioSection(
  id: 'orders',
  title: 'Orders',
  group: 'Operations',
  routeName: 'myeventlane_event_studio.workspace_orders',
  section_state: 'readonly',
  weight: 220,
  icon: 'orders',
  renderTarget: 'readonly_summary',
  writable: FALSE,
  readiness_participant: FALSE,
  empty_state_type: 'readonly_empty',
  mobile_priority: 100,
  operationalArea: 'commerce',
)]
final class OrdersSection extends EventStudioSectionBase {}
