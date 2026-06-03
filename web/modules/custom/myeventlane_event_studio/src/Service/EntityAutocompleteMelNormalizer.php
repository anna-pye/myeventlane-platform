<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Component\Utility\Tags;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Element;
use Psr\Log\LoggerInterface;

/**
 * Ensures entity_autocomplete #default_value matches what core expects (entities only).
 *
 * Dev databases can hold orphaned references or odd state; staging may not. Normalizing
 * avoids InvalidArgumentException in EntityAutocomplete::valueCallback().
 */
final class EntityAutocompleteMelNormalizer {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  public function normalizeSingle(mixed $value, string $entity_type_id, string $context_key): ?EntityInterface {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (is_array($value)) {
      $value = $this->extractSingleEntityValue($value);
      if ($value === NULL || $value === '') {
        return NULL;
      }
    }
    if ($value instanceof EntityInterface) {
      if ($value->getEntityTypeId() === $entity_type_id) {
        return $value;
      }
      $this->logDiscard($context_key, $entity_type_id, $value);
      return NULL;
    }
    if (is_numeric($value)) {
      $entity = $this->entityTypeManager->getStorage($entity_type_id)->load((int) $value);
      if ($entity instanceof EntityInterface) {
        return $entity;
      }
      $this->logDiscard($context_key, $entity_type_id, $value);
      return NULL;
    }
    if (is_string($value)) {
      $entity_id = EntityAutocomplete::extractEntityIdFromAutocompleteInput($value);
      if ($entity_id !== NULL) {
        $entity = $this->entityTypeManager->getStorage($entity_type_id)->load((int) $entity_id);
        if ($entity instanceof EntityInterface) {
          return $entity;
        }
      }
      $this->logDiscard($context_key, $entity_type_id, $value);
      return NULL;
    }
    $this->logDiscard($context_key, $entity_type_id, $value);
    return NULL;
  }

  /**
   * @param array<mixed> $value
   */
  private function extractSingleEntityValue(array $value): mixed {
    if ($value === []) {
      return NULL;
    }
    if (array_key_exists('target_id', $value)) {
      return $value['target_id'];
    }

    $items = array_values(array_filter($value, static fn(mixed $item): bool => $item !== NULL && $item !== ''));
    if (count($items) !== 1) {
      return $value;
    }

    $item = $items[0];
    if ($item instanceof EntityInterface || is_numeric($item)) {
      return $item;
    }
    if (is_array($item) && array_key_exists('target_id', $item)) {
      return $item['target_id'];
    }
    return $value;
  }

  /**
   * @return array<int, EntityInterface>
   */
  public function normalizeTags(mixed $value, string $entity_type_id, string $context_key): array {
    if ($value === NULL || $value === []) {
      return [];
    }
    if ($value instanceof EntityInterface) {
      if ($value->getEntityTypeId() === $entity_type_id) {
        return [$value];
      }
      $this->logDiscard($context_key, $entity_type_id, $value);
      return [];
    }
    if (is_string($value)) {
      return $this->normalizeTagString($value, $entity_type_id, $context_key);
    }
    if (!is_array($value)) {
      $this->logDiscard($context_key, $entity_type_id, $value);
      return [];
    }
    $out = [];
    $dropped = 0;
    foreach ($value as $item) {
      if ($item instanceof EntityInterface && $item->getEntityTypeId() === $entity_type_id) {
        $out[] = $item;
        continue;
      }
      if (is_numeric($item)) {
        $entity = $this->entityTypeManager->getStorage($entity_type_id)->load((int) $item);
        if ($entity instanceof EntityInterface) {
          $out[] = $entity;
        }
        else {
          $dropped++;
        }
        continue;
      }
      if ($item !== NULL && $item !== '') {
        $dropped++;
      }
    }
    if ($dropped > 0) {
      $this->logger->warning('Dropped @count invalid tag default value(s) for @key (entity type @type).', [
        '@count' => (string) $dropped,
        '@key' => $context_key,
        '@type' => $entity_type_id,
      ]);
    }
    return $out;
  }

  /**
   * Normalizes submitted/default values by reading entity_autocomplete elements.
   *
   * @param array<string, mixed> $form
   *   A Form API element tree.
   * @param array<string, mixed> $values
   *   Submitted or restored values aligned to the same tree.
   * @param array<string, scalar|null> $log_context
   *   Optional operational context: section, event_id, uid.
   *
   * @return array<string, mixed>
   *   Values with entity_autocomplete leaves normalized for safe replay.
   */
  public function normalizeValuesForForm(array $form, array $values, array $log_context = []): array {
    $normalized = $values;
    $this->normalizeValuesRecursive($form, $normalized, [], $log_context);
    return $normalized;
  }

