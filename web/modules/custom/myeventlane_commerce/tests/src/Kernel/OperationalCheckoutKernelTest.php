<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_price\Price;
use Drupal\myeventlane_commerce\Service\OperationalCartProjectionBuilder;
use Drupal\myeventlane_commerce\Service\OperationalCheckoutGovernanceManager;
use Drupal\myeventlane_commerce\Service\OperationalCheckoutOrchestrationManager;
use Drupal\myeventlane_commerce\Service\OperationalCustomerGuidanceBuilder;
use Drupal\user\Entity\User;

/**
 * Kernel coverage for operational checkout orchestration edge cases.
 *
 * @group myeventlane_commerce
 */
final class OperationalCheckoutKernelTest extends OperationalMerchandiseKernelTest {

  public function testBuildCheckoutContractForOrderNonEmptyWhenOperationalProduct(): void {
    $customer = User::create([
      'name' => 'checkout_kernel_plain',
      'mail' => 'checkout-kernel-plain@example.test',
      'status' => 1,
    ]);
    $customer->save();

    $merchProduct = $this->createOperationalProduct('operational_merchandise', 'operational_merchandise_var', 'MERCH-PLAIN');
    $merchProduct->set('field_mel_operational_product', json_encode([
      'operational_product_type' => 'merch_pickup',
      'operational_summary' => 'Counter',
      'pickup_mode' => 'counter',
    ], JSON_THROW_ON_ERROR));
    $merchProduct->save();
    $merchVariation = $merchProduct->getVariations()[0];

    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'draft',
      'uid' => $customer->id(),
      'mail' => 'checkout-kernel-plain@example.test',
    ]);
    $order->save();

    $item = OrderItem::create([
      'type' => 'default',
      'order_id' => $order->id(),
      'purchased_entity' => $merchVariation,
      'quantity' => 1,
      'unit_price' => new Price('10.00', 'AUD'),
    ]);
    $item->save();
    $order->addItem($item);
    $order->save();

    $oco = new OperationalCheckoutOrchestrationManager(
      $this->compositionManager(),
      new OperationalCheckoutGovernanceManager($this->container->get('string_translation')),
      new OperationalCustomerGuidanceBuilder($this->container->get('string_translation')),
      new OperationalCartProjectionBuilder($this->container->get('string_translation')),
      $this->container->get('string_translation'),
    );

    $contract = $oco->buildCheckoutContractForOrder($order);
    $this->assertNotNull($contract);
    $this->assertNotEmpty($contract['checkout_groups']);
  }

}
