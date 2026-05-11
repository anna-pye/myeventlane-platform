<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;

/**
 * Defines an Event Studio section plugin.
 *
 * Routes remain explicit YAML. This attribute describes operational metadata
 * used by the Studio shell, navigation, readiness contracts, and governance.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class EventStudioSection extends Plugin {

  public function __construct(
    string $id,
    public readonly string $title,
    public readonly string $group,
    public readonly string $routeName,
    public readonly int $weight = 0,
    public readonly string $icon = '',
    public readonly string $routeFragment = '',
    public readonly string $accessPolicy = 'event_update',
    public readonly string $renderTarget = 'controller',
    public readonly bool $readinessParticipant = FALSE,
    public readonly string $operationalArea = 'event',
    public readonly bool $deferred = FALSE,
    ?string $deriver = NULL,
  ) {
    parent::__construct($id, $deriver);
  }

}
