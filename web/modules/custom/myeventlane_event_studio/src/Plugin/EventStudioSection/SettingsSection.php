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
  group: 'Event',
  routeName: 'myeventlane_event_studio.workspace_settings',
  section_state: 'active',
  weight: 50,
  icon: 'settings',
  renderTarget: 'settings_with_readiness',
  writable: TRUE,
  readiness_participant: TRUE,
  empty_state_type: 'none',
  mobile_priority: 50,
  operationalArea: 'event',
)]
final class SettingsSection extends EventStudioSectionBase {}
