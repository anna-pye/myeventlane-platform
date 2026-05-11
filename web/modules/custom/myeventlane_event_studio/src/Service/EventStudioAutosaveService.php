<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Stores bounded Event Studio section drafts without mutating event entities.
 */
final class EventStudioAutosaveService {

  private const STORE = 'myeventlane_event_studio_autosave';

  public function __construct(
    private readonly PrivateTempStoreFactory $privateTempStoreFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * @param array<string, mixed> $mel
   */
  public function storeDraft(NodeInterface $event, string $section, array $mel, float $autosaveTimestamp, int $baseChanged, int $baseRevisionId): void {
    $this->privateTempStoreFactory
      ->get(self::STORE)
      ->set($this->key($event, $section), [
        'event_id' => (int) $event->id(),
        'section' => $section,
        'mel' => $mel,
        'autosave_ts' => $autosaveTimestamp,
        'base_changed' => $baseChanged,
        'base_revision_id' => $baseRevisionId,
        'stored_at' => time(),
      ]);
  }

  /**
   * @return array<string, mixed>|null
   */
  public function getDraft(NodeInterface $event, string $section): ?array {
    $draft = $this->privateTempStoreFactory
      ->get(self::STORE)
      ->get($this->key($event, $section));

    if (!is_array($draft)) {
      return NULL;
    }

    if ($this->draftIsOlderThanEntity($event, $draft)) {
      $this->clearDraft($event, $section);
      $this->logger->notice('Event Studio autosave draft invalidated after newer entity save: event_id=@nid section=@section', [
        '@nid' => (string) $event->id(),
        '@section' => $section,
      ]);
      return NULL;
    }

    return $draft;
  }

  public function hasDraft(NodeInterface $event, string $section): bool {
    return $this->getDraft($event, $section) !== NULL;
  }

  public function clearDraft(NodeInterface $event, string $section): void {
    $this->privateTempStoreFactory
      ->get(self::STORE)
      ->delete($this->key($event, $section));
  }

  public function isStaleSubmission(NodeInterface $event, int $baseChanged, int $baseRevisionId): bool {
    if ($baseChanged > 0 && $event->getChangedTime() > $baseChanged) {
      return TRUE;
    }

    if ($baseRevisionId > 0 && (int) $event->getRevisionId() !== $baseRevisionId) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * @param array<string, mixed> $draft
   */
  private function draftIsOlderThanEntity(NodeInterface $event, array $draft): bool {
    $baseChanged = (int) ($draft['base_changed'] ?? 0);
    $baseRevisionId = (int) ($draft['base_revision_id'] ?? 0);

    return $this->isStaleSubmission($event, $baseChanged, $baseRevisionId);
  }

  private function key(NodeInterface $event, string $section): string {
    $safeSection = preg_replace('/[^a-z0-9_:-]+/i', '_', $section) ?: 'section';
    return 'node.' . (int) $event->id() . '.' . $safeSection;
  }

}
