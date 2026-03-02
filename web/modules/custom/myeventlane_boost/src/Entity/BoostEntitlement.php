<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Boost entitlement content entity.
 *
 * @ContentEntityType(
 *   id = "myeventlane_boost_entitlement",
 *   label = @Translation("Boost entitlement"),
 *   label_collection = @Translation("Boost entitlements"),
 *   handlers = {
 *     "access" = "Drupal\myeventlane_boost\BoostEntitlementAccessControlHandler",
 *     "list_builder" = "Drupal\myeventlane_boost\BoostEntitlementListBuilder",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     },
 *     "views_data" = "Drupal\views\EntityViewsData"
 *   },
 *   base_table = "myeventlane_boost_entitlement",
 *   admin_permission = "administer myeventlane boost",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "owner" = "uid",
 *     "uid" = "uid"
 *   },
 *   links = {
 *     "collection" = "/admin/commerce/orders/boost-entitlements"
 *   }
 * )
 */
final class BoostEntitlement extends ContentEntityBase implements BoostEntitlementInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setDescription(new TranslatableMarkup('Human-readable entitlement label.'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ]);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Vendor'))
      ->setDescription(new TranslatableMarkup('Vendor user who owns this entitlement.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default:user');

    $fields['event'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Event'))
      ->setDescription(new TranslatableMarkup('Event node this entitlement applies to.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default:node')
      ->setSetting('handler_settings', [
        'target_bundles' => ['event' => 'event'],
      ]);

    $fields['order_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Order'))
      ->setDescription(new TranslatableMarkup('Commerce order that granted this entitlement.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'commerce_order');

    $fields['order_item_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Order item'))
      ->setDescription(new TranslatableMarkup('Order item that granted this entitlement.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'commerce_order_item');

    $fields['variation_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Variation'))
      ->setDescription(new TranslatableMarkup('Purchased Boost variation.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'commerce_product_variation');

    $fields['starts'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Starts'))
      ->setDescription(new TranslatableMarkup('Entitlement start timestamp.'))
      ->setRequired(TRUE);

    $fields['ends'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Ends'))
      ->setDescription(new TranslatableMarkup('Entitlement end timestamp.'))
      ->setRequired(TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setDescription(new TranslatableMarkup('Current entitlement status.'))
      ->setRequired(TRUE)
      ->setDefaultValue(BoostEntitlementInterface::STATUS_ACTIVE)
      ->setSetting('allowed_values', [
        BoostEntitlementInterface::STATUS_ACTIVE => 'Active',
        BoostEntitlementInterface::STATUS_EXPIRED => 'Expired',
        BoostEntitlementInterface::STATUS_REVOKED => 'Revoked',
      ]);

    $fields['amount_paid'] = BaseFieldDefinition::create('commerce_price')
      ->setLabel(new TranslatableMarkup('Amount paid'))
      ->setDescription(new TranslatableMarkup('Amount paid for this entitlement.'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave($storage): void {
    parent::preSave($storage);

    if ($this->get('label')->isEmpty()) {
      $event = $this->get('event')->entity;
      $event_label = $event ? $event->label() : 'Unknown event';
      $order_item_id = (int) ($this->get('order_item_id')->target_id ?? 0);
      $this->set('label', sprintf('Boost #%d for %s', $order_item_id, $event_label));
    }
  }

}
