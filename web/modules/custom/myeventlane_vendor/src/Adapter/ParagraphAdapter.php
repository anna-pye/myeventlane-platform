<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Adapter;

use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Placeholder adapter for paragraph-backed Studio fields.
 *
 * This adapter is intentionally schema-only for now. Save normalization is not
 * enabled until dedicated paragraph mutation flows are implemented.
 */
final class ParagraphAdapter implements FieldSchemaAdapterInterface {

  /**
   * {@inheritdoc}
   */
  public function supports(FieldDefinitionInterface $definition): bool {
    $field_type = (string) $definition->getType();
    $target_type = (string) ($definition->getSetting('target_type') ?? '');

    return $field_type === 'entity_reference_revisions'
      || ($field_type === 'entity_reference' && $target_type === 'paragraph');
  }

  /**
   * {@inheritdoc}
   */
  public function buildSchemaMetadata(string $fieldName, FieldDefinitionInterface $definition): array {
    return [
      'adapter' => 'paragraph',
      'adapter_key' => 'paragraph_' . $fieldName,
      'supported' => FALSE,
      'unsupported_reason' => 'paragraph_reference',
      'input_mode' => 'adapter_required',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeInboundValue(string $fieldName, FieldDefinitionInterface $definition, mixed $value): mixed {
    throw new \LogicException(sprintf(
      'Paragraph adapter normalization is not enabled for field "%s".',
      $fieldName
    ));
  }

}
