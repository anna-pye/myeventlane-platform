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
  group: 'Operations',
  routeName: 'myeventlane_event_studio.workspace_attendees',
  weight: 200,
  icon: 'attendees',
  operationalArea: 'operations',
  deferred: TRUE,
)]
final class AttendeesSection extends EventStudioSectionBase {}
