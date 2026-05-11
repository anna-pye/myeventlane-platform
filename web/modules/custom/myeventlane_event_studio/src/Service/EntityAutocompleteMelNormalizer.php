<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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

  private function logDiscard(string $context_key, string $entity_type_id, mixed $value): void {
    $info = is_object($value) ? get_debug_type($value) : gettype($value);
    $this->logger->warning('Discarded invalid entity_autocomplete default for @key (expected entity type @type): @info', [
      '@key' => $context_key,
      '@type' => $entity_type_id,
      '@info' => $info,
    ]);
  }

}
