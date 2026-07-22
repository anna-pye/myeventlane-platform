<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Collection / fulfilment — advanced ops (not primary Workspace nav).
 */
#[EventStudioSection(
  id: 'fulfilment',
  title: 'Collection',
  group: 'Workspace',
  routeName: 'myeventlane_event_studio.workspace_fulfilment',
  section_state: 'active',
  weight: 130,
  icon: 'fulfilment',
  renderTarget: 'form:Drupal\myeventlane_event_studio\Form\EventStudioOperationalCapabilityForm',
  writable: TRUE,
  supports_autosave: TRUE,
  readiness_participant: FALSE,
  empty_state_type: 'none',
  mobile_priority: 180,
  operationalArea: 'fulfilment',
  navigationVisible: FALSE,
)]
final class FulfilmentSection extends EventStudioSectionBase {}
