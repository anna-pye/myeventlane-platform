<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Marketing section — share tools and Boost entry.
 */
#[EventStudioSection(
  id: 'marketing',
  title: 'Marketing',
  group: 'Run the event',
  routeName: 'myeventlane_event_studio.workspace_marketing',
  section_state: 'active',
  weight: 30,
  icon: 'marketing',
  renderTarget: 'marketing_hub',
  writable: FALSE,
  readiness_participant: FALSE,
  empty_state_type: 'none',
  mobile_priority: 70,
  operationalArea: 'marketing',
)]
final class MarketingSection extends EventStudioSectionBase {}
