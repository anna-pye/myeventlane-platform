<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Messaging section metadata (formerly "promotions").
 */
#[EventStudioSection(
  id: 'messaging',
  title: 'Visibility & updates',
  group: 'Manage Event',
  routeName: 'myeventlane_event_studio.workspace_messaging',
  section_state: 'active',
  weight: 40,
  icon: 'promotions',
  renderTarget: 'form:Drupal\myeventlane_event_studio\Form\MessagingForm',
  writable: TRUE,
  readiness_participant: FALSE,
  empty_state_type: 'none',
  mobile_priority: 40,
  operationalArea: 'event',
)]
final class MessagingSection extends EventStudioSectionBase {}
