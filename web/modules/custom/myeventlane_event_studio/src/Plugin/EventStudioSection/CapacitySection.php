<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Capacity section metadata.
 */
#[EventStudioSection(
  id: 'capacity',
  title: 'Capacity',
  group: 'Commerce',
  routeName: 'myeventlane_event_studio.workspace_capacity',
  section_state: 'active',
  weight: 120,
  icon: 'capacity',
  renderTarget: 'capacity_summary',
  writable: FALSE,
  readiness_participant: TRUE,
  empty_state_type: 'none',
  mobile_priority: 80,
  operationalArea: 'ticket',
)]
final class CapacitySection extends EventStudioSectionBase {}
