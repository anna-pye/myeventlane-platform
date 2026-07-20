<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Event\FilterPaymentGatewaysEvent;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_commerce\EventSubscriber\FilterPaymentGatewaysSubscriber;
use Drupal\myeventlane_commerce\Service\OrderItemClassifier;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\EventSubscriber\FilterPaymentGatewaysSubscriber
 * @group myeventlane_commerce
 */
final class FilterPaymentGatewaysSubscriberTest extends UnitTestCase {

  /**
   * @covers ::onFilterPaymentGateways
   */
  public function testTicketCartKeepsStripeOnlyForCustomers(): void {
    $subscriber = $this->createSubscriber(requiresRecurring: FALSE, isAdmin: FALSE);
    $gateways = [
      'mel_stripe_cc' => $this->gateway('mel_stripe_cc'),
      'stripe' => $this->gateway('stripe'),
      'stripe_pe_recurring' => $this->gateway('stripe_pe_recurring'),
    ];
    $event = new FilterPaymentGatewaysEvent($gateways, $this->createMock(OrderInterface::class));
    $subscriber->onFilterPaymentGateways($event);
    $remaining = array_keys($event->getPaymentGateways());
    $this->assertSame(['stripe'], $remaining);
  }

  /**
   * @covers ::onFilterPaymentGateways
   */
  public function testProCartKeepsRecurringOnly(): void {
    $subscriber = $this->createSubscriber(requiresRecurring: TRUE, isAdmin: FALSE);
    $gateways = [
      'mel_stripe_cc' => $this->gateway('mel_stripe_cc'),
      'stripe' => $this->gateway('stripe'),
      'stripe_pe_recurring' => $this->gateway('stripe_pe_recurring'),
    ];
    $event = new FilterPaymentGatewaysEvent($gateways, $this->createMock(OrderInterface::class));
    $subscriber->onFilterPaymentGateways($event);
    $remaining = array_keys($event->getPaymentGateways());
    $this->assertSame(['stripe_pe_recurring'], $remaining);
  }

  /**
   * @covers ::onFilterPaymentGateways
   */
  public function testAdministratorKeepsManualGatewayOnTicketCart(): void {
    $subscriber = $this->createSubscriber(requiresRecurring: FALSE, isAdmin: TRUE);
    $gateways = [
      'mel_stripe_cc' => $this->gateway('mel_stripe_cc'),
      'stripe' => $this->gateway('stripe'),
      'stripe_pe_recurring' => $this->gateway('stripe_pe_recurring'),
    ];
    $event = new FilterPaymentGatewaysEvent($gateways, $this->createMock(OrderInterface::class));
    $subscriber->onFilterPaymentGateways($event);
    $remaining = array_keys($event->getPaymentGateways());
    sort($remaining);
    $this->assertSame(['mel_stripe_cc', 'stripe'], $remaining);
  }

  private function createSubscriber(bool $requiresRecurring, bool $isAdmin): FilterPaymentGatewaysSubscriber {
    $classifier = $this->createMock(OrderItemClassifier::class);
    $classifier->method('requiresRecurringPaymentGateway')->willReturn($requiresRecurring);

    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('hasRole')->with('administrator')->willReturn($isAdmin);

    $logger = $this->createMock(LoggerInterface::class);
    return new FilterPaymentGatewaysSubscriber($classifier, $account, $logger);
  }

  private function gateway(string $id): PaymentGatewayInterface {
    $gateway = $this->createMock(PaymentGatewayInterface::class);
    $gateway->method('id')->willReturn($id);
    return $gateway;
  }

}