  /**
   * Rewrites entity_autocomplete #default_value entries in a Form API tree.
   *
   * @param array<string, mixed> $form
   * @param array<string, scalar|null> $log_context
   *   Optional operational context: section, event_id, uid.
   */
  public function normalizeDefaultValuesForForm(array &$form, array $log_context = []): void {
    $this->normalizeDefaultValuesRecursive($form, [], $log_context);
  }

  /**
   * @param array<string, mixed> $element
   * @param array<string, mixed> $values
   * @param list<string> $path
   * @param array<string, scalar|null> $log_context
   */
  private function normalizeValuesRecursive(array $element, array &$values, array $path, array $log_context): void {
    foreach (Element::children($element) as $key) {
      if (!isset($element[$key]) || !is_array($element[$key])) {
        continue;
      }
      $child = $element[$key];
      $child_path = [...$path, (string) $key];
      if (($child['#type'] ?? NULL) === 'entity_autocomplete') {
        if (!array_key_exists($key, $values)) {
          continue;
        }
        $target_type = $child['#target_type'] ?? NULL;
        if (!is_string($target_type) || $target_type === '') {
          continue;
        }
        $context_key = 'mel.' . implode('.', $child_path);
        $child_context = $log_context + [
          'field_key' => implode('.', $child_path),
          'payload_type' => is_object($values[$key]) ? get_debug_type($values[$key]) : gettype($values[$key]),
        ];
        $values[$key] = !empty($child['#tags'])
          ? $this->normalizeTagsForReplay($values[$key], $target_type, $context_key, $child_context)
          : $this->normalizeSingleForReplay($values[$key], $target_type, $context_key, $child_context);
        continue;
      }
      if (array_key_exists($key, $values) && is_array($values[$key])) {
        $this->normalizeValuesRecursive($child, $values[$key], $child_path, $log_context);
      }
    }
  }

  /**
   * @param array<string, mixed> $element
   * @param list<string> $path
   * @param array<string, scalar|null> $log_context
   */
  private function normalizeDefaultValuesRecursive(array &$element, array $path, array $log_context): void {
    if (($element['#type'] ?? NULL) === 'entity_autocomplete' && array_key_exists('#default_value', $element)) {
      $target_type = $element['#target_type'] ?? NULL;
      if (is_string($target_type) && $target_type !== '') {
        $context_key = 'mel.' . implode('.', $path);
        $child_context = $log_context + [
          'field_key' => implode('.', $path),
          'payload_type' => is_object($element['#default_value']) ? get_debug_type($element['#default_value']) : gettype($element['#default_value']),
        ];
        $element['#default_value'] = !empty($element['#tags'])
          ? $this->normalizeTagsForReplay($element['#default_value'], $target_type, $context_key, $child_context)
          : $this->normalizeSingleForReplay($element['#default_value'], $target_type, $context_key, $child_context);
      }
    }

    foreach (Element::children($element) as $key) {
      if (!isset($element[$key]) || !is_array($element[$key])) {
        continue;
      }
      $child_path = [...$path, (string) $key];
      $this->normalizeDefaultValuesRecursive($element[$key], $child_path, $log_context);
    }
  }

  /**
   * @param array<string, scalar|null> $log_context
   */
  private function normalizeSingleForReplay(mixed $value, string $entity_type_id, string $context_key, array $log_context): ?EntityInterface {
    $normalized = $this->normalizeSingleWithoutLogging($value, $entity_type_id);
    if ($normalized instanceof EntityInterface || $value === NULL || $value === '') {
      return $normalized;
    }
    $this->logReplayDiscard($context_key, $entity_type_id, $value, $log_context);
    return NULL;
  }

  /**
   * @param array<string, scalar|null> $log_context
   *
   * @return array<int, \Drupal\Core\Entity\EntityInterface>
   */
  private function normalizeTagsForReplay(mixed $value, string $entity_type_id, string $context_key, array $log_context): array {
    $normalized = $this->normalizeTagsWithoutLogging($value, $entity_type_id);
    if ($normalized !== [] || $value === NULL || $value === [] || $value === '') {
      return $normalized;
    }
    $this->logReplayDiscard($context_key, $entity_type_id, $value, $log_context);
    return [];
  }

  private function normalizeSingleWithoutLogging(mixed $value, string $entity_type_id): ?EntityInterface {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (is_array($value)) {
      $value = $this->extractSingleEntityValue($value);
      if ($value === NULL || $value === '') {
        return NULL;
      }
      if (is_array($value)) {
        return NULL;
      }
    }
    if ($value instanceof EntityInterface) {
      return $value->getEntityTypeId() === $entity_type_id ? $value : NULL;
    }
    if (is_numeric($value)) {
      $entity = $this->entityTypeManager->getStorage($entity_type_id)->load((int) $value);
      return $entity instanceof EntityInterface ? $entity : NULL;
    }
    if (is_string($value)) {
      $entity_id = EntityAutocomplete::extractEntityIdFromAutocompleteInput($value);
      if ($entity_id !== NULL) {
        $entity = $this->entityTypeManager->getStorage($entity_type_id)->load((int) $entity_id);
        return $entity instanceof EntityInterface ? $entity : NULL;
      }
    }
    return NULL;
  }

