<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drush\Commands\DrushCommands;
use Twig\Environment;

/**
 * Read-only release health checks for MyEventLane.
 */
final class HealthCommands extends DrushCommands {

  /**
   * Constructs the commands.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StorageInterface $activeConfigStorage,
    private readonly StorageInterface $syncConfigStorage,
    private readonly QueueWorkerManagerInterface $queueWorkerManager,
    private readonly QueueFactory $queueFactory,
    private readonly Environment $twig,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct();
  }

  /**
   * Runs read-only health checks for releases.
   *
   * @command mel:health
   * @aliases mel-health
   * @usage ddev drush mel:health
   *   Run release hardening health checks (read-only).
   *
   * @return int
   *   Exit code (0 = OK, 1 = failures).
   */
  public function health(): int {
    $results = [];

    $this->checkConfigDrift($results);
    $this->checkQueueWorkers($results);
    $this->checkQueueBacklog($results);
    $this->checkMessagingTemplates($results);

    $failures = 0;
    $warnings = 0;
    foreach ($results as $row) {
      if ($row['status'] === 'FAIL') {
        $failures++;
      }
      elseif ($row['status'] === 'WARN') {
        $warnings++;
      }
    }

    $this->io()->title('MyEventLane health checks');
    $this->io()->table(['Status', 'Check', 'Details'], array_map(static function (array $r): array {
      return [$r['status'], $r['check'], $r['details']];
    }, $results));

    if ($failures > 0) {
      $this->io()->error("Health checks failed: {$failures} failure(s), {$warnings} warning(s).");
      return 1;
    }

    if ($warnings > 0) {
      $this->io()->warning("Health checks passed with warnings: {$warnings} warning(s).");
      return 0;
    }

    $this->io()->success('Health checks passed.');
    return 0;
  }

  /**
   * Adds a result row.
   */
  private function addResult(array &$results, string $status, string $check, string $details): void {
    $results[] = [
      'status' => $status,
      'check' => $check,
      'details' => $details,
    ];
  }

  /**
   * Checks for config drift between active and sync.
   */
  private function checkConfigDrift(array &$results): void {
    try {
      // Compare config as if preparing a config import: sync → active.
      $comparer = new StorageComparer($this->syncConfigStorage, $this->activeConfigStorage);
      $comparer->createChangelist();
      $create = 0;
      $update = 0;
      $delete = 0;
      $rename = 0;
      foreach ($comparer->getAllCollectionNames() as $collection) {
        $create += count($comparer->getChangelist('create', $collection));
        $update += count($comparer->getChangelist('update', $collection));
        $delete += count($comparer->getChangelist('delete', $collection));
        $rename += count($comparer->getChangelist('rename', $collection));
      }

      $count = $create + $update + $delete + $rename;
      if ($count === 0) {
        $this->addResult($results, 'OK', 'Config drift', 'No differences between active and sync.');
        return;
      }

      $this->addResult(
        $results,
        'FAIL',
        'Config drift',
        sprintf('Differences detected (create=%d, update=%d, delete=%d, rename=%d).', $create, $update, $delete, $rename)
      );
    }
    catch (\Throwable $e) {
      $this->addResult($results, 'WARN', 'Config drift', 'Unable to compare config storages: ' . $e->getMessage());
    }
  }

  /**
   * Checks that key queue worker plugins can be instantiated.
   */
  private function checkQueueWorkers(array &$results): void {
    $workers = [
      ['id' => 'myeventlane_messaging', 'module' => 'myeventlane_messaging'],
      ['id' => 'event_reminder_7d', 'module' => 'myeventlane_messaging'],
      ['id' => 'event_reminder_24h', 'module' => 'myeventlane_messaging'],

      ['id' => 'automation_sales_open', 'module' => 'myeventlane_automation'],
      ['id' => 'automation_reminder_24h', 'module' => 'myeventlane_automation'],
      ['id' => 'automation_reminder_2h', 'module' => 'myeventlane_automation'],
      ['id' => 'automation_waitlist_invite', 'module' => 'myeventlane_automation'],
      ['id' => 'automation_event_cancelled', 'module' => 'myeventlane_automation'],
      ['id' => 'automation_export_ready', 'module' => 'myeventlane_automation'],
      ['id' => 'automation_weekly_digest', 'module' => 'myeventlane_automation'],

      ['id' => 'vendor_refund_worker', 'module' => 'myeventlane_refunds'],
      ['id' => 'myeventlane_webhook_delivery', 'module' => 'myeventlane_webhooks'],
    ];

    foreach ($workers as $w) {
      if (!$this->moduleHandler->moduleExists($w['module'])) {
        $this->addResult($results, 'WARN', 'Queue worker', sprintf('%s (module %s not enabled).', $w['id'], $w['module']));
        continue;
      }

      try {
        $this->queueWorkerManager->createInstance($w['id']);
        $this->addResult($results, 'OK', 'Queue worker', sprintf('%s instantiated.', $w['id']));
      }
      catch (\Throwable $e) {
        $this->addResult($results, 'FAIL', 'Queue worker', sprintf('%s failed to instantiate: %s', $w['id'], $e->getMessage()));
      }
    }
  }

