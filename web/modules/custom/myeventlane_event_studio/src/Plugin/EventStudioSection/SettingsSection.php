<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Settings section metadata.
 */
#[EventStudioSection(
  id: 'settings',
  title: 'Settings',
  group: 'Manage Event',
  routeName: 'myeventlane_event_studio.workspace_settings',
  weight: 50,
  icon: 'settings',
  readinessParticipant: TRUE,
  operationalArea: 'event',
)]
final class SettingsSection extends EventStudioSectionBase {}
