<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_flow\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\myeventlane_checkout_flow\Service\CheckoutGroupedSummaryBuilderInterface;
use Drupal\myeventlane_checkout_flow\Service\MelCheckoutSummaryPresenter;
use Drupal\myeventlane_core\GovernedOperationalTemplates;
use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\myeventlane_legal\Service\LegalSettingsService;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_checkout_flow\Service\MelCheckoutSummaryPresenter
 *
 * @group myeventlane_checkout_flow
 */
final class MelCheckoutSummaryPresenterTest extends UnitTestCase {

  private function legalSettings(string $refund_url = '/help/policies/refund-policy'): LegalSettingsService {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static function (string $key) use ($refund_url) {
      return $key === 'refund_policy_url' ? $refund_url : NULL;
    });
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('myeventlane_legal.settings')->willReturn($config);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $time = $this->createMock(TimeInterface::class);
    return new LegalSettingsService($factory, $logger_factory, $time);
  }

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

    $presenter = new MelCheckoutSummaryPresenter($grouped, $readiness, $templates, $this->legalSettings());
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

    $presenter = new MelCheckoutSummaryPresenter($grouped, $readiness, $templates, $this->legalSettings());
    $render = $presenter->buildGroupedSummaryRenderArray($order, ['surface' => 'complete']);

    $this->assertSame('complete', $render['#surface']);
    $this->assertFalse($render['#show_jump_to_payment']);
    $this->assertFalse($render['#has_grouped_line_items']);
    $this->assertTrue($render['#has_pricing_block']);
  }

  /**
   * @covers ::buildGroupedSummaryRenderArray
   */
  public function testCheckoutTrustBlockUsesApprovedCopyAndOmitsLegacyStrings(): void {
    $order = $this->createMock(OrderInterface::class);
    $order->method('getCacheTags')->willReturn(['commerce_order:4']);
    $order->method('getCacheContexts')->willReturn(['user']);

    $built = [
      'grouped_items' => [[
        'title' => 'Show',
        'items' => [[
          'quantity' => 1,
          'ticket_type' => 'General',
          'total_price' => '$25.00',
        ]],
      ]],
      'order_total' => '$25.00',
      'subtotal_formatted' => '$25.00',
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
    $presenter = new MelCheckoutSummaryPresenter($grouped, $readiness, $templates, $this->legalSettings('/help/policies/refund-policy'));
    $render = $presenter->buildGroupedSummaryRenderArray($order, ['surface' => 'checkout']);

    $this->assertFalse($render['#show_jump_to_payment']);
    $this->assertSame('Book with confidence', $render['#trust']['heading']);
    $this->assertSame('Secure payment processed by Stripe', $render['#trust']['line_secure']);
    $this->assertStringContainsString('booking confirmation', $render['#trust']['line_instant']);
    $this->assertStringContainsString('organiser', $render['#trust']['line_refund']);
    $this->assertSame('/help/policies/refund-policy', $render['#trust']['refund_url']);
    $this->assertSame('View the Refund Policy', $render['#trust']['refund_link_label']);
    $this->assertSame('Booking summary', $render['#labels']['title']);

    $legacy = [
      'Jump to payment',
      'Secure payments via Stripe',
      'Confirmation emailed instantly',
      'Refund policy and organiser rules are in the Help Centre.',
    ];
    foreach ($legacy as $needle) {
      $this->assertStringNotContainsString($needle, implode("\n", $render['#trust']));
      $this->assertStringNotContainsString($needle, implode("\n", $render['#labels']));
    }
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

    $presenter = new MelCheckoutSummaryPresenter($grouped, $readiness, $templates, $this->legalSettings());
    $render = $presenter->buildGroupedSummaryRenderArray($order, ['surface' => 'complete']);

    $this->assertTrue($render['#has_grouped_line_items']);
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

    $presenter = new MelCheckoutSummaryPresenter($grouped, $readiness, $templates, $this->legalSettings());
    $render = $presenter->buildGroupedSummaryRenderArray($order);

    $this->assertSame('container', $render['#type']);
    $this->assertArrayHasKey('empty', $render);
    $this->assertIsArray($render['empty']);
    $this->assertSame('mel_empty_state', $render['empty']['#theme']);
  }

}
