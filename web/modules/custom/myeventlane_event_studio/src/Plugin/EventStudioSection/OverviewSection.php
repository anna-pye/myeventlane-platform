<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Overview section metadata.
 */
#[EventStudioSection(
  id: 'overview',
  title: 'Overview',
  group: 'Manage Event',
  routeName: 'myeventlane_event_studio.workspace',
  weight: 0,
  icon: 'overview',
  readinessParticipant: TRUE,
  operationalArea: 'event',
)]
final class OverviewSection extends EventStudioSectionBase {}
