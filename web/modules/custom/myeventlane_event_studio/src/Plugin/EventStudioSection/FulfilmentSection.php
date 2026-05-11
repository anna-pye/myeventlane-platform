<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Fulfilment section metadata.
 */
#[EventStudioSection(
  id: 'fulfilment',
  title: 'Fulfilment',
  group: 'Operations',
  routeName: 'myeventlane_event_studio.workspace_fulfilment',
  section_state: 'deferred',
  weight: 210,
  icon: 'fulfilment',
  renderTarget: 'deferred_empty',
  writable: FALSE,
  readiness_participant: FALSE,
  empty_state_type: 'deferred',
  mobile_priority: 180,
  operationalArea: 'fulfilment',
)]
final class FulfilmentSection extends EventStudioSectionBase {}
