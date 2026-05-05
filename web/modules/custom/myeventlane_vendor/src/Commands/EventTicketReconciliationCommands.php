<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_vendor\Service\EventTicketReconciliationService;
use Drupal\node\NodeInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for ticket mapping diagnostics and safe repair.
 */
final class EventTicketReconciliationCommands extends DrushCommands {

  public function __construct(
    private readonly EventTicketReconciliationService $reconciliation,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Audit ticket mapping / paid display signals for events.
   *
   * @command mel:tickets:audit
   * @usage drush mel:tickets:audit
   *   Audit recent events (see --limit).
   * @usage drush mel:tickets:audit --event=1592
   *   Audit a single event by nid.
   * @option event Optional event node ID.
   * @option limit Max events when --event is omitted (default 50).
   * @option format Output format: table or json.
   */
  public function audit(array $options = ['event' => NULL, 'limit' => 50, 'format' => 'table']): int {
    $format = strtolower((string) ($options['format'] ?? 'table'));
    $eventOpt = $options['event'] ?? NULL;
    $eventId = $eventOpt !== NULL && $eventOpt !== '' ? (int) $eventOpt : NULL;
    $limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 50;

    $batch = $this->reconciliation->auditAll([
      'event_id' => $eventId,
      'limit' => $eventId !== NULL ? 1 : $limit,
    ]);

    if ($format === 'json') {
      $this->output()->writeln(json_encode($batch, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
      return 0;
    }

    $summary = $batch['summary'];
    $this->io()->title('MEL ticket reconciliation audit');
    $this->io()->definitionList(
      ['Events scanned' => (string) $summary['scanned']],
      ['With errors' => (string) $summary['with_errors']],
      ['With warnings' => (string) $summary['with_warnings']],
      ['Repairable (audit)' => (string) $summary['repairable_events']],
    );

    foreach ($batch['events'] as $row) {
      $this->io()->section(sprintf('Event %d — %s', (int) $row['event_id'], (string) $row['event_title']));
      $this->io()->writeln(sprintf('Booking mode: %s', $row['booking_mode'] ?? '(n/a)'));
      $this->io()->writeln(sprintf('Product ID: %s', $row['product_id'] !== NULL ? (string) $row['product_id'] : '(none)'));
      $this->io()->writeln(sprintf('Repairable: %s', !empty($row['repairable']) ? 'yes' : 'no'));

      $table = [];
      foreach ($row['issues'] as $issue) {
        $table[] = [
          (string) ($issue['severity'] ?? ''),
          (string) ($issue['code'] ?? ''),
          (string) ($issue['message'] ?? ''),
          isset($issue['variation_id']) && $issue['variation_id'] !== NULL ? (string) $issue['variation_id'] : '—',
          isset($issue['ticket_type_id']) && $issue['ticket_type_id'] !== NULL ? (string) $issue['ticket_type_id'] : '—',
          !empty($issue['repairable']) ? 'yes' : 'no',
          (string) ($issue['repair_action'] ?? '—'),
        ];
      }
      $this->io()->table(
        ['Severity', 'Code', 'Message', 'Var', 'Tier', 'Repair?', 'Action'],
        $table,
      );
    }

    return 0;
  }

  /**
   * Dry-run or apply safe ticket reference reconciliation for one event.
   *
   * Default is dry-run. Pass --apply to persist changes (still requires --event).
   *
   * @command mel:tickets:repair
   * @usage drush mel:tickets:repair --event=1592
   *   Show planned reconcile steps without saving.
   * @usage drush mel:tickets:repair --event=1592 --apply
   *   May link/create ticket product (TASK 15) then run reconcileEventTicketReferences().
   * @option event Required event node ID.
   * @option apply Write changes (otherwise dry-run).
   * @option format Output format: table or json.
   */
  public function repair(array $options = ['event' => NULL, 'apply' => FALSE, 'format' => 'table']): int {
    $format = strtolower((string) ($options['format'] ?? 'table'));
    $eventOpt = $options['event'] ?? NULL;
    if ($eventOpt === NULL || $eventOpt === '') {
      $this->io()->error('Option --event is required for mel:tickets:repair.');
      return 1;
    }

    $eventId = (int) $eventOpt;
    $apply = !empty($options['apply']);

    $node = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$node instanceof NodeInterface) {
      $this->io()->error(sprintf('Event node %d not found.', $eventId));
      return 1;
    }

    $result = $this->reconciliation->repairEvent($node, ['apply' => $apply]);

    if ($format === 'json') {
      $this->output()->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
      return 0;
    }

    $this->io()->title(sprintf('MEL ticket reconciliation repair — event %d', $eventId));
    $this->io()->note('Repair may link field_product_target, run TicketTypeManager::syncTicketTypesToVariations(), reconcile field_ticket_types, and/or rebuild commerce_product.variations from mapped tiers (TASK 17). Lifecycle sync may unpublish orphan Commerce variations. Review Commerce/ticket state before using --apply.');
    $this->io()->writeln(sprintf('Dry run: %s', !empty($result['dry_run']) ? 'yes' : 'no'));
    $this->io()->writeln(sprintf('Applied: %s', !empty($result['applied']) ? 'yes' : 'no'));
    if (array_key_exists('success', $result) && $result['success'] !== NULL) {
      $this->io()->writeln(sprintf(
        'Repair success (no orphan variation blockers): %s',
        !empty($result['success']) ? 'yes' : 'no',
      ));
    }
    if (!empty($result['applied']) && array_key_exists('success', $result) && $result['success'] === FALSE) {
      $ids = $result['remaining_orphan_variation_ids'] ?? [];
      $this->io()->warning('Repair ran, but remaining ticket reconciliation blockers were detected (orphans and/or product variation reference drift).');
      if ($ids !== []) {
        $this->io()->writeln(sprintf('Orphan variation IDs: %s', implode(', ', array_map('strval', $ids))));
      }
      $this->io()->writeln('Review audit_after issues; inspect mel:tickets:orphans / mel:tickets:cleanup-orphans when orphan_variation_not_repairable applies.');
    }
    if (!empty($result['skipped_reason'])) {
      $this->io()->warning(sprintf('Skipped: %s', (string) $result['skipped_reason']));
      if (($result['skipped_reason'] ?? '') === 'orphan_variation_not_repairable') {
        $this->io()->writeln('');
        $this->io()->writeln(sprintf('Run:'));
        $this->io()->writeln(sprintf('  drush mel:tickets:orphans --event=%d', $eventId));
        $this->io()->writeln(sprintf('Then dry-run:'));
        $this->io()->writeln(sprintf('  drush mel:tickets:cleanup-orphans --event=%d', $eventId));
      }
    }

    foreach ($result['actions'] as $line) {
      $this->io()->writeln(' • ' . $line);
    }

    if (!empty($result['audit_after'])) {
      $this->io()->section('Audit after repair');
      foreach ($result['audit_after']['issues'] as $issue) {
        $this->io()->writeln(sprintf(
          '[%s] %s — %s',
          (string) ($issue['severity'] ?? ''),
          (string) ($issue['code'] ?? ''),
          (string) ($issue['message'] ?? ''),
        ));
      }
    }

    return 0;
  }

  /**
   * Inspect orphan Commerce ticket variations for one event (TASK 16).
   *
   * @command mel:tickets:orphans
   * @usage drush mel:tickets:orphans --event=1592
   * @option event Required event node ID.
   * @option format Output format: table or json.
   */
  public function orphans(array $options = ['event' => NULL, 'format' => 'table']): int {
    $format = strtolower((string) ($options['format'] ?? 'table'));
    $eventOpt = $options['event'] ?? NULL;
    if ($eventOpt === NULL || $eventOpt === '') {
      $this->io()->error('Option --event is required for mel:tickets:orphans.');
      return 1;
    }

    $eventId = (int) $eventOpt;
    $node = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$node instanceof NodeInterface) {
      $this->io()->error(sprintf('Event node %d not found.', $eventId));
      return 1;
    }

    $payload = $this->reconciliation->inspectOrphanVariations($node);

    if ($format === 'json') {
      $this->output()->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
      return 0;
    }

    $this->io()->title(sprintf('MEL orphan ticket variations — event %d', $eventId));
    $this->io()->writeln(sprintf('Title: %s', (string) ($payload['event_title'] ?? '')));
    $this->io()->writeln(sprintf('Product ID: %s', isset($payload['product_id']) && $payload['product_id'] !== NULL ? (string) $payload['product_id'] : '(none)'));

    $summary = $payload['summary'] ?? [];
    $this->io()->definitionList(
      ['Total orphans' => (string) ($summary['total_orphans'] ?? '0')],
      ['Safe to unpublish (inspect)' => (string) ($summary['safe_to_unpublish'] ?? '0')],
      ['Manual review' => (string) ($summary['manual_review'] ?? '0')],
    );

    $rows = [];
    foreach ($payload['orphans'] ?? [] as $row) {
      $usage = $row['order_usage'] ?? [];
      $rows[] = [
        (string) ($row['variation_id'] ?? ''),
        (string) ($row['label'] ?? ''),
        !empty($row['status']) ? 'published' : 'unpublished',
        (string) ($row['sku'] ?? '—'),
        (string) ($row['price'] ?? '—'),
        (string) ($usage['total_order_items'] ?? '0'),
        (string) ($usage['completed_order_items'] ?? '0'),
        (string) ($usage['draft_order_items'] ?? '0'),
        !empty($usage['has_completed_usage']) ? 'yes' : 'no',
        !empty($row['safe_to_unpublish']) ? 'yes' : 'no',
        (string) ($row['recommended_action'] ?? ''),
        (string) ($row['reason'] ?? ''),
      ];
    }

    $this->io()->table(
      [
        'Var',
        'Label',
        'Status',
        'SKU',
        'Price',
        'OrdItems',
        'Compl',
        'Draft',
        'Compl?',
        'Safe?',
        'Action',
        'Reason',
      ],
      $rows,
    );

    return 0;
  }

  /**
   * Dry-run or apply unpublish-only cleanup for orphan ticket variations (TASK 16).
   *
   * Default is dry-run. Pass --apply to unpublish safe orphans only.
   *
   * @command mel:tickets:cleanup-orphans
   * @usage drush mel:tickets:cleanup-orphans --event=1592
   * @usage drush mel:tickets:cleanup-orphans --event=1592 --variation=4173,4174
   * @option event Required event node ID.
   * @option variation Optional variation ID(s); comma-separated or repeat --variation=.
   * @option apply Persist unpublish for safe orphan variations.
   * @option no-reconcile Skip mel:tickets:repair after cleanup when using --apply.
   * @option disallow-draft-usage Refuse unpublish when draft/cart order items reference the variation.
   * @option format Output format: table or json.
   */
  public function cleanupOrphans(array $options = [
    'event' => NULL,
    'variation' => [],
    'apply' => FALSE,
    'no-reconcile' => FALSE,
    'disallow-draft-usage' => FALSE,
    'format' => 'table',
  ]): int {
    $format = strtolower((string) ($options['format'] ?? 'table'));
    $eventOpt = $options['event'] ?? NULL;
    if ($eventOpt === NULL || $eventOpt === '') {
      $this->io()->error('Option --event is required for mel:tickets:cleanup-orphans.');
      return 1;
    }

    $eventId = (int) $eventOpt;
    $node = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$node instanceof NodeInterface) {
      $this->io()->error(sprintf('Event node %d not found.', $eventId));
      return 1;
    }

    $variationIds = $this->normalizeVariationOption($options['variation'] ?? []);
    $apply = !empty($options['apply']);
    $thenReconcile = empty($options['no-reconcile']);
    $allowDraftUsage = empty($options['disallow-draft-usage']);

    $result = $this->reconciliation->cleanupOrphanVariations($node, [
      'apply' => $apply,
      'allow_draft_usage' => $allowDraftUsage,
      'variation_ids' => $variationIds,
      'then_reconcile' => $thenReconcile,
    ]);

    if ($format === 'json') {
      $this->output()->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
      return 0;
    }

    $this->io()->title(sprintf('MEL orphan variation cleanup — event %d', $eventId));
    $this->io()->note('Unpublish only; never deletes. Default is dry-run unless --apply.');
    $this->io()->writeln(sprintf('Dry run: %s', !empty($result['dry_run']) ? 'yes' : 'no'));
    $this->io()->writeln(sprintf('Applied: %s', !empty($result['applied']) ? 'yes' : 'no'));
    $this->io()->writeln(sprintf('Reconcile tail: %s', $thenReconcile ? 'enabled (after --apply)' : 'skipped (--no-reconcile)'));
    $this->io()->writeln(sprintf('Draft usage policy: %s', $allowDraftUsage ? 'allow unpublish when only draft/cart rows exist' : 'disallow when draft/cart rows exist'));

    foreach ($result['changes'] ?? [] as $change) {
      $this->io()->writeln(sprintf(
        ' • variation %s — %s — %s',
        (string) ($change['variation_id'] ?? ''),
        (string) ($change['action'] ?? ''),
        (string) ($change['reason'] ?? ''),
      ));
      if (!empty($change['diagnostic']) && is_array($change['diagnostic'])) {
        $d = $change['diagnostic'];
        $this->io()->writeln(sprintf(
          '     diagnostic: published_before=%s published_after=%s raw_status_before=%s raw_status_after=%s storage_reload_confirms_published=%s',
          json_encode($d['published_before'] ?? NULL),
          json_encode($d['published_after'] ?? NULL),
          json_encode($d['raw_status_before'] ?? NULL),
          json_encode($d['raw_status_after'] ?? NULL),
          json_encode($d['storage_reload_confirms_published'] ?? NULL),
        ));
        if (isset($d['product_reference_removed'])) {
          $this->io()->writeln(sprintf(
            '     product_reference_removed=%s',
            json_encode($d['product_reference_removed']),
          ));
        }
        if (!empty($d['suspected_next_action'])) {
          $this->io()->writeln('     suspected_next_action: ' . (string) $d['suspected_next_action']);
        }
      }
    }

    $blockers = $result['remaining_blockers'] ?? [];
    if ($blockers !== []) {
      $this->io()->warning('Remaining blockers after cleanup/reconcile tail:');
      foreach ($blockers as $line) {
        $this->io()->writeln('   — ' . $line);
      }
    }

    if (!empty($result['post_audit']) && is_array($result['post_audit'])) {
      $this->io()->section('Audit after cleanup / repair tail');
      foreach ($result['post_audit']['issues'] ?? [] as $issue) {
        $this->io()->writeln(sprintf(
          '[%s] %s — %s',
          (string) ($issue['severity'] ?? ''),
          (string) ($issue['code'] ?? ''),
          (string) ($issue['message'] ?? ''),
        ));
      }
    }

    return 0;
  }

  /**
   * @param mixed $raw
   *   A single scalar, or a list of scalars from repeatable options.
   *
   * @return list<int>
   */
  private function normalizeVariationOption(mixed $raw): array {
    if ($raw === NULL || $raw === '' || $raw === []) {
      return [];
    }
    if (is_array($raw)) {
      $out = [];
      foreach ($raw as $piece) {
        $out = array_merge($out, $this->normalizeVariationOption($piece));
      }
      return array_values(array_unique(array_filter(
        array_map(static fn ($v): int => (int) $v, $out),
        static fn (int $id): bool => $id > 0,
      )));
    }
    $parts = preg_split('/\s*,\s*/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return $this->normalizeVariationOption($parts);
  }

}
