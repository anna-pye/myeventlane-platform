<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_messaging\Unit;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\myeventlane_messaging\Service\ReceiptPaymentContext;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_messaging\Service\ReceiptPaymentContext
 * @group myeventlane_messaging
 */
final class ReceiptPaymentContextTest extends UnitTestCase {

  public function testFinalNumberAndActualPaymentAmountsReplaceCartContext(): void {
    $order = $this->createMock(OrderInterface::class);
    $order->method('getOrderNumber')->willReturn('2026-09-16');
    $order->method('getPlacedTime')->willReturn(1788837829);
    $order->method('getTotalPaid')->willReturn(new Price('25', 'AUD'));
    $order->method('getBalance')->willReturn(new Price('25.75', 'AUD'));
    $order->method('collectAdjustments')->willReturn([]);
    $order->expects(self::never())->method('label');
    $context = $this->service($order)->resolve(557);
    self::assertSame('2026-09-16', $context['order_number']);
    self::assertSame('', $context['first_name']);
    self::assertSame('AUD 25', $context['amount_paid']);
    self::assertSame('AUD 25.75', $context['balance_due']);
    self::assertArrayNotHasKey('vendor_name', $context);
    self::assertArrayNotHasKey('platform_fee_lines', $context);
  }

  public function testUnnumberedOrderWaitsForPlacement(): void {
    $order = $this->createMock(OrderInterface::class);
    $order->method('getPlacedTime')->willReturn(1788837829);
    $order->method('getOrderNumber')->willReturn(NULL);
    $this->expectException(RequeueException::class);
    $this->service($order)->resolve(557);
  }

  public function testBillingNameAndExclusiveTaxArePreserved(): void {
    $container = new \Drupal\Core\DependencyInjection\ContainerBuilder();
    $container->set('plugin.manager.commerce_adjustment_type', new class {
      public function getDefinitions(): array {
        return ['tax' => ['label' => 'Tax']];
      }
    });
    \Drupal::setContainer($container);
    $value = $this->createMock(TypedDataInterface::class);
    $value->method('getValue')->willReturn(' Anna ');
    $item = $this->createMock(FieldItemInterface::class);
    $item->method('get')->with('given_name')->willReturn($value);
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('first')->willReturn($item);
    $profile = $this->createMock(ProfileInterface::class);
    $profile->method('hasField')->with('address')->willReturn(TRUE);
    $profile->method('get')->with('address')->willReturn($field);
    $order = $this->createMock(OrderInterface::class);
    $order->method('getOrderNumber')->willReturn('2026-09-16');
    $order->method('getPlacedTime')->willReturn(1788837829);
    $order->method('getBillingProfile')->willReturn($profile);
    $order->method('collectAdjustments')->willReturn([new Adjustment([
      'type' => 'tax', 'label' => 'GST', 'amount' => new Price('5', 'AUD'), 'included' => FALSE,
    ])]);
    $context = $this->service($order)->resolve(557);
    self::assertSame('Anna', $context['first_name']);
    self::assertFalse($context['organiser_gst_inclusive']);
  }

  public function testMissingOrderDoesNotProduceReceipt(): void {
    $this->expectException(RequeueException::class);
    $this->service(NULL)->resolve(557);
  }

  public function testTemplateRendersPaymentFactsAndSafeGreetingFallback(): void {
    $path = '/config/sync/myeventlane_messaging.template.order_invoice.yml';
    $template = \Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__, 7) . $path);
    $install = \Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__, 3) . '/config/install/myeventlane_messaging.template.order_invoice.yml');
    self::assertSame($template, $install);
    $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader(), ['autoescape' => 'html']);
    $context = [
      'order_number' => '2026-09-16', 'first_name' => '', 'invoice_date' => '08 Sep 2026',
      'vendor_name' => 'Recorded supplier', 'vendor_abn' => 'Recorded ABN',
      'order_total' => 'A$50.75', 'amount_paid' => 'A$25.00', 'balance_due' => 'A$25.75',
      'order_total_gst' => '', 'is_tax_invoice' => FALSE,
    ];
    $body = $twig->createTemplate($template['body_html'])->render($context);
    self::assertStringContainsString('Hello,', $body);
    self::assertStringContainsString('Amount paid', $body);
    self::assertStringContainsString('A$25.00', $body);
    self::assertStringContainsString('A$25.75', $body);
    self::assertStringNotContainsString('A$0.00', $body);
    self::assertStringContainsString('Recorded supplier', $body);
    self::assertStringContainsString('No GST was recorded', $body);
    self::assertSame('Receipt – Order #2026-09-16', $twig->createTemplate($template['subject'])->render($context));
  }

  private function service(?OrderInterface $order): ReceiptPaymentContext {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects(self::once())->method('loadUnchanged')->with(557)->willReturn($order);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('commerce_order')->willReturn($storage);
    $formatter = $this->createMock(CurrencyFormatterInterface::class);
    $formatter->method('format')->willReturnCallback(static fn ($amount, $currency): string => $currency . ' ' . $amount);
    return new ReceiptPaymentContext($manager, $formatter);
  }

}
