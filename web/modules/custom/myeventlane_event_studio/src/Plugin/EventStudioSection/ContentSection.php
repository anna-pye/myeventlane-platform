<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Content section — nested under Details (not primary Workspace nav).
 */
#[EventStudioSection(
  id: 'content',
  title: 'Content',
  group: 'Workspace',
  routeName: 'myeventlane_event_studio.workspace_content',
  section_state: 'active',
  weight: 35,
  icon: 'content',
  renderTarget: 'form:Drupal\myeventlane_event_studio\Form\EventContentForm',
  writable: TRUE,
  readiness_participant: TRUE,
  empty_state_type: 'none',
  mobile_priority: 30,
  operationalArea: 'event',
  navigationVisible: FALSE,
)]
final class ContentSection extends EventStudioSectionBase {}
