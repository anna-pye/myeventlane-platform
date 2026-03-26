<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Entity;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Vendor entity.
 *
 * Vendors represent event organisers in MyEventLane. Each vendor can be
 * linked to a Commerce store and has a public-facing page at /vendor/{id}.
 *
 * @ContentEntityType(
 *   id = "myeventlane_vendor",
 *   label = @Translation("Vendor"),
 *   label_collection = @Translation("Vendors"),
 *   label_singular = @Translation("vendor"),
 *   label_plural = @Translation("vendors"),
 *   label_count = @PluralTranslation(
 *     singular = "@count vendor",
 *     plural = "@count vendors"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\myeventlane_vendor\VendorListBuilder",
 *     "access" = "Drupal\myeventlane_vendor\Entity\VendorAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\myeventlane_vendor\Form\VendorForm",
 *       "add" = "Drupal\myeventlane_vendor\Form\VendorForm",
 *       "edit" = "Drupal\myeventlane_vendor\Form\VendorForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   base_table = "myeventlane_vendor",
 *   admin_permission = "administer myeventlane vendor",
 *   field_ui_base_route = "myeventlane_vendor.settings",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "name",
 *     "owner" = "uid"
 *   },
 *   links = {
 *     "canonical" = "/vendor/{myeventlane_vendor}",
 *     "collection" = "/admin/structure/myeventlane/vendor",
 *     "add-form" = "/admin/structure/myeventlane/vendor/add",
 *     "edit-form" = "/admin/structure/myeventlane/vendor/{myeventlane_vendor}/edit",
 *     "delete-form" = "/admin/structure/myeventlane/vendor/{myeventlane_vendor}/delete"
 *   }
 * )
 */
