<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the AI Job entity.
 *
 * Stores job metadata and results. Does NOT store prompts or raw context.
 *
 * @ContentEntityType(
 *   id = "ai_job",
 *   label = @Translation("AI Job"),
 *   label_collection = @Translation("AI Jobs"),
 *   label_singular = @Translation("ai job"),
 *   label_plural = @Translation("ai jobs"),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "access" = "Drupal\myeventlane_ai\Entity\AiJobAccessControlHandler",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *   },
 *   base_table = "ai_job",
 *   admin_permission = "administer ai jobs",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "owner" = "uid",
 *   },
 *   links = {
 *     "canonical" = "/ai/job/{ai_job}",
 *   }
 * )
 */
final class AiJob extends ContentEntityBase implements EntityOwnerInterface {

  use EntityOwnerTrait;

  public const STATUS_QUEUED = 'queued';
  public const STATUS_RUNNING = 'running';
  public const STATUS_DONE = 'done';
  public const STATUS_ERROR = 'error';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Owner'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner')
      ->setReadOnly(TRUE);

    $fields['vendor_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Vendor ID'))
      ->setSetting('size', 'normal')
      ->setReadOnly(TRUE);

    $fields['scope'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Scope'))
      ->setSettings(['max_length' => 255])
      ->setReadOnly(TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setSetting('allowed_values', [
        self::STATUS_QUEUED => 'Queued',
        self::STATUS_RUNNING => 'Running',
        self::STATUS_DONE => 'Done',
        self::STATUS_ERROR => 'Error',
      ])
      ->setDefaultValue(self::STATUS_QUEUED)
      ->setRequired(TRUE);

    $fields['prompt_key'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Prompt key'))
      ->setSettings(['max_length' => 128])
      ->setReadOnly(TRUE);

    $fields['prompt_version'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Prompt version'))
      ->setSettings(['max_length' => 32])
      ->setReadOnly(TRUE);

    $fields['prompt_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Prompt hash'))
      ->setSettings(['max_length' => 64])
      ->setRequired(TRUE)
      ->setReadOnly(TRUE);

    $fields['result_text'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Result text'))
      ->setReadOnly(TRUE);

    $fields['error_message'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Error message'))
      ->setSettings(['max_length' => 255])
      ->setReadOnly(TRUE);

    $fields['model'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Model'))
      ->setSettings(['max_length' => 64])
      ->setReadOnly(TRUE);

    $fields['token_counts'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Token counts (JSON)'))
      ->setReadOnly(TRUE);

    $fields['estimated_cost_usd'] = BaseFieldDefinition::create('float')
      ->setLabel(t('Estimated cost USD'))
      ->setReadOnly(TRUE);

    $fields['expires_at'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Expires at'))
      ->setDescription(t('Unix timestamp for cleanup later.'))
      ->setSetting('size', 'big');

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

}