  /**
   * Checks queue backlog counts (read-only).
   */
  private function checkQueueBacklog(array &$results): void {
    $queues = [
      ['name' => 'myeventlane_messaging', 'module' => 'myeventlane_messaging'],
      ['name' => 'event_reminder_7d', 'module' => 'myeventlane_messaging'],
      ['name' => 'event_reminder_24h', 'module' => 'myeventlane_messaging'],

      ['name' => 'automation_sales_open', 'module' => 'myeventlane_automation'],
      ['name' => 'automation_reminder_24h', 'module' => 'myeventlane_automation'],
      ['name' => 'automation_reminder_2h', 'module' => 'myeventlane_automation'],
      ['name' => 'automation_waitlist_invite', 'module' => 'myeventlane_automation'],
      ['name' => 'automation_event_cancelled', 'module' => 'myeventlane_automation'],
      ['name' => 'automation_export_ready', 'module' => 'myeventlane_automation'],
      ['name' => 'automation_weekly_digest', 'module' => 'myeventlane_automation'],

      ['name' => 'vendor_refund_worker', 'module' => 'myeventlane_refunds'],
      ['name' => 'myeventlane_webhook_delivery', 'module' => 'myeventlane_webhooks'],

      // These are used as queues (not queue workers).
      ['name' => 'mel_rsvp_vendor_digest', 'module' => 'myeventlane_rsvp'],
      ['name' => 'myeventlane_rsvp_sms_delivery', 'module' => 'myeventlane_rsvp'],
    ];

    foreach ($queues as $q) {
      if (!$this->moduleHandler->moduleExists($q['module'])) {
        $this->addResult($results, 'WARN', 'Queue backlog', sprintf('%s (module %s not enabled).', $q['name'], $q['module']));
        continue;
      }

      try {
        $count = $this->queueFactory->get($q['name'])->numberOfItems();
        $this->addResult($results, 'OK', 'Queue backlog', sprintf('%s: %d item(s).', $q['name'], $count));
      }
      catch (\Throwable $e) {
        $this->addResult($results, 'FAIL', 'Queue backlog', sprintf('%s backlog check failed: %s', $q['name'], $e->getMessage()));
      }
    }
  }

  /**
   * Checks that enabled messaging templates compile as Twig.
   */
  private function checkMessagingTemplates(array &$results): void {
    $names = $this->configFactory->listAll('myeventlane_messaging.template.');
    if (empty($names)) {
      $this->addResult($results, 'WARN', 'Messaging templates', 'No template configs found.');
      return;
    }

    $enabled = 0;
    $compiled = 0;
    foreach ($names as $name) {
      $conf = $this->configFactory->get($name);
      if (!$conf->get('enabled')) {
        continue;
      }
      $enabled++;

      $subject = (string) ($conf->get('subject') ?? '');
      $body = (string) ($conf->get('body_html') ?? '');

      if ($subject === '' && $body === '') {
        $this->addResult($results, 'WARN', 'Messaging templates', sprintf('%s enabled but empty.', $name));
        continue;
      }

      try {
        if ($subject !== '') {
          $this->twig->createTemplate($subject);
        }
        if ($body !== '') {
          $this->twig->createTemplate($body);
        }
        $compiled++;
      }
      catch (\Throwable $e) {
        $this->addResult($results, 'FAIL', 'Messaging templates', sprintf('%s Twig compile failed: %s', $name, $e->getMessage()));
      }
    }

    $this->addResult($results, 'OK', 'Messaging templates', sprintf('Enabled=%d, compiled=%d.', $enabled, $compiled));
  }

}

