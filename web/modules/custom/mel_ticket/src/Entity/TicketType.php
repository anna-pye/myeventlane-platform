<?php

declare(strict_types=1);

namespace Drupal\mel_ticket\Entity;

use Drupal\commerce_price\Price;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Entity\EntityStorageException;

/**
 * Ticket type (RSVP, paid, or external) owned by a vendor user.
 *
 * @ContentEntityType(
 *   id = "mel_ticket_type",
 *   label = @Translation("Ticket type"),
 *   label_collection = @Translation("Ticket types"),
 *   label_singular = @Translation("ticket type"),
 *   label_plural = @Translation("ticket types"),
 *   label_count = @PluralTranslation(
 *     singular = "@count ticket type",
 *     plural = "@count ticket types",
 *   ),
 *   handlers = {
 *     "access" = "Drupal\mel_ticket\Access\TicketTypeAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\mel_ticket\Form\TicketTypeForm",
 *       "add" = "Drupal\mel_ticket\Form\TicketTypeForm",
 *       "edit" = "Drupal\mel_ticket\Form\TicketTypeForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider",
 *     },
 *     "views_data" = "Drupal\views\EntityViewsData",
 *   },
 *   base_table = "mel_ticket_type",
 *   admin_permission = "administer mel_ticket_type entities",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "title",
 *     "langcode" = "langcode",
 *     "published" = "status",
 *   },
 *   links = {
 *     "canonical" = "/ticket-type/{mel_ticket_type}",
 *     "edit-form" = "/ticket-type/{mel_ticket_type}/edit",
 *     "delete-form" = "/ticket-type/{mel_ticket_type}/delete",
 *   },
 * )
 */
final class TicketType extends ContentEntityBase implements TicketTypeInterface {

  use EntityPublishedTrait;