class Vendor extends ContentEntityBase implements EntityChangedInterface, EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    // Set the owner to the current user if not already set.
    $ownerId = $this->getOwnerId();
    if ($ownerId === NULL) {
      $ownerId = (int) \Drupal::currentUser()->id();
      $this->setOwnerId($ownerId);
    }

    // ENFORCE 1:1 RELATIONSHIP: One Drupal user → one Vendor entity.
    // Prevent duplicate Vendor creation at storage level.
    // This is a hard constraint: if a vendor already exists for this owner,
    // throw an exception to prevent duplicate creation.
    if ($this->isNew() && $ownerId > 0) {
      $existingVendorIds = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $ownerId)
        ->execute();

      if (!empty($existingVendorIds)) {
        // Remove this entity from the list if it's somehow in there.
        $existingVendorIds = array_filter($existingVendorIds, function ($id) {
          return $id !== $this->id();
        });

        if (!empty($existingVendorIds)) {
          $logger = \Drupal::logger('myeventlane_vendor');
          $logger->error('Attempted to create duplicate Vendor entity for user @uid. Existing vendor ID: @existing_id', [
            '@uid' => $ownerId,
            '@existing_id' => reset($existingVendorIds),
          ]);
          throw new EntityStorageException(
            'A vendor entity already exists for this user. Each user can only have one vendor entity.'
          );
        }
      }
    }

    // Ensure field_vendor_users references are valid.
    // The entity reference access check can fail if the current user doesn't have
    // permission to view the referenced users, but the references themselves are valid.
    if ($this->hasField('field_vendor_users') && !$this->get('field_vendor_users')->isEmpty()) {
      $valid_users = [];
      foreach ($this->get('field_vendor_users') as $item) {
        if ($item->target_id) {
          $user = \Drupal::entityTypeManager()->getStorage('user')->load($item->target_id);
          // Only keep references to users that exist and are active.
          if ($user && $user->isActive()) {
            $valid_users[] = ['target_id' => $item->target_id];
          }
        }
      }
      // Reset the field with only valid users.
      $this->set('field_vendor_users', $valid_users);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Vendor name (label field).
    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Vendor name'))
      ->setDescription(new TranslatableMarkup('The name of the vendor / organiser.'))
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

    // Owner (uid) field - user who owns this vendor.
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Owner'))
      ->setDescription(new TranslatableMarkup('The user who owns this vendor.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default:user')
      ->setSetting('handler_settings', [
        'include_anonymous' => FALSE,
      ])
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner')
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

    // Created timestamp.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('The time that the vendor was created.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Changed timestamp.
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('The time that the vendor was last updated.'))
      ->setDisplayConfigurable('view', TRUE);

    // API key (hashed value for vendor API authentication).
    $fields['api_key_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('API Key Hash'))
      ->setDescription(new TranslatableMarkup('Hashed API key for vendor API authentication. Generated automatically.'))
      ->setRequired(FALSE)
      ->setSettings([
        'max_length' => 255,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 20,
        'settings' => [
          'size' => 60,
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setReadOnly(TRUE);

    // Opt-in for educational nudges on the vendor dashboard.
    // Default OFF — vendors must explicitly enable this.
    $fields['nudges_enabled'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Enable helpful tips'))
      ->setDescription(new TranslatableMarkup('Opt in to receive occasional helpful tips in the vendor dashboard.'))
      ->setDefaultValue(0)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 100,
        'settings' => [
          'display_label' => TRUE,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', FALSE);

    // Opt-in for AI assistant (Vendor AI in escalation context).
    // Default OFF — vendors must explicitly enable. Admin can override.
    $fields['ai_enabled'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Enable AI assistant'))
      ->setDescription(new TranslatableMarkup('Allow the AI assistant to help with escalation questions in the vendor portal.'))
      ->setDefaultValue(0)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 95,
        'settings' => [
          'display_label' => TRUE,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', FALSE);

    // Billing preferences: optional auto-billing for MEL contribution invoices.
    // Default OFF — vendor must explicitly opt in. NEVER auto-charge without this.
    $fields['auto_billing_enabled'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Auto-billing enabled'))
      ->setDescription(new TranslatableMarkup('Automatically pay MEL contribution invoices using a saved payment method.'))
      ->setDefaultValue(0)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    // Stripe customer ID for platform payments (MEL contributions). PCI: we never store card details.
    $fields['stripe_customer_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Stripe customer ID'))
      ->setDescription(new TranslatableMarkup('Stripe customer ID for platform auto-billing.'))
      ->setRequired(FALSE)
      ->setSettings(['max_length' => 255])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    // Default payment method ID (pm_xxx) for off-session charges.
    $fields['stripe_default_payment_method'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Stripe default payment method'))
      ->setDescription(new TranslatableMarkup('Stripe payment method ID for auto-billing.'))
      ->setRequired(FALSE)
      ->setSettings(['max_length' => 255])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    return $fields;
  }

  /**
   * Gets the vendor name.
   *
   * @return string
   *   The vendor name.
   */
  public function getName(): string {
    return $this->get('name')->value ?? '';
  }

  /**
   * Sets the vendor name.
   *
   * @param string $name
   *   The vendor name.
   *
   * @return $this
   */
  public function setName(string $name): static {
    $this->set('name', $name);
    return $this;
  }

  /**
   * Gets the created time.
   *
   * @return int
   *   The created timestamp.
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * Sets the created time.
   *
   * @param int $timestamp
   *   The created timestamp.
   *
   * @return $this
   */
  public function setCreatedTime(int $timestamp): static {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * Gets the API key hash.
   *
   * @return string|null
   *   The hashed API key, or NULL if not set.
   */
  public function getApiKeyHash(): ?string {
    $value = $this->get('api_key_hash')->value;
    return $value ? (string) $value : NULL;
  }

  /**
   * Sets the API key hash.
   *
   * @param string $hash
   *   The hashed API key.
   *
   * @return $this
   */
  public function setApiKeyHash(string $hash): static {
    $this->set('api_key_hash', $hash);
    return $this;
  }

  /**
   * Whether AI assistant is enabled for this vendor.
   *
   * @return bool
   *   TRUE if AI is enabled.
   */
  public function isAiEnabled(): bool {
    if (!$this->hasField('ai_enabled') || $this->get('ai_enabled')->isEmpty()) {
      return FALSE;
    }
    return (bool) $this->get('ai_enabled')->value;
  }

  /**
   * Whether auto-billing for MEL invoices is enabled.
   *
   * @return bool
   *   TRUE if auto-billing is enabled.
   */
  public function isAutoBillingEnabled(): bool {
    if (!$this->hasField('auto_billing_enabled') || $this->get('auto_billing_enabled')->isEmpty()) {
      return FALSE;
    }
    return (bool) $this->get('auto_billing_enabled')->value;
  }

  /**
   * Gets the Stripe customer ID for platform billing.
   *
   * @return string|null
   *   The Stripe customer ID (cus_xxx), or NULL if not set.
   */
  public function getStripeCustomerId(): ?string {
    if (!$this->hasField('stripe_customer_id') || $this->get('stripe_customer_id')->isEmpty()) {
      return NULL;
    }
    $v = trim((string) $this->get('stripe_customer_id')->value);
    return $v !== '' ? $v : NULL;
  }

  /**
   * Gets the Stripe default payment method ID.
   *
   * @return string|null
   *   The payment method ID (pm_xxx), or NULL if not set.
   */
  public function getStripeDefaultPaymentMethod(): ?string {
    if (!$this->hasField('stripe_default_payment_method') || $this->get('stripe_default_payment_method')->isEmpty()) {
      return NULL;
    }
    $v = trim((string) $this->get('stripe_default_payment_method')->value);
    return $v !== '' ? $v : NULL;
  }

}
