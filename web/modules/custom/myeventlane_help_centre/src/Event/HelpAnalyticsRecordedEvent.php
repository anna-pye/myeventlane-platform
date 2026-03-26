<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired after a row is written to myeventlane_help_analytics.
 */
final class HelpAnalyticsRecordedEvent extends Event {

  public const NAME = 'myeventlane_help_centre.help_analytics_recorded';

  public function __construct(
    public readonly string $eventType,
    public readonly ?int $nodeId,
    public readonly ?string $query,
    public readonly ?string $contextAudience,
    public readonly ?int $uid,
    public readonly int $created,
  ) {}

}
