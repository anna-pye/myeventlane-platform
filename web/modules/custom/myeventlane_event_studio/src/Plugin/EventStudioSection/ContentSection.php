<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Content section metadata.
 */
#[EventStudioSection(
  id: 'content',
  title: 'Content',
  group: 'Manage Event',
  routeName: 'myeventlane_event_studio.workspace_content',
  weight: 30,
  icon: 'content',
  readinessParticipant: TRUE,
  operationalArea: 'event',
)]
final class ContentSection extends EventStudioSectionBase {}
