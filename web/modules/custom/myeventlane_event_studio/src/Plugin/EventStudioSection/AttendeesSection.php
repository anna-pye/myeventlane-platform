<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Attendees section — VX2 One Attendee Workspace (B7).
 */
#[EventStudioSection(
  id: 'attendees',
  title: 'Attendees',
  group: 'Workspace',
  routeName: 'myeventlane_event_studio.workspace_attendees',
  section_state: 'active',
  weight: 60,
  icon: 'attendees',
  renderTarget: 'attendees_stack',
  writable: FALSE,
  readiness_participant: FALSE,
  empty_state_type: 'default',
  mobile_priority: 50,
  operationalArea: 'operations',
)]
final class AttendeesSection extends EventStudioSectionBase {}
