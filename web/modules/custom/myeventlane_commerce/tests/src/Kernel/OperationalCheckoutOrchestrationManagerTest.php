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
 * @group myeventlane_commerce
 */
final class OperationalCheckoutOrchestrationManagerTest extends OperationalMerchandiseKernelTest {

  private function checkoutOrchestration(): OperationalCheckoutOrchestrationManager {
    return new OperationalCheckoutOrchestrationManager(
      $this->compositionManager(),
      new OperationalCheckoutGovernanceManager($this->container->get('string_translation')),
      new OperationalCustomerGuidanceBuilder($this->container->get('string_translation')),
      new OperationalCartProjectionBuilder($this->container->get('string_translation')),
      $this->container->get('string_translation'),
    );
  }

  public function testBuildCheckoutContractForOrderContainsContractKeys(): void {
    $customer = User::create([
      'name' => 'checkout_orch_buyer',
      'mail' => 'checkout-orch-buyer@example.test',
      'status' => 1,
    ]);
    $customer->save();

    $merchProduct = $this->createOperationalProduct('operational_merchandise', 'operational_merchandise_var', 'MERCH-ORCH');
    $merchProduct->set('field_mel_operational_product', json_encode([
      'operational_product_type' => 'merch_pickup',
      'operational_summary' => 'Collect at north counter',
      'pickup_mode' => 'counter',
      'readiness_mode' => 'nominal',
      'operational_chips' => [
        ['label' => 'Collect after entry', 'tone' => 'info'],
      ],
    ], JSON_THROW_ON_ERROR));
    $merchProduct->save();
    $merchVariation = $merchProduct->getVariations()[0];

    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'draft',
      'uid' => $customer->id(),
      'mail' => 'checkout-orch-buyer@example.test',
    ]);
    $order->save();

    $itemMerch = OrderItem::create([
      'type' => 'default',
      'order_id' => $order->id(),
      'purchased_entity' => $merchVariation,
      'quantity' => 1,
      'unit_price' => new Price('10.00', 'AUD'),
    ]);
    $itemMerch->save();
    $order->addItem($itemMerch);
    $order->save();

    $contract = $this->checkoutOrchestration()->buildCheckoutContractForOrder($order);
    $this->assertNotNull($contract);
    $this->assertTrue($contract[OperationalCheckoutOrchestrationManager::CONTRACT_FLAG]);
    $this->assertArrayHasKey('checkout_groups', $contract);
    $this->assertArrayHasKey('fulfillment_groups', $contract);
    $this->assertArrayHasKey('pickup_groups', $contract);
    $this->assertArrayHasKey('readiness_rollup', $contract);
    $this->assertArrayHasKey('governance', $contract);
    $this->assertArrayHasKey('customer_guidance', $contract);
    $this->assertArrayHasKey('cart_projection', $contract);
    $this->assertSame((int) $order->id(), $contract['order_id']);
    $this->assertNoForbiddenKeysRecursive($contract, OperationalCheckoutOrchestrationManager::FORBIDDEN_CHECKOUT_KEYS);
  }

  public function testBuildOrderRenderArrayUsesMelOperationalCheckoutTheme(): void {
    $customer = User::create([
      'name' => 'checkout_orch_buyer2',
      'mail' => 'checkout-orch-buyer2@example.test',
      'status' => 1,
    ]);
    $customer->save();

    $merchProduct = $this->createOperationalProduct('operational_merchandise', 'operational_merchandise_var', 'MERCH-ORCH2');
    $merchProduct->set('field_mel_operational_product', json_encode([
      'operational_product_type' => 'merch_pickup',
      'operational_summary' => 'Collect',
      'pickup_mode' => 'counter',
    ], JSON_THROW_ON_ERROR));
    $merchProduct->save();
    $merchVariation = $merchProduct->getVariations()[0];

    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'draft',
      'uid' => $customer->id(),
      'mail' => 'checkout-orch-buyer2@example.test',
    ]);
    $order->save();
    $itemMerch = OrderItem::create([
      'type' => 'default',
      'order_id' => $order->id(),
      'purchased_entity' => $merchVariation,
      'quantity' => 1,
      'unit_price' => new Price('10.00', 'AUD'),
    ]);
    $itemMerch->save();
    $order->addItem($itemMerch);
    $order->save();

    $build = $this->checkoutOrchestration()->buildOrderRenderArray($order);
    $this->assertNotNull($build);
    $this->assertSame('mel_operational_checkout', $build['#theme']);
  }

  /**
   * @param array<string, mixed> $data
   * @param list<string>         $forbidden
   */
  private function assertNoForbiddenKeysRecursive(array $data, array $forbidden): void {
    foreach ($data as $key => $value) {
      if (is_string($key)) {
        $this->assertNotContains($key, $forbidden);
      }
      if (is_array($value)) {
        $this->assertNoForbiddenKeysRecursive($value, $forbidden);
      }
    }
  }

}
