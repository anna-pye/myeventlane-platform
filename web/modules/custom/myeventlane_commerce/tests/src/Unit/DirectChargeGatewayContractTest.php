<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the connected-account context required by organiser direct charges.
 */
final class DirectChargeGatewayContractTest extends TestCase {

  public function testGatewayScopesEveryChargeMutationToTheConnectedAccount(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Plugin/Commerce/PaymentGateway/StripeConnect.php');
    self::assertIsString($source);

    foreach (['createPaymentIntent', 'createPayment', 'onReturn', 'processWebHook', 'capturePayment', 'voidPayment', 'refundPayment'] as $method) {
      self::assertStringContainsString("function {$method}", $source);
    }
    self::assertGreaterThanOrEqual(6, substr_count($source, 'enterConnectedAccountContext'));
    self::assertStringContainsString('StripeLibrary::setAccountId($accountId)', $source);
    self::assertStringContainsString('StripeLibrary::setAccountId($previousAccount)', $source);
    self::assertStringContainsString("getData('stripe_connected_account_id')", $source);
    self::assertStringContainsString('protected function isReusable(): bool', $source);
    self::assertStringContainsString('public function getRemoteCustomerId', $source);
    self::assertStringContainsString('avoids creating Stripe Customers', $source);
    self::assertStringContainsString('$webhook_event->account', $source);
    self::assertStringContainsString('missing from direct-charge webhook', $source);
    self::assertStringContainsString('direct_charge_operational_event_handler', $source);
    self::assertStringContainsString('operationalEventHandler->supports', $source);
    self::assertStringContainsString('WebhookEventState::Succeeded', $source);
    self::assertStringContainsString('WebhookEventState::Skipped', $source);
    self::assertStringContainsString('WebhookEventState::Failed', $source);
  }

  public function testPaymentIntentContainsNoDestinationTransferParameters(): void {
    $service = file_get_contents(dirname(__DIR__, 3) . '/src/Service/StripeConnectPaymentService.php');
    self::assertIsString($service);
    self::assertStringNotContainsString("'transfer_data' =>", $service);
    self::assertStringNotContainsString("'destination' =>", $service);
    self::assertStringContainsString("'application_fee_amount' =>", $service);
    self::assertStringContainsString("'mel_charge_model' => 'organiser_direct_charge'", $service);
    self::assertStringContainsString("get('direct_charge_fee_model_approved')", $service);
    self::assertStringContainsString("get('platform_fee_percent')", $service);
    self::assertStringContainsString("get('platform_fee_gst_inclusive')", $service);
    self::assertStringContainsString('calculateApplicationFee($ticketRevenue, $feePercentage, 0)', $service);
    self::assertStringNotContainsString("get('stripe_fee_percent')", $service);
    self::assertStringNotContainsString("get('stripe_fee_fixed_cents')", $service);
    self::assertStringContainsString('validateApplicationFeeForDirectCharge', $service);
  }

  public function testRefundReturnsTheApplicationFeeWithoutTransferReversal(): void {
    $gateway = file_get_contents(dirname(__DIR__, 3) . '/src/Plugin/Commerce/PaymentGateway/StripeConnect.php');
    self::assertIsString($gateway);
    self::assertStringContainsString("'refund_application_fee' => TRUE", $gateway);
    self::assertStringNotContainsString("'reverse_transfer' =>", $gateway);
    self::assertStringContainsString("'partially_refunded'", $gateway);
    self::assertStringContainsString("'refunded'", $gateway);
  }

  public function testGeneralSettingsHasOneAdjustableTicketFeeSource(): void {
    $form = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_core/src/Form/GeneralSettingsForm.php');
    self::assertIsString($form);
    self::assertStringContainsString("['payments']['platform_fee_percent']", $form);
    self::assertStringContainsString('GST-inclusive MEL platform fee', $form);
    self::assertStringContainsString('with no fixed fee', $form);
    self::assertStringNotContainsString("['payments']['stripe_fee_percent']", $form);
    self::assertStringNotContainsString("['payments']['stripe_fee_fixed_cents']", $form);
  }

