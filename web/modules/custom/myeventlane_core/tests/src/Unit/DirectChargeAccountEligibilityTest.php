<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_core\Unit;

require_once dirname(__DIR__, 3) . '/src/Service/StripeService.php';

use Drupal\myeventlane_core\Service\StripeService;
use PHPUnit\Framework\TestCase;
use Stripe\Account;

/**
 * Guards the Stripe responsibility model accepted for direct charges.
 */
final class DirectChargeAccountEligibilityTest extends TestCase {

  public function testStandardLikeControllerIsEligible(): void {
    $result = StripeService::evaluateDirectChargeAccountEligibility($this->account([
      'fees' => ['payer' => 'account'],
      'losses' => ['payments' => 'stripe'],
      'requirement_collection' => 'stripe',
      'stripe_dashboard' => ['type' => 'full'],
    ]));

    self::assertTrue($result['eligible']);
    self::assertNull($result['reason']);
    self::assertSame('stripe', $result['losses_payer']);
    self::assertSame('account', $result['fee_payer']);
  }

  public function testExpressLiabilityFailsClosed(): void {
    $result = StripeService::evaluateDirectChargeAccountEligibility($this->account([
      'fees' => ['payer' => 'application_express'],
      'losses' => ['payments' => 'application'],
      'requirement_collection' => 'stripe',
      'stripe_dashboard' => ['type' => 'express'],
    ], 'express'));

    self::assertFalse($result['eligible']);
    self::assertSame('MyEventLane is liable for this connected account payment losses.', $result['reason']);
    self::assertSame('application', $result['losses_payer']);
    self::assertSame('application_express', $result['fee_payer']);
  }

  public function testDisabledChargesFailClosed(): void {
    $result = StripeService::evaluateDirectChargeAccountEligibility($this->account([
      'fees' => ['payer' => 'account'],
      'losses' => ['payments' => 'stripe'],
      'requirement_collection' => 'stripe',
      'stripe_dashboard' => ['type' => 'full'],
    ], chargesEnabled: FALSE));

    self::assertFalse($result['eligible']);
    self::assertSame('Stripe has not enabled card charges for this account.', $result['reason']);
  }

  public function testNewAccountCreationUsesExplicitResponsibilityProperties(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Service/StripeService.php');
    self::assertIsString($source);
    self::assertStringContainsString("'fees' => ['payer' => 'account']", $source);
    self::assertStringContainsString("'losses' => ['payments' => 'stripe']", $source);
    self::assertStringContainsString("'stripe_dashboard' => ['type' => 'full']", $source);
    self::assertStringContainsString("string \$type = 'standard'", $source);
  }

  private function account(array $controller, string $type = 'standard', bool $chargesEnabled = TRUE): Account {
    return Account::constructFrom([
      'id' => 'acct_contract_test',
      'type' => $type,
      'charges_enabled' => $chargesEnabled,
      'capabilities' => ['card_payments' => $chargesEnabled ? 'active' : 'inactive'],
      'controller' => $controller,
    ]);
  }

}
