<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;

/**
 * Fulfilment section metadata.
 */
#[EventStudioSection(
  id: 'fulfilment',
  title: 'Fulfilment',
  group: 'Operations',
  routeName: 'myeventlane_event_studio.workspace_fulfilment',
  weight: 210,
  icon: 'fulfilment',
  operationalArea: 'fulfilment',
  deferred: TRUE,
)]
final class FulfilmentSection extends EventStudioSectionBase {}
