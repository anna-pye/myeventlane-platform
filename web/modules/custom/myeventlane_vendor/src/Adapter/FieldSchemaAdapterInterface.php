<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Adapter;

use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Contract for Studio field-schema adapters.
 *
 * Adapters can expose custom schema metadata for complex field types and
 * provide a dedicated normalization path for save payloads.
 */
interface FieldSchemaAdapterInterface {

  /**
   * Returns TRUE when the adapter supports the field definition.
   */
  public function supports(FieldDefinitionInterface $definition): bool;

  /**
   * Builds adapter metadata for schema responses.
   *
   * @return array<string, mixed>
   *   Adapter metadata payload.
   */
  public function buildSchemaMetadata(string $fieldName, FieldDefinitionInterface $definition): array;

  /**
   * Normalizes incoming payload for a supported field.
   *
   * @throws \LogicException
   *   When normalization is not enabled.
   */
  public function normalizeInboundValue(string $fieldName, FieldDefinitionInterface $definition, mixed $value): mixed;

}
