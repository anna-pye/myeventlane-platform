<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Stores bounded Event Studio section drafts without mutating event entities.
 *
 * Schedule and Venue share EventInformationForm with Details, so their drafts
 * use the canonical `information` storage key.
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
    $canonical = $this->canonicalSection($section);
    $payload = [
      'event_id' => (int) $event->id(),
      'section' => $canonical,
      'mel' => $mel,
      'autosave_ts' => $autosaveTimestamp,
      'base_changed' => $baseChanged,
      'base_revision_id' => $baseRevisionId,
      'stored_at' => time(),
    ];
    $this->store()->set($this->key($event, $canonical), $payload);
    // Drop alias keys so Schedule/Venue/Details cannot diverge.
    $this->deleteAliasKeys($event, $canonical, $canonical);
  }

  /**
   * @return array<string, mixed>|null
   */
  public function getDraft(NodeInterface $event, string $section): ?array {
    $canonical = $this->canonicalSection($section);
    $draft = $this->readRawDraft($event, $canonical);

    // Migrate pre-normalization alias keys (schedule/venue) into the canonical key.
    if ($draft === NULL) {
      foreach ($this->sectionAliases($canonical) as $alias) {
        if ($alias === $canonical) {
          continue;
        }
        $legacy = $this->readRawDraft($event, $alias);
        if ($legacy === NULL) {
          continue;
        }
        $legacy['section'] = $canonical;
        $this->store()->set($this->key($event, $canonical), $legacy);
        $this->deleteAliasKeys($event, $canonical, $canonical);
        $draft = $legacy;
        $this->logger->notice('Event Studio autosave draft migrated to canonical section: event_id=@nid from=@from to=@to', [
          '@nid' => (string) $event->id(),
          '@from' => $alias,
          '@to' => $canonical,
        ]);
        break;
      }
    }

    if (!is_array($draft)) {
      return NULL;
    }

    if ($this->draftIsOlderThanEntity($event, $draft)) {
      $this->clearDraft($event, $canonical);
      $this->logger->notice('Event Studio autosave draft invalidated after newer entity save: event_id=@nid section=@section', [
        '@nid' => (string) $event->id(),
        '@section' => $canonical,
      ]);
      return NULL;
    }

    return $draft;
  }

  public function hasDraft(NodeInterface $event, string $section): bool {
    return $this->getDraft($event, $section) !== NULL;
  }

  public function clearDraft(NodeInterface $event, string $section): void {
    $canonical = $this->canonicalSection($section);
    $this->deleteAliasKeys($event, $canonical, NULL);
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
   * Resolves Schedule/Venue (and similar) aliases to the shared draft storage key.
   */
  public function canonicalSection(string $section): string {
    $safe = preg_replace('/[^a-z0-9_:-]+/i', '_', trim($section)) ?: 'section';
    return match ($safe) {
      'schedule', 'venue', 'details' => 'information',
      default => $safe,
    };
  }

  /**
   * @param array<string, mixed> $draft
   */
  private function draftIsOlderThanEntity(NodeInterface $event, array $draft): bool {
    $baseChanged = (int) ($draft['base_changed'] ?? 0);
    $baseRevisionId = (int) ($draft['base_revision_id'] ?? 0);

    return $this->isStaleSubmission($event, $baseChanged, $baseRevisionId);
  }

  /**
   * @return array<string, mixed>|null
   */
  private function readRawDraft(NodeInterface $event, string $section): ?array {
    $draft = $this->store()->get($this->key($event, $section));
    return is_array($draft) ? $draft : NULL;
  }

  /**
   * @return list<string>
   */
  private function sectionAliases(string $canonical): array {
    return match ($canonical) {
      'information' => ['information', 'schedule', 'venue', 'details'],
      default => [$canonical],
    };
  }

  private function deleteAliasKeys(NodeInterface $event, string $canonical, ?string $keep): void {
    foreach ($this->sectionAliases($canonical) as $alias) {
      if ($keep !== NULL && $alias === $keep) {
        continue;
      }
      $this->store()->delete($this->key($event, $alias));
    }
  }

  private function store(): \Drupal\Core\TempStore\PrivateTempStore {
    return $this->privateTempStoreFactory->get(self::STORE);
  }

  private function key(NodeInterface $event, string $section): string {
    $safeSection = preg_replace('/[^a-z0-9_:-]+/i', '_', $section) ?: 'section';
    return 'node.' . (int) $event->id() . '.' . $safeSection;
  }

}
