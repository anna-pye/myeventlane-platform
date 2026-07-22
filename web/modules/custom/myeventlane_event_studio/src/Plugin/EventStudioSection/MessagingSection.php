<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Messages section metadata.
 */
#[EventStudioSection(
  id: 'messaging',
  title: 'Messages',
  group: 'Workspace',
  routeName: 'myeventlane_event_studio.workspace_messaging',
  section_state: 'active',
  weight: 70,
  icon: 'promotions',
  routeFragment: 'messages',
  renderTarget: 'form:Drupal\myeventlane_event_studio\Form\MessagingForm',
  writable: TRUE,
  readiness_participant: FALSE,
  empty_state_type: 'none',
  mobile_priority: 60,
  operationalArea: 'event',
)]
final class MessagingSection extends EventStudioSectionBase {}
