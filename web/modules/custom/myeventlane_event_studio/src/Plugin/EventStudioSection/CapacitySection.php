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
  weight: 120,
  icon: 'capacity',
  readinessParticipant: TRUE,
  operationalArea: 'ticket',
  deferred: TRUE,
)]
final class CapacitySection extends EventStudioSectionBase {}