  /**
   * {@inheritdoc}
   */
  public function getTitle(): string {
    return (string) $this->get('title')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getTicketKind(): string {
    return (string) $this->get('ticket_kind')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isReusable(): bool {
    return (bool) $this->get('is_reusable')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function toPriceValue(): ?Price {
    $field = $this->get('price');
    if ($field->isEmpty()) {
      return NULL;
    }
    /** @var \Drupal\commerce_price\Plugin\Field\FieldType\PriceItem $item */
    $item = $field->first();
    $number = (string) $item->number;
    $code = (string) $item->currency_code;
    if ($number === '' || $code === '') {
      return NULL;
    }
    return new Price($number, $code);
  }

  /**
   * {@inheritdoc}
   */
  public function getExternalUrlString(): ?string {
    if ($this->get('external_url')->isEmpty()) {
      return NULL;
    }
    /** @var \Drupal\link\Plugin\Field\FieldType\LinkItem $item */
    $item = $this->get('external_url')->first();
    return $item->getUri();
  }

  /**
   * {@inheritdoc}
   */
  public function getLifecycleStatus(): string {
    $value = (string) ($this->get('lifecycle_status')->value ?? '');
    return $value !== '' ? $value : self::LIFECYCLE_ACTIVE;
  }

  /**
   * {@inheritdoc}
   */
  public function isArchived(): bool {
    return $this->getLifecycleStatus() === self::LIFECYCLE_ARCHIVED;
  }

  /**
   * {@inheritdoc}
   */
  public function isDefaultTicket(): bool {
    return $this->hasField('field_is_default_ticket')
      && (bool) $this->get('field_is_default_ticket')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isBestValueTicket(): bool {
    return $this->hasField('field_is_best_value')
      && (bool) $this->get('field_is_best_value')->value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setDescription(t('Public ticket name (e.g. "General admission").'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -10,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['short_description'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Short description'))
      ->setDescription(t('Optional text shown to buyers (about two lines). Plain text.'))
      ->setRequired(FALSE)
      ->setSetting('max_length', 320)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => -5,
        'settings' => [
          'rows' => 2,
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -9,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['ticket_kind'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Ticket type'))
      ->setDescription(t('RSVP (free reservation), paid (Commerce-backed), or external link-out.'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        'rsvp' => 'RSVP',
        'paid' => 'Paid',
        'external' => 'External',
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => -9,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['price'] = BaseFieldDefinition::create('commerce_price')
      ->setLabel(t('Price'))
      ->setDescription(t('Required for paid tickets; source of truth is mirrored on the Commerce variation.'))
      ->setRequired(FALSE)
      ->setCardinality(1)
      ->setDisplayOptions('form', [
        'type' => 'commerce_price_default',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['capacity'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Capacity'))
      ->setDescription(t('Leave empty for unlimited tickets'))
      ->setRequired(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['rsvp_limit'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('RSVP limit per booking'))
      ->setDescription(t('Optional per-order cap for RSVP tickets.'))
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['is_reusable'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Reusable across events'))
      ->setDescription(t('When enabled, this ticket is not tied to a single event entity.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_is_default_ticket'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Set as recommended ticket'))
      ->setDescription(t('This ticket will be highlighted to buyers and selected by default.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['field_is_best_value'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Mark as best value'))
      ->setDescription(t('Shows a buyer-facing Best value badge. Only one ticket per event should be marked best value.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['field_use_ticket_attendee_questions'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Add custom questions for this ticket'))
      ->setDescription(t('Optional. Event-level attendee questions still apply; enable this only when this tier needs extra questions.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 28,
        'settings' => [
          'display_label' => TRUE,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['field_attendee_questions'] = BaseFieldDefinition::create('entity_reference_revisions')
      ->setLabel(t('Attendee questions for this ticket'))
      ->setDescription(t('Paragraphs (attendee_extra_field) asked in addition to event-level questions for this tier.'))
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setSetting('target_type', 'paragraph')
      ->setSetting('handler', 'default:paragraph')
      ->setSetting('handler_settings', [
        'target_bundles' => [
          'attendee_extra_field' => 'attendee_extra_field',
        ],
        'negate' => 0,
        'target_bundles_drag_drop' => [
          'attendee_extra_field' => [
            'enabled' => TRUE,
            'weight' => 0,
          ],
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'paragraphs',
        'weight' => 29,
        'settings' => [
          'title' => 'Question',
          'title_plural' => 'Questions',
          'edit_mode' => 'closed',
          'closed_mode' => 'summary',
          'autocollapse' => 'none',
          'closed_mode_threshold' => 0,
          'add_mode' => 'dropdown',
          'form_display_mode' => 'default',
          'default_paragraph_type' => 'attendee_extra_field',
          'features' => [
            'add_above' => '0',
            'collapse_edit_all' => 'collapse_edit_all',
            'duplicate' => 'duplicate',
          ],
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_revisions_entity_view',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['vendor_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Vendor'))
      ->setDescription(t('User that owns this ticket definition.'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 5,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['event'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Event'))
      ->setDescription(t('Optional: event this non-reusable ticket was created for.'))
      ->setSetting('target_type', 'node')
      ->setSetting('handler_settings', ['target_bundles' => ['event' => 'event']])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 6,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['external_url'] = BaseFieldDefinition::create('link')
      ->setLabel(t('External URL'))
      ->setDescription(t('Required for external tickets.'))
      ->setDisplayOptions('form', [
        'type' => 'link_default',
        'weight' => 7,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['sale_start'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Sale start'))
      ->setSetting('datetime_type', 'datetime')
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
        'weight' => 8,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['sale_end'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Sale end'))
      ->setSetting('datetime_type', 'datetime')
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
        'weight' => 9,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['commerce_variation'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Commerce variation'))
      ->setDescription(t('Synced product variation for paid tickets.'))
      ->setSetting('target_type', 'commerce_product_variation')
      ->setSetting('handler_settings', ['target_bundles' => ['ticket_variation' => 'ticket_variation']])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['template_source'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Source template'))
      ->setDescription(t('If set, this row was created by cloning a reusable template; the template entity is unchanged.'))
      ->setSetting('target_type', 'mel_ticket_type')
      ->setSetting('handler', 'default')
      ->setRequired(FALSE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['visibility_mode'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Visibility'))
      ->setDescription(t('Public tiers appear on the standard booking page. Hidden, access-code, and group-only tiers stay off the public list until access rules are satisfied.'))
      ->setRequired(TRUE)
      ->setDefaultValue('public')
      ->setSetting('allowed_values', [
        'public' => 'Public',
        'hidden' => 'Hidden',
        'access_code' => 'Access code',
        'group_only' => 'Group / partner only',
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['hidden_label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Internal label'))
      ->setDescription(t('Optional organiser-only note (not shown to buyers).'))
      ->setRequired(FALSE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 21,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['waitlist_enabled'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Waitlist when sold out'))
      ->setDescription(t('When the tier is sold out, buyers can join a waitlist instead of purchasing.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 22,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['waitlist_capacity'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Waitlist capacity'))
      ->setDescription(t('Maximum number of waiting entries; leave empty for no cap.'))
      ->setRequired(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 23,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['auto_promote_waitlist'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Auto-offer waitlist when tickets free up'))
      ->setDescription(t('When capacity becomes available, the next waiters receive a time-limited offer to purchase.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 24,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['group_sale_mode'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Group sales mode'))
      ->setDescription(t('Enforces quantity rules on paid tiers at checkout (server-side).'))
      ->setRequired(TRUE)
      ->setDefaultValue('none')
      ->setSetting('allowed_values', [
        'none' => 'None (any quantity)',
        'fixed_bundle' => 'Fixed bundle (e.g. table of N)',
        'minimum_group_size' => 'Minimum group size',
        'reserved_block' => 'Reserved block / partner allocation',
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 25,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['group_min_size'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Minimum group size'))
      ->setDescription(t('Minimum tickets per line item when using minimum group size mode.'))
      ->setRequired(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 26,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['group_bundle_size'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Bundle / block size'))
      ->setDescription(t('For fixed bundle: quantity must be a multiple of this value. For reserved block, use with group-only visibility and access codes.'))
      ->setRequired(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 27,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['lifecycle_status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Lifecycle status'))
      ->setDescription(t('Internal lifecycle state. Archived tickets are excluded from pricing, booking, and Commerce sync.'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::LIFECYCLE_ACTIVE)
      ->setSetting('allowed_values', [
        self::LIFECYCLE_ACTIVE => 'Active',
        self::LIFECYCLE_ARCHIVED => 'Archived',
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Published'))
      ->setDefaultValue(TRUE)
      ->setDisplayConfigurable('form', FALSE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage, array &$values) {
    parent::preCreate($storage, $values);
    if (empty($values['status'])) {
      $values['status'] = 1;
    }
    if (empty($values['lifecycle_status'])) {
      $values['lifecycle_status'] = self::LIFECYCLE_ACTIVE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    $this->normalizeNullableCapacity();

    $kind = (string) $this->get('ticket_kind')->value;
    if ($kind === 'external') {
      $this->set('capacity', NULL);
      $this->set('rsvp_limit', NULL);
      $this->set('price', NULL);
    }
    if ($kind === 'rsvp') {
      $this->set('price', NULL);
      $this->set('external_url', NULL);
    }
    if ($kind === 'paid') {
      $this->set('external_url', NULL);
      // Invariant: paid tiers always have a Commerce variation per event product.
      // The entity form may submit is_reusable from the checkbox; normalize here.
      $this->set('is_reusable', FALSE);
    }
    if ($this->isReusable() && $this->hasField('field_is_default_ticket')) {
      $this->set('field_is_default_ticket', FALSE);
    }
    if ($this->isReusable() && $this->hasField('field_is_best_value')) {
      $this->set('field_is_best_value', FALSE);
    }
    parent::preSave($storage);
    $this->assertBusinessRules();
  }

  /**
   * Validates RSVP / paid / external dependency rules.
   */
  private function assertBusinessRules(): void {
    $kind = $this->getTicketKind();
    $violations = [];

    if ($this->hasField('sale_start') && $this->hasField('sale_end')
        && !$this->get('sale_start')->isEmpty() && !$this->get('sale_end')->isEmpty()) {
      $start = $this->get('sale_start')->date->getTimestamp();
      $end = $this->get('sale_end')->date->getTimestamp();
      if ($end <= $start) {
        $violations[] = 'Sale end must be after sale start.';
      }
    }

    switch ($kind) {
      case 'paid':
        if ($this->isReusable()) {
          $violations[] = 'Paid tickets cannot be marked reusable: Commerce variations are created per event product. Use RSVP or external reusable tickets, or create a paid ticket per event.';
        }
        if ($this->toPriceValue() === NULL) {
          $violations[] = 'Paid tickets must include a price.';
        }
        if (!$this->get('price')->isEmpty()) {
          $price = $this->toPriceValue();
          if ($price && (float) $price->getNumber() < 0) {
            $violations[] = 'Paid ticket price cannot be negative.';
          }
        }
        if (!$this->get('external_url')->isEmpty()) {
          $violations[] = 'External URL must be empty for paid tickets.';
        }
        $this->validateCapacityWhenSet($violations);
        $this->assertGroupSaleRules($violations);
        break;

      case 'rsvp':
        if (!$this->get('price')->isEmpty()) {
          $violations[] = 'RSVP tickets cannot store a price; use paid instead.';
        }
        if (!$this->get('external_url')->isEmpty()) {
          $violations[] = 'External URL must be empty for RSVP tickets.';
        }
        $this->validateCapacityWhenSet($violations);
        if (!$this->get('commerce_variation')->isEmpty()) {
          $violations[] = 'RSVP tickets must not reference a Commerce variation.';
        }
        break;

      case 'external':
        $uri = $this->getExternalUrlString();
        if ($uri === NULL || $uri === '') {
          $violations[] = 'External tickets must include an external URL.';
        }
        elseif (!str_starts_with($uri, 'http://') && !str_starts_with($uri, 'https://')) {
          $violations[] = 'External URL must be a valid http(s) URL.';
        }
        if (!$this->get('price')->isEmpty()) {
          $violations[] = 'External tickets cannot store a price.';
        }
        if (!$this->get('commerce_variation')->isEmpty()) {
          $violations[] = 'External tickets must not reference a Commerce variation.';
        }
        break;

      default:
        $violations[] = 'Invalid ticket type.';
        break;
    }

    if ($violations) {
      throw new EntityStorageException(implode(' ', $violations));
    }
  }

  /**
   * Ensures capacity is either empty for unlimited or at least 1.
   *
   * @param array<string> $violations
   *   Violation messages to append to.
   */
  private function validateCapacityWhenSet(array &$violations): void {
    $value = $this->get('capacity')->value;
    if ($value === NULL) {
      return;
    }
    $raw = trim((string) $value);
    if ($raw === '') {
      return;
    }
    if (!preg_match('/^\d+$/', $raw) || (int) $raw < 1) {
      $violations[] = 'Capacity must be empty for unlimited or at least 1.';
    }
  }

  /**
   * Stores empty capacity as NULL so empty UI input means unlimited.
   */
  private function normalizeNullableCapacity(): void {
    if (!$this->hasField('capacity')) {
      return;
    }
    $value = $this->get('capacity')->value;
    if ($value === NULL || trim((string) $value) === '') {
      $this->set('capacity', NULL);
    }
  }

  /**
   * Validates group sale mode dependencies.
   *
   * @param array<string> $violations
   */
  private function assertGroupSaleRules(array &$violations): void {
    if (!$this->hasField('group_sale_mode')) {
      return;
    }
    $mode = (string) ($this->get('group_sale_mode')->value ?? 'none');
    switch ($mode) {
      case 'none':
        break;

      case 'fixed_bundle':
      case 'reserved_block':
        if ($this->get('group_bundle_size')->isEmpty() || (int) $this->get('group_bundle_size')->value < 2) {
          $violations[] = 'Bundle / block size must be at least 2 for this group mode.';
        }
        break;

      case 'minimum_group_size':
        if ($this->get('group_min_size')->isEmpty() || (int) $this->get('group_min_size')->value < 2) {
          $violations[] = 'Minimum group size must be at least 2.';
        }
        break;

      default:
        $violations[] = 'Invalid group sales mode.';
        break;
    }
  }

}
