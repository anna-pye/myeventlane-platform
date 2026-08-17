<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_admin_dashboard\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the Stripe Transfer webhook event contract.
 *
 * @group myeventlane_admin_dashboard
 */
final class StripeWebhookEventContractTest extends TestCase {

  /**
   * Confirms that only real Stripe Transfer event types are dispatched.
   */
  public function testOnlyRealStripeTransferEventsAreDispatched(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/StripeWebhookController.php');
    self::assertIsString($source);

    self::assertStringContainsString("'transfer.created' =>", $source);
    self::assertStringContainsString("'transfer.reversed' =>", $source);
    self::assertStringNotContainsString("'transfer.paid' =>", $source);
    self::assertStringNotContainsString("'transfer.failed' =>", $source);
  }

  /**
   * Confirms that a full reversal restores manual payout recovery.
   */
  public function testFullReversalMovesPaidLedgerRowsToManualReview(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/StripeWebhookController.php');
    self::assertIsString($source);

    self::assertStringContainsString("'status' => 'unpaid'", $source);
    self::assertStringContainsString("'transfer_id' => NULL", $source);
    self::assertStringContainsString("'reversed_transfer_id' => \$transferId", $source);
    self::assertStringContainsString("->condition('transfer_id', \$transferId)", $source);
    self::assertStringContainsString("->condition('status', 'paid')", $source);
  }

  /**
   * Confirms that a delayed created event cannot undo a full reversal.
   */
  public function testCreatedReplayCannotRepayReversedTransfer(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/StripeWebhookController.php');
    self::assertIsString($source);

    self::assertStringContainsString("(\$row->reversed_transfer_id ?? NULL) === \$transferId", $source);
    self::assertStringContainsString("->isNull('reversed_transfer_id')", $source);
    self::assertStringContainsString("->condition('reversed_transfer_id', \$transferId, '<>')", $source);
  }

  /**
   * Confirms that webhook secrets are supplied through runtime overrides.
   */
  public function testWebhookSecretsUseDeploymentSafeRuntimeOverrides(): void {
    $settings = file_get_contents(dirname(__DIR__, 6) . '/sites/default/settings.mel_shared_session.php');
    self::assertIsString($settings);

    self::assertStringContainsString("\$melGetEnv('MEL_PRO_WEBHOOK_SECRET')", $settings);
    self::assertStringContainsString("['myeventlane_pro.settings']['subscription_webhook_secret']", $settings);
    self::assertStringContainsString("\$melGetEnv('MEL_PAYOUT_WEBHOOK_SECRET')", $settings);
    self::assertStringContainsString("['myeventlane_admin_dashboard.settings']['stripe_webhook_secret']", $settings);
  }

}
