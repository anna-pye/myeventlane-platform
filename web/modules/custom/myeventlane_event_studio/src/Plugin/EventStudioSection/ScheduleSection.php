<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Schedule section — date, time, and timezone (shared information form).
 */
#[EventStudioSection(
  id: 'schedule',
  title: 'Schedule',
  group: 'Set up',
  routeName: 'myeventlane_event_studio.workspace_schedule',
  section_state: 'active',
  weight: 20,
  icon: 'schedule',
  renderTarget: 'form:Drupal\myeventlane_event_studio\Form\EventInformationForm',
  writable: TRUE,
  readiness_participant: TRUE,
  empty_state_type: 'none',
  mobile_priority: 15,
  operationalArea: 'event',
)]
final class ScheduleSection extends EventStudioSectionBase {}
