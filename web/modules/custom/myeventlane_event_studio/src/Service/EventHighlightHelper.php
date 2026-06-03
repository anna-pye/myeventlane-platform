<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Highlight icon keys and row normalization for Event Studio.
 */
final class EventHighlightHelper {

  /**
   * Maximum highlights persisted and exposed in the Event Studio UI.
   */
  public const HIGHLIGHT_LIMIT = 6;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Loads allowed highlight icon machine names from field storage.
   *
   * @return list<string>
   *   Ordered allowed icon keys, or empty if storage is missing or invalid.
   */
  public function getAllowedIconKeys(): array {
    $pairs = $this->getHighlightIconAllowedPairs();
    $keys = [];
    foreach ($pairs as $pair) {
      $keys[] = $pair['value'];
    }
    return $keys;
  }

  /**
   * Machine name to label map for Event Studio JS (icon select options).
   *
   * @return array<string, string>
   *   Keys are allowed values; values are human-readable labels.
   */
  public function getIconOptionsForJs(): array {
    $out = [];
    foreach ($this->getHighlightIconAllowedPairs() as $pair) {
      $out[$pair['value']] = $pair['label'];
    }
    return $out;
  }

  /**
   * Icon picker rows for Event Studio (emoji button + storage value + label).
   *
   * @return list<array{value: string, emoji: string, label: string}>
   */
  public function getHighlightIconPickerItems(): array {
    $out = [];
    foreach ($this->getHighlightIconAllowedPairs() as $pair) {
      $value = $pair['value'];
      $label = trim($pair['label']);
      $emoji = $this->extractLeadingEmoji($label);
      $out[] = [
        'value' => $value,
        'emoji' => $emoji,
        'label' => $label,
      ];
    }
    return $out;
  }

  /**
   * Normalizes field_storage allowed_values for list_string option fields.
   *
   * Supports:
   * - Export shape: list of rows `[ ['value' => …, 'label' => …], … ]`
   * - Flat map: `[ machine_key => human_label, … ]`
   *
   * @return list<array{value: string, label: string}>
   */
  private function getHighlightIconAllowedPairs(): array {
    $storage = $this->loadHighlightIconFieldStorage();
    if ($storage === NULL) {
      return [];
    }
    $raw = $storage->getSetting('allowed_values');
    if (!is_array($raw) || $raw === []) {
      return [];
    }

    $hasStructured = FALSE;
    foreach ($raw as $item) {
      if (is_array($item) && array_key_exists('value', $item)) {
        $hasStructured = TRUE;
        break;
      }
    }

    if ($hasStructured) {
      $out = [];
      foreach ($raw as $item) {
        if (!is_array($item) || !array_key_exists('value', $item)) {
          continue;
        }
        $out[] = [
          'value' => (string) $item['value'],
          'label' => array_key_exists('label', $item) ? (string) $item['label'] : (string) $item['value'],
        ];
      }
      return $out;
    }

    $flat = [];
    foreach ($raw as $value => $label) {
      if (!is_string($value) && !is_int($value)) {
        continue;
      }
      if (!is_scalar($label)) {
        continue;
      }
      $flat[] = [
        'value' => (string) $value,
        'label' => (string) $label,
      ];
    }
    return $flat;
  }

  /**
   * First Unicode extended grapheme cluster in a string (emoji-safe lead character).
   */
  private function extractLeadingEmoji(string $label): string {
    if ($label === '') {
      return '';
    }
    if (preg_match('/^(\X)/u', $label, $m) === 1) {
      return (string) ($m[1] ?? '');
    }
    return '';
  }

  /**
   * Normalizes decoded highlight rows for persistence.
   *
   * - Trims text and icon strings.
   * - Drops rows with empty text after trim.
   * - Clears icon when it is not in $allowed_icons.
   * - Assigns zero-based weights in iteration order.
   * - Truncates to $limit rows.
   *
   * @param array<int|string, mixed> $items
   *   Raw rows (e.g. from JSON decode).
   * @param list<string> $allowed_icons
   *   Allowed icon machine names from getAllowedIconKeys().
   * @param int $limit
   *   Max rows to return (default self::HIGHLIGHT_LIMIT).
   *
   * @return list<array{text: string, icon: string, weight: int}>
   *   Normalized rows with text, icon, and weight.
   */
  public function normalizeHighlights(array $items, array $allowed_icons, int $limit = self::HIGHLIGHT_LIMIT): array {
    $out = [];
    $weight = 0;
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $text = trim((string) ($item['text'] ?? ''));
      if ($text === '') {
        continue;
      }
      $icon = trim((string) ($item['icon'] ?? ''));
      if ($icon !== '' && !in_array($icon, $allowed_icons, TRUE)) {
        $icon = '';
      }
      $out[] = [
        'text' => $text,
        'icon' => $icon,
        'weight' => $weight,
      ];
      $weight++;
    }

