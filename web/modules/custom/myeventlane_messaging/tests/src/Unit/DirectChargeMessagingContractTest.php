<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_messaging\Unit;

use Drupal\Component\Serialization\Yaml;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Protects direct-charge seller, refund, and dispute wording in email.
 *
 * @group myeventlane_messaging
 * @coversNothing
 */
final class DirectChargeMessagingContractTest extends TestCase {

  public function testPaidBookingNamesTheOrganiserAsSeller(): void {
    foreach ($this->orderConfirmationTemplates() as $template) {
      $body = $this->renderBody($template, [
        'is_paid' => TRUE,
        'organiser_name' => 'Sample Organiser',
      ]);

      self::assertStringContainsString('Seller:</strong> Sample Organiser', $body);
      self::assertStringContainsString("organiser's connected Stripe account", $body);
      self::assertStringContainsString('MyEventLane provides the marketplace', $body);
    }
  }

  public function testInvoiceAndReceiptNameTheSupplier(): void {
    foreach (['order_invoice', 'order_receipt'] as $id) {
      $template = $this->syncTemplate($id);
      self::assertStringContainsString('<strong>Supplier:</strong> {{ vendor_name }}', (string) $template['body_html']);
    }
  }

  public function testBuyerRefundTimingIsConditionalNotPromised(): void {
    foreach (['refund_approved_buyer', 'refund_completed_buyer'] as $id) {
      $body = mb_strtolower((string) $this->syncTemplate($id)['body_html']);
      self::assertStringNotContainsString('2–5 business days', $body);
      self::assertStringNotContainsString('2-5 business days', $body);
      self::assertStringContainsString('payment method', $body);
      self::assertStringContainsString('financial institution', $body);
    }
  }

  public function testOrganiserRefundMessagesPreserveConnectedAccountContext(): void {
    foreach (['refund_approved_vendor', 'refund_completed_vendor', 'refund_failed_vendor', 'refund_requested_vendor'] as $id) {
      $body = mb_strtolower((string) $this->syncTemplate($id)['body_html']);
      self::assertStringContainsString('connected stripe account', $body, $id);
    }

    $approved = mb_strtolower((string) $this->syncTemplate('refund_approved_vendor')['body_html']);
    $failed = mb_strtolower((string) $this->syncTemplate('refund_failed_vendor')['body_html']);
    self::assertStringContainsString('sufficient funds', $approved);
    self::assertStringContainsString('sufficient funds', $failed);
  }

  public function testCriticalStripeAlertsMatchTheApprovedResponsibilityBoundary(): void {
    foreach ([
      'stripe_dispute_created_vendor',
      'stripe_account_restricted_vendor',
      'stripe_payout_failed_vendor',
    ] as $id) {
      $sync = $this->syncTemplate($id);
      $install = $this->installTemplate($id);
      self::assertSame($sync, $install, $id);
      self::assertTrue((bool) $sync['enabled'], $id);
      self::assertStringContainsString('stripe_manage_url', (string) $sync['body_html'], $id);
      $rendered = $this->renderBody($sync, [
        'organiser_name' => 'Example Organiser',
        'amount' => 'AUD 30.00',
        'reason' => 'general',
        'response_deadline' => '30 Aug 2026',
        'restriction_reason' => 'requirements.past_due',
        'failure_message' => 'Bank account unavailable',
        'stripe_manage_url' => 'https://vendor.example.test/stripe/manage',
      ]);
      self::assertStringNotContainsString('{{', $rendered, $id);
    }

    $dispute = (string) $this->syncTemplate('stripe_dispute_created_vendor')['body_html'];
    self::assertStringContainsString('You are responsible for responding', $dispute);
    self::assertStringContainsString('cannot decide the dispute', $dispute);

    $restriction = (string) $this->syncTemplate('stripe_account_restricted_vendor')['body_html'];
    self::assertStringContainsString('cannot approve Stripe verification', $restriction);
    self::assertStringContainsString('remove a restriction', $restriction);

    $payout = (string) $this->syncTemplate('stripe_payout_failed_vendor')['body_html'];
    self::assertStringContainsString('Stripe controls payout timing', $payout);
    self::assertStringContainsString('cannot release, reschedule or mark a Stripe payout as paid', $payout);
  }

  public function testCriticalStripeAlertQueueFailureRemainsRetryable(): void {
    $customModules = dirname(__DIR__, 4);
    $handler = file_get_contents($customModules . '/myeventlane_commerce/src/Service/DirectChargeOperationalEventHandler.php');
    $manager = file_get_contents($customModules . '/myeventlane_messaging/src/Service/MessagingManager.php');

    self::assertIsString($handler);
    self::assertIsString($manager);
    self::assertStringContainsString("'return_existing' => TRUE", $handler);
    self::assertStringContainsString('Critical Stripe organiser notification could not be queued', $handler);
    self::assertStringContainsString("['processing', 'dispatching', 'sent']", $manager);
    self::assertStringNotContainsString("['queued', 'processing', 'dispatching', 'sent']", $manager);
    self::assertStringContainsString('$queueItemId === FALSE', $manager);
    self::assertStringContainsString("'status' => 'failed'", $manager);
    self::assertStringContainsString('Without this transition, a later', $manager);
  }

