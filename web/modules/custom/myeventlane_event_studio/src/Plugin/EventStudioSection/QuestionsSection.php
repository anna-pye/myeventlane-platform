<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Guest questions — advanced ticket tooling (not primary Workspace nav).
 */
#[EventStudioSection(
  id: 'questions',
  title: 'Guest questions',
  group: 'Workspace',
  routeName: 'myeventlane_event_studio.workspace_questions',
  section_state: 'active',
  weight: 55,
  icon: 'questions',
  renderTarget: 'form:Drupal\myeventlane_event_studio\Form\EventCheckoutQuestionsForm',
  writable: TRUE,
  readiness_participant: FALSE,
  empty_state_type: 'none',
  mobile_priority: 70,
  operationalArea: 'commerce',
  navigationVisible: FALSE,
)]
final class QuestionsSection extends EventStudioSectionBase {}
