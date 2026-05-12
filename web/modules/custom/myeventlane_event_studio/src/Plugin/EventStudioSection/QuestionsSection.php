<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Checkout questions section metadata.
 */
#[EventStudioSection(
  id: 'questions',
  title: 'Checkout questions',
  group: 'Commerce',
  routeName: 'myeventlane_event_studio.workspace_questions',
  section_state: 'active',
  weight: 110,
  icon: 'questions',
  renderTarget: 'form:Drupal\myeventlane_event_studio\Form\EventCheckoutQuestionsForm',
  writable: TRUE,
  readiness_participant: FALSE,
  empty_state_type: 'none',
  mobile_priority: 70,
  operationalArea: 'commerce',
)]
final class QuestionsSection extends EventStudioSectionBase {}