  /**
   * @return array<int, \Drupal\Core\Entity\EntityInterface>
   */
  private function normalizeTagsWithoutLogging(mixed $value, string $entity_type_id): array {
    if ($value === NULL || $value === [] || $value === '') {
      return [];
    }
    if ($value instanceof EntityInterface) {
      return $value->getEntityTypeId() === $entity_type_id ? [$value] : [];
    }
    if (is_string($value)) {
      return $this->loadTagIds($this->extractTagIdsFromString($value), $entity_type_id);
    }
    if (!is_array($value)) {
      return [];
    }
    $ids = [];
    $entities = [];
    $had_entity_input = FALSE;
    foreach ($value as $item) {
      if ($item instanceof EntityInterface) {
        $had_entity_input = TRUE;
        if ($item->getEntityTypeId() === $entity_type_id) {
          $entities[] = $item;
        }
        continue;
      }
      if (is_numeric($item)) {
        $ids[] = (int) $item;
        continue;
      }
      if (is_array($item) && isset($item['target_id']) && is_numeric($item['target_id'])) {
        $ids[] = (int) $item['target_id'];
        continue;
      }
      if (is_string($item) && $item !== '') {
        foreach ($this->extractTagIdsFromString($item) as $id) {
          $ids[] = $id;
        }
      }
    }
    $unique_ids = array_values(array_unique(array_filter($ids)));
    if ($had_entity_input) {
      return array_values([...$entities, ...$this->loadTagIds($unique_ids, $entity_type_id)]);
    }
    if ($unique_ids === []) {
      return [];
    }
    return array_values(array_map(static fn(int $id): array => ['target_id' => $id], $unique_ids));
  }

  /**
   * @return array<int, \Drupal\Core\Entity\EntityInterface>
   */
  private function normalizeTagString(string $value, string $entity_type_id, string $context_key): array {
    $ids = $this->extractTagIdsFromString($value);
    if ($ids === [] && trim($value) !== '') {
      $this->logDiscard($context_key, $entity_type_id, $value);
      return [];
    }
    return $this->loadTagIds($ids, $entity_type_id);
  }

  /**
   * @return list<int>
   */
  private function extractTagIdsFromString(string $value): array {
    $ids = [];
    foreach (Tags::explode($value) as $part) {
      $part = trim($part);
      if ($part === '') {
        continue;
      }
      if (is_numeric($part)) {
        $ids[] = (int) $part;
        continue;
      }
      $entity_id = EntityAutocomplete::extractEntityIdFromAutocompleteInput($part);
      if ($entity_id !== NULL) {
        $ids[] = (int) $entity_id;
      }
    }
    return array_values(array_unique(array_filter($ids)));
  }

  /**
   * @param list<int> $ids
   *
   * @return array<int, \Drupal\Core\Entity\EntityInterface>
   */
  private function loadTagIds(array $ids, string $entity_type_id): array {
    $out = [];
    foreach (array_values(array_unique(array_filter($ids))) as $id) {
      $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);
      if ($entity instanceof EntityInterface) {
        $out[] = $entity;
      }
    }
    return $out;
  }

  private function logDiscard(string $context_key, string $entity_type_id, mixed $value): void {
    $info = is_object($value) ? get_debug_type($value) : gettype($value);
    $this->logger->warning('Discarded invalid entity_autocomplete default for @key (expected entity type @type): @info', [
      '@key' => $context_key,
      '@type' => $entity_type_id,
      '@info' => $info,
    ]);
  }

  /**
   * @param array<string, scalar|null> $log_context
   */
  private function logReplayDiscard(string $context_key, string $entity_type_id, mixed $value, array $log_context): void {
    $info = is_object($value) ? get_debug_type($value) : gettype($value);
    $this->logger->warning('Discarded invalid entity_autocomplete replay value for @key (expected entity type @type): @info section=@section event=@event uid=@uid field=@field payload_type=@payload_type', [
      '@key' => $context_key,
      '@type' => $entity_type_id,
      '@info' => $info,
      '@section' => (string) ($log_context['section'] ?? 'unknown'),
      '@event' => (string) ($log_context['event_id'] ?? 'unknown'),
      '@uid' => (string) ($log_context['uid'] ?? 'unknown'),
      '@field' => (string) ($log_context['field_key'] ?? $context_key),
      '@payload_type' => (string) ($log_context['payload_type'] ?? $info),
    ]);
  }

}
