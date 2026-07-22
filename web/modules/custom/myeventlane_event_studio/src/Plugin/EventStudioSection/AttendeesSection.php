<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Attendees section metadata.
 */
#[EventStudioSection(
  id: 'attendees',
  title: 'Attendees',
  group: 'Workspace',
  routeName: 'myeventlane_event_studio.workspace_attendees',
  section_state: 'readonly',
  weight: 60,
  icon: 'attendees',
  renderTarget: 'readonly_summary',
  writable: FALSE,
  readiness_participant: FALSE,
  empty_state_type: 'readonly_empty',
  mobile_priority: 50,
  operationalArea: 'operations',
)]
final class AttendeesSection extends EventStudioSectionBase {}
