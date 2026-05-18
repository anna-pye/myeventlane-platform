<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Unified Event Extras authoring section.
 */
#[EventStudioSection(
  id: 'extras',
  title: 'Extras',
  group: 'Commerce',
  routeName: 'myeventlane_event_studio.workspace_extras',
  section_state: 'active',
  weight: 125,
  icon: 'extras',
  renderTarget: 'form:Drupal\myeventlane_event_studio\Form\EventStudioEventExtrasForm',
  writable: TRUE,
  supports_autosave: FALSE,
  readiness_participant: FALSE,
  empty_state_type: 'none',
  mobile_priority: 155,
  operationalArea: 'commerce_product',
)]
final class ExtrasSection extends EventStudioSectionBase {}
