<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_ai_draft\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_escalations\Entity\Escalation;
use Drupal\myeventlane_escalations_sla\Service\EscalationSlaBadgeResolver;
use Drupal\myeventlane_staff_playbooks\Service\PlaybookMatcher;
use Psr\Log\LoggerInterface;

/**
 * Builds SAFE context for staff escalation draft AI prompts.
 *
 * Includes: subject, description, status, priority, waiting_on, SLA, thread
 * (masked), playbook snippets, playbook AI summaries, governance standards.
 * EXCLUDES: notes (internal).
 */
final class EscalationDraftContextBuilder {

  private const GOVERNANCE_STANDARDS = 'Communication standards: Use a calm, neutral, professional tone. '
    . 'Be empathetic but avoid promises or guarantees. Keep replies concise (50–150 words where possible). '
    . 'Use Australian English. Gender-neutral language.';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PlaybookMatcher $playbookMatcher,
    private readonly EscalationSlaBadgeResolver $badgeResolver,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Builds full context for draft generation.
   *
   * @return array
   *   Context array with keys: subject, description, meta, thread, playbooks,
   *   governance_standards. Never includes notes.
   */
  public function build(int $escalation_id): array {
    /** @var \Drupal\myeventlane_escalations\Entity\Escalation|null $escalation */
    $escalation = $this->entityTypeManager->getStorage('escalation')->load($escalation_id);

    if (!$escalation) {
      $this->logger->warning('Draft context: escalation not found id={id}', ['id' => $escalation_id]);
      return ['error' => 'escalation_not_found', 'escalation_id' => $escalation_id];
    }

    $subject = $this->sanitizeText((string) $escalation->get('subject')->value);
    $description = $this->sanitizeText((string) $escalation->get('description')->value);
    $status = (string) $escalation->get('status')->value;
    $priority = (string) $escalation->get('priority')->value;
    $waiting_on = $this->resolveWaitingOn($escalation);
    $badge = $this->badgeResolver->resolve($escalation);
    $sla_label = $badge['label'] ?? 'On track';

    $thread = $this->loadSanitizedThread($escalation_id);

    $playbooks = [];
    $matched = $this->playbookMatcher->match($escalation);
    foreach ($matched as $playbook) {
      $snippets = [];
      if ($playbook->hasField('field_reply_snippets') && !$playbook->get('field_reply_snippets')->isEmpty()) {
        foreach ($playbook->get('field_reply_snippets') as $item) {
          $raw = trim((string) ($item->value ?? ''));
          if ($raw !== '') {
            $lines = explode("\n", $raw, 2);
            $snippets[] = [
              'title' => trim($lines[0] ?? ''),
              'body' => $this->sanitizeText(trim($lines[1] ?? '')),
            ];
          }
        }
      }
      $ai_summary = '';
      if ($playbook->hasField('field_ai_summary') && !$playbook->get('field_ai_summary')->isEmpty()) {
        $ai_summary = $this->sanitizeText((string) $playbook->get('field_ai_summary')->value);
      }
      $playbooks[] = [
        'title' => $playbook->label(),
        'snippets' => $snippets,
        'ai_summary' => $ai_summary,
      ];
    }

    return [
      'subject' => $subject,
      'description' => $description,
      'meta' => [
        'status' => $status,
        'priority' => $priority,
        'waiting_on' => $waiting_on,
        'sla_label' => $sla_label,
      ],
      'thread' => $thread,
      'playbooks' => $playbooks,
      'governance_standards' => self::GOVERNANCE_STANDARDS,
    ];
  }

  private function resolveWaitingOn(Escalation $escalation): string {
    if (!$escalation->hasField('field_waiting_on') || $escalation->get('field_waiting_on')->isEmpty()) {
      return '-';
    }
    $map = ['vendor' => 'Vendor', 'customer' => 'Customer', 'staff' => 'Staff'];
    return $map[(string) $escalation->get('field_waiting_on')->value] ?? '-';
  }

  private function loadSanitizedThread(int $escalation_id): array {
    try {
      $comment_storage = $this->entityTypeManager->getStorage('comment');
    }
    catch (\Throwable) {
      return [];
    }

    $cids = $comment_storage->getQuery()
      ->condition('entity_type', 'escalation')
      ->condition('entity_id', $escalation_id)
      ->condition('field_name', 'field_escalation_thread')
      ->sort('created', 'ASC')
      ->range(0, 20)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($cids)) {
      return [];
    }

    $comments = $comment_storage->loadMultiple($cids);
    $messages = [];

    foreach ($comments as $comment) {
      $author = $comment->getOwner();
      $author_name = $author ? $author->getDisplayName() : 'Unknown';
      $body = (string) ($comment->get('comment_body')->value ?? '');
      $body_plain = strip_tags($body);

      $messages[] = [
        'author' => $this->sanitizeText((string) $author_name),
        'body' => $this->sanitizeText($body_plain),
        'created' => (int) $comment->getCreatedTime(),
      ];
    }

    return $messages;
  }

  private function sanitizeText(string $text): string {
    $text = trim($text);

    $text = preg_replace_callback('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', static function (array $m): string {
      $email = $m[0];
      $parts = explode('@', $email, 2);
      if (count($parts) !== 2) {
        return '[redacted-email]';
      }
      $local = $parts[0];
      $domain = $parts[1];
      return mb_substr($local, 0, 1) . '***@' . $domain;
    }, $text) ?? $text;

    $text = preg_replace('/(\+?\d[\d\s().\-]{7,}\d)/', '[redacted-phone]', $text) ?? $text;

    return $text;
  }

}
