<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Branding section metadata (cover, gallery, and event page style).
 */
#[EventStudioSection(
  id: 'branding',
  title: 'Branding',
  group: 'Workspace',
  routeName: 'myeventlane_event_studio.workspace_branding',
  section_state: 'active',
  weight: 40,
  icon: 'branding',
  routeFragment: 'images',
  renderTarget: 'form:Drupal\myeventlane_event_studio\Form\EventBrandingForm',
  writable: TRUE,
  supports_autosave: FALSE,
  readiness_participant: TRUE,
  empty_state_type: 'none',
  mobile_priority: 20,
  operationalArea: 'event',
)]
final class BrandingSection extends EventStudioSectionBase {}
