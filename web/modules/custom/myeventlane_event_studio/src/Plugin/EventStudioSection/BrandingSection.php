<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Branding section metadata.
 */
#[EventStudioSection(
  id: 'branding',
  title: 'Branding',
  group: 'Manage Event',
  routeName: 'myeventlane_event_studio.workspace_branding',
  weight: 20,
  icon: 'branding',
  readinessParticipant: TRUE,
  operationalArea: 'event',
)]
final class BrandingSection extends EventStudioSectionBase {}
