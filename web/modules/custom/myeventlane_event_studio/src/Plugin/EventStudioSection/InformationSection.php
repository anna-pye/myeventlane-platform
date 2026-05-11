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
  section_state: 'active',
  weight: 10,
  icon: 'information',
  renderTarget: 'form:Drupal\myeventlane_event_studio\Form\EventInformationForm',
  writable: TRUE,
  readiness_participant: TRUE,
  empty_state_type: 'none',
  mobile_priority: 10,
  operationalArea: 'event',
)]
final class InformationSection extends EventStudioSectionBase {}
