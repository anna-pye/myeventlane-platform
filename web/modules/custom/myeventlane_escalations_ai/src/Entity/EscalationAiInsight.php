<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_ai\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\myeventlane_escalations_ai\EscalationAiInsightInterface;

/**
 * Stores AI-generated suggestions for an escalation.
 *
 * Append-only by convention: updates/deletes are denied by access handler.
 *
 * @ContentEntityType(
 *   id = "escalation_ai_insight",
 *   label = @Translation("Escalation AI Insight"),
 *   label_collection = @Translation("Escalation AI Insights"),
 *   base_table = "escalation_ai_insight",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "created" = "created",
 *     "changed" = "changed"
 *   },
 *   handlers = {
 *     "access" = "Drupal\myeventlane_escalations_ai\EscalationAiInsightAccessControlHandler",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *   },
 *   admin_permission = "view escalation ai insights",
 * )
 */
final class EscalationAiInsight extends ContentEntityBase implements EscalationAiInsightInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['escalation_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Escalation'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'escalation');

    $fields['insight_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Insight type'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        'triage' => 'Triage',
        'reply_suggestion' => 'Reply suggestion',
        'summary' => 'Summary',
        'risk_flag' => 'Risk flag',
        'breach_soon' => 'Breach soon advisory',
      ]);

    $fields['confidence'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Confidence'))
      ->setDescription(new TranslatableMarkup('0-1, or provider-specific confidence string.'))
      ->setSetting('max_length', 16)
      ->setDefaultValue('0');

    $fields['is_internal'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Internal'))
      ->setDefaultValue(TRUE);

    $fields['payload_json'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Payload (JSON/text)'))
      ->setRequired(TRUE);

    $fields['prompt_key'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Prompt key'))
      ->setSetting('max_length', 128)
      ->setDefaultValue('');

    $fields['prompt_version'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Prompt version'))
      ->setSetting('max_length', 32)
      ->setDefaultValue('v1');

    $fields['provider'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Provider'))
      ->setSetting('max_length', 64)
      ->setDefaultValue('');

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setSetting('allowed_values', [
        'ok' => 'OK',
        'error' => 'Error',
      ])
      ->setDefaultValue('ok');

    $fields['error_message'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Error message'))
      ->setDefaultValue('');

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}