  public function testStripeJsPatchCarriesTheConnectedAccount(): void {
    $patch = file_get_contents(dirname(__DIR__, 7) . '/patches/commerce-stripe-connected-account-payment-element.patch');
    $module = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_commerce.module');
    self::assertIsString($patch);
    self::assertIsString($module);
    self::assertStringContainsString('stripeOptions.stripeAccount = settings.stripeAccount', $patch);
    self::assertStringContainsString("['stripeAccount'] = \$validation['account_id']", $module);
  }

  public function testCommerceStripeWebhookStatusPatchMatchesIntegerEnum(): void {
    $patch = file_get_contents(dirname(__DIR__, 7) . '/patches/commerce-stripe-webhook-status-type.patch');
    $composer = file_get_contents(dirname(__DIR__, 7) . '/composer.json');
    self::assertIsString($patch);
    self::assertIsString($composer);
    self::assertStringContainsString('int $webhook_event_status', $patch);
    self::assertStringContainsString('@@ -1689,13 +1689,13 @@', $patch);
    self::assertStringNotContainsString('@@ -1689,14 +1689,14 @@', $patch);
    self::assertStringContainsString('commerce-stripe-webhook-status-type.patch', $composer);
  }

  public function testGatewayEntityIsDormantUntilTheMigrationSwitchRoutesAnOrder(): void {
    $config = file_get_contents(dirname(__DIR__, 7) . '/config/sync/commerce_payment.commerce_payment_gateway.stripe_connect.yml');
    $subscriber = file_get_contents(dirname(__DIR__, 3) . '/src/EventSubscriber/FilterPaymentGatewaysSubscriber.php');
    $settings = file_get_contents(dirname(__DIR__, 7) . '/web/sites/default/settings.mel_shared_session.php');

    self::assertIsString($config);
    self::assertIsString($subscriber);
    self::assertIsString($settings);
    self::assertStringContainsString("status: false\n", $config);
    self::assertStringContainsString('plugin: stripe_connect', $config);
    self::assertStringContainsString('webhook_signing_secret:', $config);
    self::assertStringContainsString('if ($gatewayId === self::DIRECT_CHARGE_GATEWAY_ID)', $subscriber);
    self::assertStringContainsString('MEL_STRIPE_CONNECT_WEBHOOK_SECRET', $settings);
    self::assertStringContainsString('MEL_CONNECT_STRIPE_SECRET_KEY', $settings);
    self::assertStringContainsString('MEL_PLATFORM_STRIPE_SECRET_KEY', $settings);
    self::assertStringContainsString('MEL_PRO_STRIPE_SECRET_KEY', $settings);
  }

  public function testPurposeSpecificStripeClientsAreUsed(): void {
    $core = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_core/src/Service/StripeService.php');
    $portal = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_pro/src/Service/ProBillingPortalService.php');
    self::assertIsString($core);
    self::assertIsString($portal);
    self::assertStringContainsString('function getConnectClient', $core);
    self::assertStringContainsString('function getPlatformPaymentsClient', $core);
    self::assertStringContainsString('function getProBillingClient', $core);
    self::assertStringContainsString('getProBillingClient()', $portal);
  }

  /**
   * Missing Connect credentials surface the actionable organiser message.
   */
  public function testConnectCredentialErrorMessageRemainsInSync(): void {
    $service = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_core/src/Service/StripeService.php');
    $controller = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_vendor/src/Controller/StripeConnectController.php');

    self::assertIsString($service);
    self::assertIsString($controller);
    self::assertStringContainsString(
      "throw new \\RuntimeException('Stripe Connect server key is not configured.')",
      $service,
    );
    self::assertStringContainsString(
      "str_contains(\$msg, 'Stripe Connect server key is not configured')",
      $controller,
    );
    self::assertStringContainsString('MEL_CONNECT_STRIPE_SECRET_KEY', $controller);
  }

  public function testCoreTicketIntentHelperAlsoUsesDirectChargeContext(): void {
    $core = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_core/src/Service/StripeService.php');
    self::assertIsString($core);
    self::assertStringNotContainsString("'transfer_data' =>", $core);
    self::assertStringContainsString("'stripe_account' => \$stripeAccountId", $core);
    self::assertStringContainsString("'mel_charge_model' => 'organiser_direct_charge'", $core);
  }

}