  public function testInterruptedQueuedLedgerIsReenqueuedBeforeSuccess(): void {
    $manager = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_messaging/src/Service/MessagingManager.php');
    $queuedRecoveryPattern = <<<'REGEX'
/if \(\$returnExisting && \$existing->status === 'queued'\) \{.*?createItem\(\[.*?'message_id' => \$existing->id,.*?return \$existing->id;/s
REGEX;

    self::assertIsString($manager);
    self::assertMatchesRegularExpression(
      $queuedRecoveryPattern,
      $manager,
    );
    self::assertStringContainsString('queued message durably re-enqueued', $manager);
  }

  public function testRefundFailureAndRejectionCopyDoesNotOverstateMelControl(): void {
    $buyerFailure = (string) $this->syncTemplate('refund_failed_buyer')['body_html'];
    self::assertStringContainsString('investigate the recorded refund state with the organiser', $buyerFailure);

    $adminFailure = (string) $this->syncTemplate('refund_failed_admin')['body_html'];
    self::assertStringContainsString('Stripe controls the payment and dispute outcome', $adminFailure);
    self::assertStringContainsString('must not promise to release funds or override Stripe', $adminFailure);

    $buyerRejection = (string) $this->syncTemplate('refund_rejected_buyer')['body_html'];
    self::assertStringContainsString('does not affect rights you may have under Australian Consumer Law', $buyerRejection);
  }

  public function testCancellationCopyNamesOrganiserResponsibilityAndFundingSource(): void {
    foreach ($this->cancellationTemplates() as $template) {
      $body = (string) ($template['body_html'] ?? $template['body'] ?? '');
      self::assertStringContainsString('You remain responsible for refunds for your event', $body);
      self::assertStringContainsString('money comes from your connected Stripe account', $body);
      self::assertStringContainsString('Make sure sufficient funds are available', $body);
    }
  }

  public function testFallbackInstallAndCancellationSourcesKeepTheSameBoundaries(): void {
    $customModules = dirname(__DIR__, 4);
    $refundInstall = file_get_contents($customModules . '/myeventlane_refunds/myeventlane_refunds.install');
    $buyerForm = file_get_contents($customModules . '/myeventlane_refunds/src/Form/BuyerRefundForm.php');
    $cancellationWorker = file_get_contents($customModules . '/myeventlane_automation/src/Plugin/QueueWorker/EventCancelledWorker.php');

    self::assertIsString($refundInstall);
    self::assertIsString($buyerForm);
    self::assertIsString($cancellationWorker);
    self::assertStringNotContainsString('2–5 business days', $refundInstall . $buyerForm);
    self::assertStringNotContainsString('refunds will be processed automatically', mb_strtolower($cancellationWorker));
    self::assertStringContainsString('connected Stripe account', $refundInstall);
    self::assertStringContainsString('Australian Consumer Law', $refundInstall);
    self::assertStringContainsString("organiser\\'s connected Stripe account", $cancellationWorker);
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function orderConfirmationTemplates(): array {
    return [
      $this->syncTemplate('order_confirmation'),
      $this->installTemplate('order_confirmation'),
    ];
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function cancellationTemplates(): array {
    return [
      $this->syncTemplate('vendor_event_cancellation'),
      $this->installTemplate('vendor_event_cancellation'),
    ];
  }

  /**
   * @param array<string, mixed> $template
   * @param array<string, mixed> $context
   */
  private function renderBody(array $template, array $context): string {
    $twig = new Environment(new ArrayLoader([]));
    return trim($twig->createTemplate((string) $template['body_html'])->render($context));
  }

  /**
   * @return array<string, mixed>
   */
  private function syncTemplate(string $id): array {
    return $this->loadYaml(dirname(__DIR__, 7) . "/config/sync/myeventlane_messaging.template.{$id}.yml");
  }

  /**
   * @return array<string, mixed>
   */
  private function installTemplate(string $id): array {
    return $this->loadYaml(dirname(__DIR__, 3) . "/config/install/myeventlane_messaging.template.{$id}.yml");
  }

  /**
   * @return array<string, mixed>
   */
  private function loadYaml(string $path): array {
    self::assertFileExists($path);
    $data = Yaml::decode((string) file_get_contents($path));
    self::assertIsArray($data);
    return $data;
  }

}
