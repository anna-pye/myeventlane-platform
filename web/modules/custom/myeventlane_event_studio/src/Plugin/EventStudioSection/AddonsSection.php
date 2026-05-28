<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Add-ons section metadata.
 */
#[EventStudioSection(
  id: 'addons',
  title: 'Add-ons',
  group: 'Commerce',
  routeName: 'myeventlane_event_studio.workspace_addons',
  section_state: 'deferred',
  weight: 140,
  icon: 'addons',
  routeFragment: 'addons',
  renderTarget: 'deferred_empty',
  writable: FALSE,
  readiness_participant: FALSE,
  empty_state_type: 'deferred',
  mobile_priority: 170,
  operationalArea: 'commerce_product',
  navigationVisible: FALSE,
)]
final class AddonsSection extends EventStudioSectionBase {}
