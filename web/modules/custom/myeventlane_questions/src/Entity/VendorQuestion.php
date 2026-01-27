<?php

declare(strict_types=1);

namespace Drupal\myeventlane_questions\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_store\Entity\StoreInterface;

/**
 * Defines the VendorQuestion entity.
 *
 * Store-based question templates that can be cloned into paragraph instances
 * for use in event attendee questions.
 *
 * @ContentEntityType(
 *   id = "vendor_question",
 *   label = @Translation("Vendor Question"),
 *   label_collection = @Translation("Vendor Questions"),
 *   label_singular = @Translation("vendor question"),
 *   label_plural = @Translation("vendor questions"),
 *   label_count = @PluralTranslation(
 *     singular = "@count vendor question",
 *     plural = "@count vendor questions"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\myeventlane_questions\VendorQuestionListBuilder",
 *     "access" = "Drupal\myeventlane_questions\Entity\VendorQuestionAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\myeventlane_questions\Form\VendorQuestionForm",
 *       "add" = "Drupal\myeventlane_questions\Form\VendorQuestionForm",
 *       "edit" = "Drupal\myeventlane_questions\Form\VendorQuestionForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   base_table = "vendor_question",
 *   data_table = "vendor_question_field_data",
 *   admin_permission = "administer site configuration",
 *   field_ui_base_route = "myeventlane_questions.library",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "status" = "status"
 *   },
 *   links = {
 *     "collection" = "/vendor/questions",
 *     "add-form" = "/vendor/questions/add",
 *     "edit-form" = "/vendor/questions/{vendor_question}/edit",
 *     "delete-form" = "/vendor/questions/{vendor_question}/delete"
 *   }
 * )
 */
class VendorQuestion extends ContentEntityBase implements VendorQuestionInterface, EntityChangedInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    // Ensure store is set.
    if ($this->isNew() && $this->get('field_store')->isEmpty()) {
      // Try to get store from current user's vendor.
      $current_user = \Drupal::currentUser();
      if ($current_user->isAuthenticated()) {
        $vendor_storage = \Drupal::entityTypeManager()->getStorage('myeventlane_vendor');
        $vendor_ids = $vendor_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('uid', $current_user->id())
          ->range(0, 1)
          ->execute();

        if (!empty($vendor_ids)) {
          $vendor = $vendor_storage->load(reset($vendor_ids));
          if ($vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
            $store = $vendor->get('field_vendor_store')->entity;
            if ($store instanceof StoreInterface) {
              $this->set('field_store', $store);
            }
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Label field (question text).
    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Question Label'))
      ->setDescription(new TranslatableMarkup('The question text shown to attendees.'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -10,
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Question type (textfield, select, checkbox, textarea).
    $fields['field_question_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Question Type'))
      ->setDescription(new TranslatableMarkup('The type of form field to display.'))
      ->setRequired(TRUE)
      ->setSettings([
        'allowed_values' => [
          'textfield' => 'Text field',
          'textarea' => 'Textarea',
          'select' => 'Select (dropdown)',
          'checkbox' => 'Checkbox',
        ],
      ])
      ->setDefaultValue('textfield')
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'list_default',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Options (for select/checkbox types).
    $fields['field_options'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Options'))
      ->setDescription(new TranslatableMarkup('For select/checkbox fields, enter one option per line.'))
      ->setRequired(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 1,
        'settings' => [
          'rows' => 5,
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'text_default',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Help text.
    $fields['field_help_text'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Help Text'))
      ->setDescription(new TranslatableMarkup('Optional help text shown below the question field.'))
      ->setRequired(FALSE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 2,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Required flag.
    $fields['field_required'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Required'))
      ->setDescription(new TranslatableMarkup('Whether this question must be answered.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 3,
        'settings' => [
          'display_label' => TRUE,
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'boolean',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Store reference (REQUIRED).
    $fields['field_store'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Store'))
      ->setDescription(new TranslatableMarkup('The Commerce store this question belongs to.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'commerce_store')
      ->setSetting('handler', 'default:commerce_store')
      ->setSetting('handler_settings', [
        'target_bundles' => ['online'],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 10,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Status (enabled/disabled).
    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setDescription(new TranslatableMarkup('Whether this question template is enabled and available for use.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 20,
        'settings' => [
          'display_label' => TRUE,
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'boolean',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Created timestamp.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('The time that the question was created.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 30,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Changed timestamp.
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('The time that the question was last updated.'))
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return $this->get('label')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function setLabel(string $label): static {
    $this->set('label', $label);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestionType(): string {
    return $this->get('field_question_type')->value ?? 'textfield';
  }

  /**
   * {@inheritdoc}
   */
  public function setQuestionType(string $type): static {
    $this->set('field_question_type', $type);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getOptions(): string {
    return $this->get('field_options')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function setOptions(string $options): static {
    $this->set('field_options', $options);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getHelpText(): string {
    return $this->get('field_help_text')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function setHelpText(string $help_text): static {
    $this->set('field_help_text', $help_text);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isRequired(): bool {
    return (bool) ($this->get('field_required')->value ?? FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function setRequired(bool $required): static {
    $this->set('field_required', $required);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getStore(): ?StoreInterface {
    $store = $this->get('field_store')->entity;
    return $store instanceof StoreInterface ? $store : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setStore(StoreInterface $store): static {
    $this->set('field_store', $store);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isEnabled(): bool {
    return (bool) ($this->get('status')->value ?? TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function setEnabled(bool $enabled): static {
    $this->set('status', $enabled);
    return $this;
  }

}
