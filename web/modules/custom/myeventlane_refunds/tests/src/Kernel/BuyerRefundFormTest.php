<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_refunds\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_refunds\Form\BuyerRefundForm;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel tests for BuyerRefundForm request parameter handling.
 *
 * @group myeventlane_refunds
 */
final class BuyerRefundFormTest extends KernelTestBase {

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
    $this->installConfig(['commerce_order']);

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
  }

  /**
   * Ensures event/order query params are read without property conflicts.
   */
  public function testBuildFormReadsEventAndOrderParams(): void {
    $event = Node::create([
      'type' => 'event',
      'title' => 'Buyer Refund Event',
      'uid' => 0,
    ]);
    $event->save();

    $order = Order::create([
      'type' => 'default',
      'state' => 'completed',
      'uid' => 0,
    ]);
    $order->save();

    $request = Request::create('/my-tickets/order/' . $order->id() . '/refund', 'GET', [
      'event' => (string) $event->id(),
      'order' => (string) $order->id(),
    ]);
    $this->container->get('request_stack')->push($request);

    $form = BuyerRefundForm::create($this->container);
    $formState = new FormState();
    $build = $form->buildForm([], $formState);

    $this->assertSame((int) $event->id(), (int) $formState->get('event_id'));
    $this->assertSame((int) $order->id(), (int) $formState->get('order_id'));
    $this->assertArrayNotHasKey('error', $build);
    $this->assertFalse((new \ReflectionClass(BuyerRefundForm::class))->hasProperty('requestStack'));
  }

}

