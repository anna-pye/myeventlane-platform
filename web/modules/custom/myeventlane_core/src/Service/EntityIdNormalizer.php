<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

/**
 * Normalizes entity ID lists before EntityStorageBase::loadMultiple().
 *
 * Drupal core uses array_flip() on ID arrays; values must be int|string. This
 * service strips invalid shapes (nested field rows, NULLs) and returns unique
 * scalar IDs. Prefer dependency injection; {@see normalizeIterable()} exists
 * for legacy procedural callers (e.g. theme files).
 */
final class EntityIdNormalizer {

  /**
   * Normalizes arbitrary entity ID inputs (nodes, terms, custom entities).
   *
   * @param array<int|string, mixed> $raw
   *   Mixed list: scalars, or rows with target_id / nid / id.
   *
   * @return list<int|string>
   *   Unique IDs safe for loadMultiple(); numeric strings become integers.
   */
  public function normalizeEntityIds(array $raw): array {
    return self::normalizeRawList($raw);
  }

  /**
   * Normalizes node IDs (same rules as {@see normalizeEntityIds()}, named for call sites).
   *
   * @param array<int|string, mixed> $raw
   *   Mixed list from queries, forms, or merged arrays.
   *
   * @return list<int>
   *   Unique positive integer node IDs where possible.
   */
  public function normalizeNodeIds(array $raw): array {
    // Key by int so duplicates (and int/string "123" pairs from callers) collapse.
    // loadMultiple() uses array_flip() on IDs; duplicates and non int|string values warn.
    $byId = [];
    foreach (self::normalizeRawList($raw) as $id) {
      $nid = NULL;
      if (is_int($id) && $id > 0) {
        $nid = $id;
      }
      elseif (is_string($id) && preg_match('/^\d+$/', $id) === 1) {
        $v = (int) $id;
        if ($v > 0) {
          $nid = $v;
        }
      }
      elseif (is_float($id) && is_finite($id) && (float) (int) $id === $id) {
        $v = (int) $id;
        if ($v > 0) {
          $nid = $v;
        }
      }
      if ($nid !== NULL) {
        $byId[$nid] = $nid;
      }
    }
    return array_values($byId);
  }

  /**
   * Normalizes Commerce order IDs (and similar numeric content entities).
   *
   * @param array<int|string, mixed> $raw
   *   Mixed list from queries or order item dereferencing.
   *
   * @return list<int>
   *   Unique positive integer IDs.
   */
  public function normalizeOrderIds(array $raw): array {
    return $this->normalizeNodeIds($raw);
  }

  /**
   * Normalizes IDs from an iterable (entity query results, generators).
   *
   * @param iterable<int|string, mixed> $raw
   *
   * @return list<int|string>
   */
  public static function normalizeIterable(iterable $raw): array {
    if (is_array($raw)) {
      return self::normalizeRawList($raw);
    }
    return self::normalizeRawList(iterator_to_array($raw, FALSE));
  }

  /**
   * @param array<int|string, mixed> $raw
   *
   * @return list<int|string>
   */
  private static function normalizeRawList(array $raw): array {
    $out = [];
    foreach ($raw as $item) {
      $id = self::extractScalarId($item);
      if ($id === NULL) {
        continue;
      }
      if (is_int($id)) {
        $out[] = $id;
        continue;
      }
      if (is_float($id)) {
        if (!is_finite($id)) {
          continue;
        }
        $asInt = (int) $id;
        if ((float) $asInt === $id) {
          $out[] = $asInt;
        }
        continue;
      }
      if (is_string($id)) {
        if ($id === '') {
          continue;
        }
        if (preg_match('/^-?\d+$/', $id) === 1) {
          $out[] = (int) $id;
        }
        else {
          $out[] = $id;
        }
      }
    }

    $out = array_values(array_unique($out, SORT_REGULAR));
    $sanitized = [];
    foreach ($out as $item) {
      if (is_int($item)) {
        $sanitized[] = $item;
      }
      elseif (is_string($item)) {
        $sanitized[] = $item;
      }
      elseif (is_float($item) && is_finite($item) && (float) (int) $item === $item) {
        $sanitized[] = (int) $item;
      }
    }

    return array_values(array_unique($sanitized, SORT_REGULAR));
  }

  /**
   * @param mixed $item
   *   Scalar or one-level array with target_id, nid, or id.
   *
   * @return float|int|string|null
   *   Extracted scalar for normalization, or NULL to skip.
   */
  private static function extractScalarId(mixed $item): float|int|string|null {
    if (is_array($item)) {
      if (array_key_exists('target_id', $item)) {
        $item = $item['target_id'];
      }
      elseif (array_key_exists('nid', $item)) {
        $item = $item['nid'];
      }
      elseif (array_key_exists('id', $item)) {
        $item = $item['id'];
      }
      else {
        return NULL;
      }
    }
    if ($item === NULL || $item === '') {
      return NULL;
    }
    if (is_array($item) || is_object($item)) {
      return NULL;
    }
    if (is_resource($item)) {
      return NULL;
    }
    if (is_bool($item)) {
      return NULL;
    }
    if (is_int($item) || is_float($item) || is_string($item)) {
      return $item;
    }
    return NULL;
  }

}
