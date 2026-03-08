<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\Commands;

use Drupal\myeventlane_domain_events\Service\ProjectionReplayService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for domain event observability and maintenance.
 */
final class DomainEventsCommands extends DrushCommands {

  public function __construct(
    private readonly ProjectionReplayService $projectionReplay,
  ) {
    parent::__construct();
  }

  /**
   * Truncates all projection tables and rebuilds from domain events.
   */
  #[CLI\Command(name: 'mel:events:replay', aliases: ['mel-events-replay'])]
  #[CLI\Usage(name: 'drush mel:events:replay', description: 'Replay all domain events to rebuild projections.')]
  #[CLI\Option(name: 'dry-run', description: 'Scan events without truncating or rebuilding projections.')]
  #[CLI\Option(name: 'chunk-size', description: 'Replay chunk size (1-5000).')]
  public function replay(array $options = ['dry-run' => FALSE, 'chunk-size' => 500]): int {
    $dryRun = (bool) ($options['dry-run'] ?? FALSE);
    $chunkSizeInput = $options['chunk-size'] ?? 500;
    if (!is_numeric((string) $chunkSizeInput)) {
      $this->io()->error('Invalid --chunk-size value. Expected an integer between 1 and 5000.');
      return 1;
    }

    $chunkSize = (int) $chunkSizeInput;
    if ($chunkSize < 1 || $chunkSize > 5000) {
      $this->io()->error('Invalid --chunk-size value. Expected an integer between 1 and 5000.');
      return 1;
    }

    if (!$dryRun) {
      $confirmed = $this->io()->confirm(
        'This will truncate projection tables and rebuild them from the event store. Continue?',
        FALSE
      );
      if (!$confirmed) {
        $this->io()->warning('Replay cancelled.');
        return 1;
      }
    }

    if ($dryRun) {
      $this->output()->writeln('Dry run: no projections will be truncated or rebuilt.');
      $scanned = $this->projectionReplay->replayAll(TRUE, $chunkSize);
      $this->output()->writeln(sprintf('Events scanned: %s', number_format($scanned)));
      return 0;
    }

    $this->output()->writeln('Replaying domain events...');

    $nextMilestone = 1000;
    $lastReported = 0;
    $processed = $this->projectionReplay->replayAll(FALSE, $chunkSize, function (int $count) use (&$nextMilestone, &$lastReported): void {
      if ($count < $nextMilestone) {
        return;
      }

      while ($count >= $nextMilestone) {
        $lastReported = $nextMilestone;
        $this->output()->writeln(sprintf('Events processed: %s', number_format($nextMilestone)));
        $nextMilestone += 1000;
      }
    });

    if ($processed !== $lastReported) {
      $this->output()->writeln(sprintf('Events processed: %s', number_format($processed)));
    }

    $this->output()->writeln('Replay completed.');
    return 0;
  }

}
