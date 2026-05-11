<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Checkout questions section metadata.
 */
#[EventStudioSection(
  id: 'questions',
  title: 'Checkout Questions',
  group: 'Commerce',
  routeName: 'myeventlane_event_studio.workspace_questions',
  weight: 110,
  icon: 'questions',
  operationalArea: 'commerce',
)]
final class QuestionsSection extends EventStudioSectionBase {}
