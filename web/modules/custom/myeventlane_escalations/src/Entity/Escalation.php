<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\UserInterface;

/**
 * Defines the Escalation entity.
 *
 * @ContentEntityType(
 *   id = "escalation",
 *   label = @Translation("Escalation"),
 *   label_collection = @Translation("Escalations"),
 *   label_singular = @Translation("escalation"),
 *   label_plural = @Translation("escalations"),
 *   label_count = @PluralTranslation(
 *     singular = "@count escalation",
 *     plural = "@count escalations",
 *   ),
 *   base_table = "escalation",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "uid" = "user_id",
 *     "label" = "subject",
 *     "status" = "status",
 *     "created" = "created",
 *     "changed" = "changed",
 *   },
 *   admin_permission = "administer escalations",
 *   handlers = {
 *     "list_builder" = "Drupal\myeventlane_escalations\EscalationListBuilder",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "form" = {
 *       "default" = "Drupal\myeventlane_escalations\Form\EscalationForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   links = {
 *     "canonical" = "/admin/myeventlane/escalations/{escalation}",
 *     "add-form" = "/admin/myeventlane/escalations/add",
 *     "edit-form" = "/admin/myeventlane/escalations/{escalation}/edit",
 *     "delete-form" = "/admin/myeventlane/escalations/{escalation}/delete",
 *     "collection" = "/admin/myeventlane/escalations",
 *   },
 *   field_ui_base_route = "entity.escalation.collection",
 * )
 */
final class Escalation extends ContentEntityBase implements EscalationInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['subject'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Subject'))
      ->setDescription(new TranslatableMarkup('The escalation subject line.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDescription(new TranslatableMarkup('Detailed description of the issue.'))
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'text_default',
        'weight' => 1,
      ])
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Type'))
      ->setDescription(new TranslatableMarkup('Type of escalation.'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        'payment' => 'Payment Issue',
        'refund' => 'Refund Request',
        'ticket' => 'Ticket Issue',
        'event' => 'Event Related',
        'vendor' => 'Vendor Dispute',
        'customer' => 'Customer Complaint',
        'technical' => 'Technical Issue',
        'other' => 'Other',
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'list_default',
        'weight' => 2,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setDescription(new TranslatableMarkup('Current status of the escalation.'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        'new' => 'New',
        'in_progress' => 'In Progress',
        'waiting_vendor' => 'Waiting for Vendor',
        'waiting_customer' => 'Waiting for Customer',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
      ])
      ->setDefaultValue('new')
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'list_default',
        'weight' => 3,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['priority'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Priority'))
      ->setDescription(new TranslatableMarkup('Priority level of the escalation.'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
      ])
      ->setDefaultValue('normal')
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'list_default',
        'weight' => 4,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Customer'))
      ->setDescription(new TranslatableMarkup('The customer who submitted the escalation.'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'author',
        'weight' => 5,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 5,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['vendor_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Vendor'))
      ->setDescription(new TranslatableMarkup('The vendor involved in the escalation.'))
      ->setSetting('target_type', 'myeventlane_vendor')
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => 6,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 6,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['event_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Event'))
      ->setDescription(new TranslatableMarkup('The event related to this escalation.'))
      ->setSetting('target_type', 'node')
      ->setSetting('handler_settings', ['target_bundles' => ['event' => 'event']])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => 7,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 7,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['order_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Order'))
      ->setDescription(new TranslatableMarkup('The order related to this escalation, if applicable.'))
      ->setSetting('target_type', 'commerce_order')
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => 8,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 8,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['notes'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Admin Notes'))
      ->setDescription(new TranslatableMarkup('Internal notes and updates.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
        'weight' => 9,
      ])
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 9,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['resolved_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Resolved At'))
      ->setDescription(new TranslatableMarkup('When the escalation was resolved.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('The time that the escalation was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('The time that the escalation was last edited.'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getSubject(): string {
    return $this->get('subject')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setSubject(string $subject): EscalationInterface {
    $this->set('subject', $subject);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setStatus(string $status): EscalationInterface {
    $this->set('status', $status);
    if ($status === 'resolved' || $status === 'closed') {
      $this->set('resolved_at', \Drupal::time()->getRequestTime());
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPriority(): string {
    return $this->get('priority')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setPriority(string $priority): EscalationInterface {
    $this->set('priority', $priority);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCustomer(): ?UserInterface {
    return $this->get('user_id')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function setCustomer(UserInterface $account): EscalationInterface {
    $this->set('user_id', $account->id());
    return $this;
  }

}
















