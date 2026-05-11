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
  weight: 140,
  icon: 'addons',
  routeFragment: 'addons',
  operationalArea: 'commerce_product',
  deferred: TRUE,
)]
final class AddonsSection extends EventStudioSectionBase {}
