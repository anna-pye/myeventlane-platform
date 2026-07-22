<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Merch & add-ons — progressive disclosure under Tickets (not primary nav).
 */
#[EventStudioSection(
  id: 'extras',
  title: 'Merch & add-ons',
  group: 'Workspace',
  routeName: 'myeventlane_event_studio.workspace_extras',
  section_state: 'active',
  weight: 57,
  icon: 'extras',
  renderTarget: 'form:Drupal\myeventlane_event_studio\Form\EventStudioEventExtrasForm',
  writable: TRUE,
  supports_autosave: FALSE,
  readiness_participant: FALSE,
  empty_state_type: 'none',
  mobile_priority: 155,
  operationalArea: 'commerce_product',
  navigationVisible: FALSE,
)]
final class ExtrasSection extends EventStudioSectionBase {}
