<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_messaging\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\myeventlane_messaging\EventSubscriber\OrderPlacedSubscriber;
use PHPUnit\Framework\TestCase;

/**
 * Protects organiser purchases from attendee email content and duplicates.
 *
 * @group myeventlane_messaging
 */
final class OrganiserTransactionEmailContractTest extends TestCase {

  /**
   * A Commerce Recurring billing-period order is not a new subscription.
   */
  public function testRecurringProRenewalDoesNotQualifyForWelcomeEmail(): void {
    $billingPeriod = $this->createMock(FieldItemListInterface::class);
    $billingPeriod->method('isEmpty')->willReturn(FALSE);
    $order = $this->createMock(OrderInterface::class);
    $order->method('hasField')->with('billing_period')->willReturn(TRUE);
    $order->method('get')->with('billing_period')->willReturn($billingPeriod);

    $subscriber = (new \ReflectionClass(OrderPlacedSubscriber::class))
      ->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod(OrderPlacedSubscriber::class, 'isRecurringRenewalOrder');

    self::assertTrue($method->invoke($subscriber, $order));
  }

  public function testProOrdersUseDedicatedIdempotentConfirmation(): void {
    $subscriber = $this->moduleFile('src/EventSubscriber/OrderPlacedSubscriber.php');
    $template = $this->syncConfig('myeventlane_messaging.template.pro_subscription_started.yml');

    self::assertStringContainsString('isProOnlyOrder($order)', $subscriber);
    self::assertStringContainsString('isRecurringRenewalOrder($order)', $subscriber);
    self::assertStringContainsString("hasField('billing_period')", $subscriber);
    self::assertStringContainsString("get('billing_period')->isEmpty()", $subscriber);
    self::assertStringContainsString("queue('pro_subscription_started'", $subscriber);
    self::assertStringContainsString("'order:%d:pro_subscription_started'", $subscriber);
    self::assertStringContainsString("commerce_recurring.commerce_billing_schedule.mel_pro_monthly", $subscriber);
    self::assertStringContainsString("configuration.trial_interval.number", $subscriber);
    self::assertStringNotContainsString("myeventlane_pro.settings')->get('trial_days", $subscriber);
    self::assertStringContainsString('{% if trial_applies %}', $template);
    self::assertStringContainsString('{{ monthly_price }} per month', $template);
    self::assertStringContainsString('Monthly until cancelled', $template);
    self::assertStringContainsString('download invoices and receipts', $template);
    self::assertStringNotContainsString('ticket', strtolower($template));
    self::assertStringNotContainsString('attendee', strtolower($template));
    self::assertStringNotContainsString('refund', strtolower($template));
  }

  public function testBoostConfirmationExplainsOneOffOrganiserPurchase(): void {
    $subscriber = $this->moduleFile('src/EventSubscriber/OrderPlacedSubscriber.php');
    $template = $this->syncConfig('myeventlane_messaging.template.boost_confirmation.yml');

    self::assertStringContainsString("'order:%d:boost_confirmation'", $subscriber);
    self::assertStringContainsString('{{ event_name }}', $template);
    self::assertStringContainsString('{{ boost_days }}', $template);
    self::assertStringContainsString('{{ total_paid }}', $template);
    self::assertStringContainsString('one-off payment', strtolower($template));
    self::assertStringContainsString('no recurring Boost charges', $template);
  }

  public function testProAbandonedCheckoutUsesOrganiserLanguageAndStableKeys(): void {
    $job = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_pro/src/Plugin/AdvancedQueue/JobType/ProAbandonedCartJob.php');
    self::assertIsString($job);

    foreach (['w1', 'w2'] as $wave) {
      $template = $this->syncConfig("myeventlane_messaging.template.pro_cart_abandoned_{$wave}.yml");
      self::assertStringContainsString('MyEventLane Pro', $template);
      self::assertStringContainsString('{{ monthly_price }} per month until cancelled', $template);
      self::assertStringNotContainsString('ticket', strtolower($template));
      self::assertStringNotContainsString('sell out', strtolower($template));
    }

    self::assertStringContainsString("'idempotency_key' => sprintf('order:%d:%s'", $job);
    self::assertStringContainsString('commerce_recurring.commerce_billing_schedule.mel_pro_monthly', $job);
    self::assertStringContainsString('configuration.trial_interval.number', $job);
    self::assertStringNotContainsString('You left tickets in your cart.', $job);
  }

  public function testProCannotTriggerTicketPdfRecovery(): void {
    $subscriber = $this->moduleFile('src/EventSubscriber/OrderPaidConfirmationPdfRecoverySubscriber.php');
    $invoice = $this->moduleFile('src/EventSubscriber/OrderPaidInvoiceSubscriber.php');

    self::assertStringContainsString("bundle() !== 'mel_pro_subscription_variation'", $subscriber);
    self::assertStringContainsString("bundle() === 'mel_pro_subscription_variation'", $invoice);
  }

  private function moduleFile(string $path): string {
    $contents = file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    self::assertIsString($contents);
    return $contents;
  }

  private function syncConfig(string $filename): string {
    $contents = file_get_contents(dirname(__DIR__, 7) . '/config/sync/' . $filename);
    self::assertIsString($contents);
    return $contents;
  }

}
