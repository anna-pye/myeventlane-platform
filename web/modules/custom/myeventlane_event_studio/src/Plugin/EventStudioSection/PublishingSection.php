<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Publishing section — visibility and readiness before going live.
 */
#[EventStudioSection(
  id: 'publishing',
  title: 'Publishing',
  group: 'Workspace',
  routeName: 'myeventlane_event_studio.workspace_publishing',
  section_state: 'active',
  weight: 110,
  icon: 'publishing',
  renderTarget: 'publishing_hub',
  writable: TRUE,
  readiness_participant: TRUE,
  empty_state_type: 'none',
  mobile_priority: 45,
  operationalArea: 'event',
)]
final class PublishingSection extends EventStudioSectionBase {}
