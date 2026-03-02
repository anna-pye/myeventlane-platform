<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_refunds\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_price\Price;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_refunds\Form\VendorRefundForm;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel tests for VendorRefundForm access behavior.
 *
 * @group myeventlane_refunds
 */
final class VendorRefundFormAccessTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'commerce',
    'commerce_order',
    'commerce_price',
    'commerce_store',
    'myeventlane_checkout_flow',
    'myeventlane_refunds',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installConfig(['commerce_order', 'user']);

    if (!NodeType::load('event')) {
      NodeType::create([
        'type' => 'event',
        'name' => 'Event',
      ])->save();
    }
    if (!OrderType::load('default')) {
      OrderType::create([
        'id' => 'default',
        'label' => 'Default',
        'workflow' => 'order_default',
      ])->save();
    }
    if (!OrderItemType::load('default')) {
      OrderItemType::create([
        'id' => 'default',
        'label' => 'Default',
        'orderType' => 'default',
      ])->save();
    }

    if (!FieldStorageConfig::loadByName('commerce_order_item', 'field_target_event')) {
      FieldStorageConfig::create([
        'field_name' => 'field_target_event',
        'entity_type' => 'commerce_order_item',
        'type' => 'entity_reference',
        'settings' => [
          'target_type' => 'node',
        ],
      ])->save();
    }
    if (!FieldConfig::loadByName('commerce_order_item', 'default', 'field_target_event')) {
      FieldConfig::create([
        'field_name' => 'field_target_event',
        'entity_type' => 'commerce_order_item',
        'bundle' => 'default',
        'label' => 'Target event',
      ])->save();
    }
  }

  /**
   * Tests access TRUE for owner and FALSE for non-owner.
   */
  public function testAccessByVendorOwnership(): void {
    $vendor = User::create([
      'name' => 'vendor_owner',
      'mail' => 'vendor_owner@example.com',
    ]);
    $vendor->save();

    $otherVendor = User::create([
      'name' => 'vendor_other',
      'mail' => 'vendor_other@example.com',
    ]);
    $otherVendor->save();

    $role = Role::create([
      'id' => 'vendor_refund_role',
      'label' => 'Vendor refund role',
    ]);
    $role->grantPermission('manage_refunds');
    $role->save();
    $vendor->addRole('vendor_refund_role');
    $vendor->save();

    $ownedEvent = Node::create([
      'type' => 'event',
      'title' => 'Owned Event',
      'uid' => $vendor->id(),
    ]);
    $ownedEvent->save();

    $foreignEvent = Node::create([
      'type' => 'event',
      'title' => 'Foreign Event',
      'uid' => $otherVendor->id(),
    ]);
    $foreignEvent->save();

    $order = Order::create([
      'type' => 'default',
      'state' => 'completed',
      'uid' => 0,
    ]);
    $order->save();

    $ownedItem = OrderItem::create([
      'type' => 'default',
      'order_id' => $order->id(),
      'unit_price' => new Price('25.00', 'AUD'),
      'quantity' => 1,
      'field_target_event' => ['target_id' => $ownedEvent->id()],
    ]);
    $ownedItem->save();
    $order->addItem($ownedItem);

    $foreignItem = OrderItem::create([
      'type' => 'default',
      'order_id' => $order->id(),
      'unit_price' => new Price('10.00', 'AUD'),
      'quantity' => 1,
      'field_target_event' => ['target_id' => $foreignEvent->id()],
    ]);
    $foreignItem->save();
    $order->addItem($foreignItem);
    $order->save();

    $this->container->get('current_user')->setAccount($vendor);
    $form = VendorRefundForm::create($this->container);

    $requestOwned = Request::create('/vendor/orders/' . $order->id() . '/refund', 'GET', [
      'event' => (string) $ownedEvent->id(),
      'order' => (string) $order->id(),
    ]);
    $this->container->get('request_stack')->push($requestOwned);
    $this->assertTrue($form->access($order)->isAllowed());

    $requestForeign = Request::create('/vendor/orders/' . $order->id() . '/refund', 'GET', [
      'event' => (string) $foreignEvent->id(),
      'order' => (string) $order->id(),
    ]);
    $this->container->get('request_stack')->pop();
    $this->container->get('request_stack')->push($requestForeign);
    $this->assertFalse($form->access($order)->isAllowed());
  }

}

