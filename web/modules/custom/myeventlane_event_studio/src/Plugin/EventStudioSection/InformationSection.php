<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Event information section metadata.
 */
#[EventStudioSection(
  id: 'information',
  title: 'Event Information',
  group: 'Manage Event',
  routeName: 'myeventlane_event_studio.workspace_information',
  weight: 10,
  icon: 'information',
  readinessParticipant: TRUE,
  operationalArea: 'event',
)]
final class InformationSection extends EventStudioSectionBase {}
