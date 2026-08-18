<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the post-merge billing and webhook review corrections.
 *
 * @group myeventlane_pro
 */
final class ProPostMergeReviewContractTest extends TestCase {

  public function testMissingWebhookLedgerReturnsRetryableFailure(): void {
    $controller = $this->moduleFile('src/Controller/ProSubscriptionWebhookController.php');

    self::assertStringContainsString("return new Response('Webhook ledger unavailable', Response::HTTP_SERVICE_UNAVAILABLE)", $controller);
    self::assertStringContainsString('if ($recorded === NULL)', $controller);
    self::assertStringContainsString('if ($recorded === FALSE)', $controller);
  }

  public function testSettingsDisplayReadsCanonicalBillingSchedule(): void {
    $form = $this->moduleFile('src/Form/ProSettingsForm.php');

    self::assertStringContainsString('commerce_recurring.commerce_billing_schedule.mel_pro_monthly', $form);
    self::assertStringContainsString('configuration.trial_interval.number', $form);
    self::assertStringNotContainsString("->set('trial_days'", $form);
  }

  public function testCommercialSettingsPreserveNestedFormValues(): void {
    $form = $this->moduleFile('src/Form/ProSettingsForm.php');

    self::assertStringContainsString("'#tree' => TRUE", $form);
    self::assertStringContainsString("\$commercial = \$values['commercial'] ?? [];", $form);
    self::assertStringContainsString("->set('pro_boost_days', (int) (\$commercial['pro_boost_days'] ?? 7))", $form);
  }

  public function testElapsedProBoostIsCappedAndExpired(): void {
    $provisioner = $this->moduleFile('src/Service/ProBoostProvisioner.php');

    self::assertStringContainsString('cappedExistingGrantEnd(', $provisioner);
    self::assertStringContainsString('BoostEntitlementInterface::STATUS_EXPIRED', $provisioner);
  }

  public function testFreeCustomerCheckoutDoesNotPromiseCardStep(): void {
    $theme = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_theme/myeventlane_theme.theme');
    self::assertIsString($theme);

    self::assertStringContainsString("\$form['actions']['next']['#value'] = t('Complete booking');", $theme);
    self::assertStringNotContainsString("? t('Continue to card details')\n              : t('Complete booking');", $theme);
  }

  public function testDeletedBoostTargetFallsBackWithoutDereference(): void {
    $context = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_checkout_flow/src/Service/OrganiserCheckoutContext.php');
    self::assertIsString($context);

    self::assertStringContainsString('if ($event !== NULL)', $context);
    self::assertStringContainsString("\$target = \$eventTitle !== ''", $context);
  }

  private function moduleFile(string $path): string {
    $contents = file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    self::assertIsString($contents);
    return $contents;
  }

}
