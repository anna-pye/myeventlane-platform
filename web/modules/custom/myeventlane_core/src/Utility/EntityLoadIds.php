<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Utility;

use Drupal\myeventlane_core\Service\EntityIdNormalizer;

/**
 * @deprecated in favour of injecting myeventlane_core.entity_id_normalizer.
 *
 * Normalizes entity ID lists before EntityStorageBase::loadMultiple().
 * Delegates to {@see EntityIdNormalizer::normalizeIterable()}.
 */
final class EntityLoadIds {

  /**
   * @param iterable<int|string, mixed> $ids
   *   Raw IDs (e.g. from entity queries or merged arrays).
   *
   * @return list<int|string>
   *   Unique IDs safe for loadMultiple(); numeric strings become integers.
   */
  public static function normalizeForLoadMultiple(iterable $ids): array {
    return EntityIdNormalizer::normalizeIterable($ids);
  }

}
