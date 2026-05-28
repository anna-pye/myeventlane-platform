<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Merchandise section metadata.
 */
#[EventStudioSection(
  id: 'merchandise',
  title: 'Merchandise',
  group: 'Commerce',
  routeName: 'myeventlane_event_studio.workspace_merchandise',
  section_state: 'active',
  weight: 130,
  icon: 'merchandise',
  renderTarget: 'form:Drupal\myeventlane_event_studio\Form\EventStudioProductisationForm',
  writable: TRUE,
  supports_autosave: TRUE,
  readiness_participant: FALSE,
  empty_state_type: 'none',
  mobile_priority: 160,
  operationalArea: 'commerce_product',
  navigationVisible: FALSE,
)]
final class MerchandiseSection extends EventStudioSectionBase {}
