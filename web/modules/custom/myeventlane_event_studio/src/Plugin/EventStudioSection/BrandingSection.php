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
  group: 'Event',
  routeName: 'myeventlane_event_studio.workspace_branding',
  section_state: 'active',
  weight: 20,
  icon: 'branding',
  renderTarget: 'form:Drupal\myeventlane_event_studio\Form\EventBrandingForm',
  writable: TRUE,
  readiness_participant: TRUE,
  empty_state_type: 'none',
  mobile_priority: 20,
  operationalArea: 'event',
)]
final class BrandingSection extends EventStudioSectionBase {}