    return array_slice($out, 0, $limit);
  }

  /**
   * Encodes paragraph highlights for the Event Studio JS builder (hidden JSON field).
   */
  public function encodeItemsStateJsonForNode(NodeInterface $event): string {
    if (!$event->hasField('field_event_highlights') || $event->get('field_event_highlights')->isEmpty()) {
      return '[]';
    }
    $rows = [];
    foreach ($event->get('field_event_highlights')->referencedEntities() as $paragraph) {
      if (!$paragraph instanceof Paragraph || $paragraph->bundle() !== 'event_highlight') {
        continue;
      }
      $text = '';
      if ($paragraph->hasField('field_highlight_text') && !$paragraph->get('field_highlight_text')->isEmpty()) {
        $text = trim((string) $paragraph->get('field_highlight_text')->value);
      }
      $icon = '';
      if ($paragraph->hasField('field_highlight_icon') && !$paragraph->get('field_highlight_icon')->isEmpty()) {
        $icon = trim((string) $paragraph->get('field_highlight_icon')->value);
      }
      $rows[] = [
        'text' => $text,
        'icon' => $icon,
      ];
    }
    try {
      return json_encode($rows, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return '[]';
    }
  }

  /**
   * Validates hidden JSON for highlights (form / full save path).
   *
   * Empty string means no editor state; validation is skipped.
   *
   * @return list<string>
   *   User-facing error messages; empty when valid.
   */
  public function validateHighlightItemsStateJson(string $raw): array {
    if ($raw === '') {
      return [];
    }
    try {
      $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return ['Highlights data could not be read.'];
    }
    if (!is_array($decoded)) {
      return ['Highlights data could not be read.'];
    }
    $analysis = $this->analyzeDecodedHighlights($decoded);
    if ($analysis['icon_without_text']) {
      return ['Each highlight with an icon needs highlight text.'];
    }
    if ($analysis['persistable_count'] > self::HIGHLIGHT_LIMIT) {
      return ['You can add at most 6 highlights.'];
    }
    return [];
  }

  /**
   * Analysis used by form validation and items_state validation.
   *
   * Skips non-array rows. Detects icon-without-text rows. Counts rows that
   * normalizeHighlights() would keep (non-empty text after trim), matching the
   * pre-slice length used for the max-highlights check.
   *
   * @param array<int|string, mixed> $decoded
   *   Decoded PHP array from the highlights JSON state field.
   *
   * @return array{icon_without_text: bool, persistable_count: int}
   *   Whether any row has an icon but no text, and how many rows would persist.
   */
  public function analyzeDecodedHighlights(array $decoded): array {
    $icon_without_text = FALSE;
    $persistable_count = 0;
    foreach ($decoded as $item) {
      if (!is_array($item)) {
        continue;
      }
      $text = trim((string) ($item['text'] ?? ''));
      $icon = trim((string) ($item['icon'] ?? ''));
      if ($text === '' && $icon !== '') {
        $icon_without_text = TRUE;
      }
      if ($text !== '') {
        $persistable_count++;
      }
    }
    return [
      'icon_without_text' => $icon_without_text,
      'persistable_count' => $persistable_count,
    ];
  }

  /**
   * Autosave path: strict row shape and business rules before normalization.
   *
   * Aligns with validateHighlightItemsStateJson(): rejects icon-without-text
   * rows and more than HIGHLIGHT_LIMIT persistable rows (non-empty text), so
   * decode + normalize cannot pass while items_state validation fails later.
   *
   * @param array<int|string, mixed> $decoded
   *   PHP array from json_decode(..., TRUE).
   *
   * @throws \InvalidArgumentException
   *   When payload structure or highlight rows are invalid for MEL autosave.
   */
  public function validateDecodedForAutosaveRequest(array $decoded): void {
    foreach ($decoded as $row) {
      if (!is_array($row)) {
        throw new \InvalidArgumentException('Invalid highlight row.');
      }
      $icon = trim((string) ($row['icon'] ?? ''));
      $text = trim((string) ($row['text'] ?? ''));
      if ($icon !== '' && $text === '') {
        throw new \InvalidArgumentException('Highlight text required when icon is set.');
      }
    }
    $analysis = $this->analyzeDecodedHighlights($decoded);
    if ($analysis['persistable_count'] > self::HIGHLIGHT_LIMIT) {
      throw new \InvalidArgumentException('You can add at most 6 highlights.');
    }
  }

  /**
   * Loads paragraph field storage for highlight icons, if configured.
   */
  private function loadHighlightIconFieldStorage(): ?FieldStorageConfig {
    $storage = $this->entityTypeManager
      ->getStorage('field_storage_config')
      ->load('paragraph.field_highlight_icon');
    return $storage instanceof FieldStorageConfig ? $storage : NULL;
  }

}
