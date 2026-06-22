<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_flow\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\myeventlane_checkout_flow\Service\CheckoutGroupedSummaryBuilderInterface;
use Drupal\myeventlane_checkout_flow\Service\MelCheckoutSummaryPresenter;
use Drupal\myeventlane_core\GovernedOperationalTemplates;
use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_checkout_flow\Service\MelCheckoutSummaryPresenter
 *
 * @group myeventlane_checkout_flow
 */
final class MelCheckoutSummaryPresenterTest extends UnitTestCase {

  /**
   * @covers ::buildGroupedSummaryRenderArray
   */
  public function testCartSurfaceOmitsJumpToPayment(): void {
    $order = $this->createMock(OrderInterface::class);
    $order->method('getCacheTags')->willReturn(['commerce_order:1']);
    $order->method('getCacheContexts')->willReturn(['user']);

    $built = [
      'grouped_items' => [['title' => 'E', 'items' => []]],
      'order_total' => '$10.00',
      'subtotal_formatted' => '$9.00',
      'optional_donation_formatted' => '',
      'tax_rows' => [],
      'fee_rows' => [],
      'platform_fee_absorbed' => FALSE,
      'show_includes_gst_note' => FALSE,
      'cache_tags' => [],
    ];

    $grouped = $this->createMock(CheckoutGroupedSummaryBuilderInterface::class);
    $grouped->method('build')->with($order)->willReturn($built);

    $readiness = new MelReadinessHelper($this->getStringTranslationStub());
    $templates = new GovernedOperationalTemplates($readiness);

    $presenter = new MelCheckoutSummaryPresenter($grouped, $readiness, $templates);
    $render = $presenter->buildGroupedSummaryRenderArray($order, ['surface' => 'cart']);

    $this->assertSame('mel_checkout_order_summary_grouped', $render['#theme']);
    $this->assertSame('cart', $render['#surface']);
    $this->assertFalse($render['#show_jump_to_payment']);
  }

  /**
   * @covers ::buildGroupedSummaryRenderArray
   */
  public function testCompleteSurfaceOmitsJumpToPayment(): void {
    $order = $this->createMock(OrderInterface::class);
    $order->method('getCacheTags')->willReturn(['commerce_order:2']);
    $order->method('getCacheContexts')->willReturn(['user']);

    $built = [
      'grouped_items' => [['title' => 'E', 'items' => []]],
      'order_total' => '$10.00',
      'subtotal_formatted' => '$9.00',
      'optional_donation_formatted' => '',
      'tax_rows' => [],
      'fee_rows' => [],
      'platform_fee_absorbed' => FALSE,
      'show_includes_gst_note' => FALSE,
      'cache_tags' => [],
    ];

    $grouped = $this->createMock(CheckoutGroupedSummaryBuilderInterface::class);
    $grouped->method('build')->willReturn($built);

    $readiness = new MelReadinessHelper($this->getStringTranslationStub());
    $templates = new GovernedOperationalTemplates($readiness);

    $presenter = new MelCheckoutSummaryPresenter($grouped, $readiness, $templates);
    $render = $presenter->buildGroupedSummaryRenderArray($order, ['surface' => 'complete']);

    $this->assertSame('complete', $render['#surface']);
    $this->assertFalse($render['#show_jump_to_payment']);
    $this->assertFalse($render['has_grouped_line_items']);
    $this->assertTrue($render['has_pricing_block']);
  }

  /**
   * @covers ::buildGroupedSummaryRenderArray
   */
  public function testGroupedLineItemsFlagWhenTicketRowsExist(): void {
    $order = $this->createMock(OrderInterface::class);
    $order->method('getCacheTags')->willReturn(['commerce_order:3']);
    $order->method('getCacheContexts')->willReturn(['user']);

    $built = [
      'grouped_items' => [[
        'title' => 'Show',
        'items' => [[
          'quantity' => 2,
          'ticket_type' => 'General',
          'total_price' => '$20.00',
        ]],
      ]],
      'order_total' => '$20.00',
      'subtotal_formatted' => '$20.00',
      'optional_donation_formatted' => '',
      'tax_rows' => [],
      'fee_rows' => [],
      'platform_fee_absorbed' => FALSE,
      'show_includes_gst_note' => FALSE,
      'cache_tags' => [],
    ];

    $grouped = $this->createMock(CheckoutGroupedSummaryBuilderInterface::class);
    $grouped->method('build')->willReturn($built);

    $readiness = new MelReadinessHelper($this->getStringTranslationStub());
    $templates = new GovernedOperationalTemplates($readiness);

    $presenter = new MelCheckoutSummaryPresenter($grouped, $readiness, $templates);
    $render = $presenter->buildGroupedSummaryRenderArray($order, ['surface' => 'complete']);

    $this->assertTrue($render['has_grouped_line_items']);
    $this->assertSame('mel_checkout_order_summary_grouped', $render['#theme']);
  }

  /**
   * @covers ::buildGroupedSummaryRenderArray
   */
  public function testEmptyOrderUsesGovernedEmptyState(): void {
    $order = $this->createMock(OrderInterface::class);
    $order->method('getCacheTags')->willReturn([]);
    $order->method('getCacheContexts')->willReturn([]);

    $built = [
      'grouped_items' => [],
      'order_total' => '',
      'subtotal_formatted' => '',
      'optional_donation_formatted' => '',
      'tax_rows' => [],
      'fee_rows' => [],
      'platform_fee_absorbed' => FALSE,
      'show_includes_gst_note' => FALSE,
      'cache_tags' => [],
    ];

    $grouped = $this->createMock(CheckoutGroupedSummaryBuilderInterface::class);
    $grouped->method('build')->willReturn($built);

    $readiness = new MelReadinessHelper($this->getStringTranslationStub());
    $templates = new GovernedOperationalTemplates($readiness);

    $presenter = new MelCheckoutSummaryPresenter($grouped, $readiness, $templates);
    $render = $presenter->buildGroupedSummaryRenderArray($order);

    $this->assertSame('container', $render['#type']);
    $this->assertArrayHasKey('empty', $render);
    $this->assertIsArray($render['empty']);
    $this->assertSame('mel_empty_state', $render['empty']['#theme']);
  }

}
