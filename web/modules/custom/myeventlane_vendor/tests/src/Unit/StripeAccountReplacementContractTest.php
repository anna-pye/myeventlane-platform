<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the non-destructive direct-charge account replacement workflow.
 */
final class StripeAccountReplacementContractTest extends TestCase {

  private function repositoryRoot(): string {
    return dirname(__DIR__, 7);
  }

  public function testReplacementUsesDedicatedProtectedStoreReferences(): void {
    $root = $this->repositoryRoot();
    foreach ([
      'field.storage.commerce_store.field_stripe_replacement_id.yml',
      'field.field.commerce_store.online.field_stripe_replacement_id.yml',
      'field.storage.commerce_store.field_stripe_previous_id.yml',
      'field.field.commerce_store.online.field_stripe_previous_id.yml',
    ] as $filename) {
      self::assertFileExists($root . '/config/sync/' . $filename);
    }

    $install = file_get_contents($root . '/web/modules/custom/myeventlane_vendor/myeventlane_vendor.install');
    self::assertIsString($install);
    self::assertStringContainsString('myeventlane_vendor_update_10026', $install);
    self::assertStringContainsString('field_stripe_replacement_id', $install);
    self::assertStringContainsString('field_stripe_previous_id', $install);
  }

  public function testProtectedAccountReferencesRemainHiddenOnStoreDisplays(): void {
    $root = $this->repositoryRoot();
    foreach ([
      'core.entity_form_display.commerce_store.online.default.yml',
      'core.entity_view_display.commerce_store.online.default.yml',
    ] as $filename) {
      $display = file_get_contents($root . '/config/sync/' . $filename);
      self::assertIsString($display);
      self::assertStringContainsString('field_stripe_previous_id: true', $display, $filename);
      self::assertStringContainsString('field_stripe_replacement_id: true', $display, $filename);
    }
  }

  public function testReplacementRequiresConfirmationAndNeverDeletesThePreviousAccount(): void {
    $root = $this->repositoryRoot();
    $routing = file_get_contents($root . '/web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml');
    $form = file_get_contents($root . '/web/modules/custom/myeventlane_vendor/src/Form/StripeAccountReconnectForm.php');
    $service = file_get_contents($root . '/web/modules/custom/myeventlane_core/src/Service/StripeService.php');
    $controller = file_get_contents($root . '/web/modules/custom/myeventlane_vendor/src/Controller/StripeConnectController.php');
    $health = file_get_contents($root . '/web/modules/custom/myeventlane_vendor/src/Service/VendorPaymentsHealthService.php');
    self::assertIsString($routing);
    self::assertIsString($form);
    self::assertIsString($service);
    self::assertIsString($controller);
    self::assertIsString($health);

    self::assertStringContainsString("path: '/stripe/reconnect'", $routing);
    self::assertStringContainsString("path: '/stripe/manage/previous'", $routing);
    self::assertStringContainsString('extends ConfirmFormBase', $form);
    self::assertStringContainsString('No existing account was replaced', $form);
    self::assertStringContainsString('beginConnectAccountReplacement', $form);
    self::assertStringContainsString('beginConnectAccountReplacement', $service);
    self::assertStringContainsString('promoteConnectAccountReplacement', $service);
    self::assertStringContainsString("set('field_stripe_previous_id', \$previousAccountId)", $service);
    self::assertStringContainsString("\$eligibility['configuration_compatible'] !== TRUE", $controller);
    self::assertStringContainsString("if (!\$eligibility['eligible'])", $controller);
    self::assertStringContainsString('promoteConnectAccountReplacement', $controller);
    self::assertStringContainsString('MANAGE_DEST_RECONNECT', $controller);
    self::assertStringNotContainsString('createLoginLink($accountId)', $controller);
    self::assertStringContainsString('public function managePrevious()', $controller);
    self::assertStringContainsString('resolvePendingReplacementAccountId($store)', $controller);
    self::assertStringContainsString('createLoginLinkIfEligible($previousAccountId)', $controller);
    self::assertStringContainsString("'myeventlane_vendor.stripe_manage_previous'", $health);
    self::assertStringContainsString("'secondary_cta_url' => \$previousManageUrl", $health);
    self::assertStringNotContainsString('accounts->delete', $service);
    self::assertStringNotContainsString('accounts->del', $service);
  }

  public function testPendingReplacementBlocksPaidPublishingAndShowsAResumeAction(): void {
    $root = $this->repositoryRoot();
    $gate = file_get_contents($root . '/web/modules/custom/myeventlane_vendor/src/Service/PaidPublishStripeGate.php');
    $health = file_get_contents($root . '/web/modules/custom/myeventlane_vendor/src/Service/VendorPaymentsHealthService.php');
    self::assertIsString($gate);
    self::assertIsString($health);

    self::assertStringContainsString("'stripe_reconnection_pending'", $gate);
    self::assertStringContainsString('DirectChargeCopy::RECONNECT_GATE', $gate);
    self::assertStringContainsString("'reconnection_pending'", $health);
    self::assertStringContainsString("'myeventlane_vendor.stripe_reconnect'", $health);
  }

}
