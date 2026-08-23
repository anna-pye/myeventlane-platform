<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_flow\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\myeventlane_checkout_flow\Service\MyTicketsOrderViewModelBuilder;
use Drupal\myeventlane_core\Service\TicketLabelResolver;
use Drupal\myeventlane_tickets\Service\UniversalTicketViewModelBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests customer refund summaries use canonical Commerce payment amounts.
 *
 * @group myeventlane_checkout_flow
 */
final class MyTicketsRefundSummaryTest extends TestCase {

  /**
   * A partial refund exposes refunded, original and retained amounts.
   */
  public function testPartialRefundSummary(): void {
    $builder = $this->builderForPayment('46.20', '45.45');
    $order = $this->createMock(OrderInterface::class);
    $order->method('id')->willReturn(522);

    $result = $builder->buildRefundSummary($order);
    $summary = $result['summary'];

    $this->assertSame('partial', $summary['status']);
    $this->assertSame('Partial refund processed', $summary['heading']);
    $this->assertSame('45.45', $summary['refunded_amount']->getNumber());
    $this->assertSame('46.20', $summary['original_paid_amount']->getNumber());
    $this->assertSame('0.75', $summary['remaining_amount']->getNumber());
    $this->assertSame(['commerce_payment:411'], $result['cache_tags']);
  }

  /**
   * A full refund does not report any retained amount.
   */
  public function testFullRefundSummary(): void {
    $builder = $this->builderForPayment('49.00', '49.00');
    $order = $this->createMock(OrderInterface::class);
    $order->method('id')->willReturn(523);

    $summary = $builder->buildRefundSummary($order)['summary'];

    $this->assertSame('full', $summary['status']);
    $this->assertSame('Refund processed', $summary['heading']);
    $this->assertTrue($summary['remaining_amount']->isZero());
  }

  /**
   * An unrefunded payment remains part of the original paid total.
   */
  public function testPartialRefundAcrossMultiplePayments(): void {
    $builder = $this->builderForPayments([
      ['id' => 411, 'amount' => '50.00', 'refunded' => '50.00'],
      ['id' => 412, 'amount' => '50.00', 'refunded' => '0.00'],
    ]);
    $order = $this->createMock(OrderInterface::class);
    $order->method('id')->willReturn(524);

    $result = $builder->buildRefundSummary($order);
    $summary = $result['summary'];

    $this->assertSame('partial', $summary['status']);
    $this->assertSame('50.00', $summary['refunded_amount']->getNumber());
    $this->assertSame('100', $summary['original_paid_amount']->getNumber());
    $this->assertSame('50', $summary['remaining_amount']->getNumber());
    $this->assertSame(
      ['commerce_payment:411', 'commerce_payment:412'],
      $result['cache_tags'],
    );
  }

  /**
   * Builds the subject with one canonical Commerce payment.
   */
  private function builderForPayment(
    string $amount,
    string $refunded,
  ): MyTicketsOrderViewModelBuilder {
    return $this->builderForPayments([
      ['id' => 411, 'amount' => $amount, 'refunded' => $refunded],
    ]);
  }

  /**
   * Builds the subject with canonical Commerce payments.
   *
   * @param list<array{id: int, amount: string, refunded: string}> $paymentData
   *   Payment fixtures.
   */
  private function builderForPayments(array $paymentData): MyTicketsOrderViewModelBuilder {
    $payments = [];
    foreach ($paymentData as $data) {
      $payment = $this->createMock(PaymentInterface::class);
      $payment->method('getAmount')->willReturn(new Price($data['amount'], 'AUD'));
      $payment->method('getRefundedAmount')->willReturn(new Price($data['refunded'], 'AUD'));
      $payment->method('getCacheTags')->willReturn(['commerce_payment:' . $data['id']]);
      $payments[$data['id']] = $payment;
    }

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn(array_combine(array_keys($payments), array_keys($payments)));

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn($payments);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('hasDefinition')->with('commerce_payment')->willReturn(TRUE);
    $entityTypeManager->method('getStorage')->with('commerce_payment')->willReturn($storage);

    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString());

    return new MyTicketsOrderViewModelBuilder(
      $entityTypeManager,
      (new \ReflectionClass(UniversalTicketViewModelBuilder::class))->newInstanceWithoutConstructor(),
      (new \ReflectionClass(TicketLabelResolver::class))->newInstanceWithoutConstructor(),
      $translation,
    );
  }

}
