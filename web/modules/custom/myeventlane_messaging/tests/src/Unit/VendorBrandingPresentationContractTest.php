<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_messaging\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the account-level message branding presentation.
 *
 * @group myeventlane_messaging
 */
final class VendorBrandingPresentationContractTest extends TestCase {

  public function testMessageBrandingRemainsAnAccountSettingsRoute(): void {
    $nav = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_vendor/src/Service/VendorNavBuilder.php');
    self::assertIsString($nav);
    self::assertStringContainsString("'myeventlane_vendor.console.messaging_brand' => 'settings'", $nav);

    $controller = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_vendor/src/Controller/VendorDashboardMessagingBrandController.php');
    self::assertIsString($controller);
    self::assertStringContainsString("'title' => \$this->t('Message branding')", $controller);
    self::assertStringContainsString("'tabs' => []", $controller);
  }

  public function testSavedAssetsHaveVisualPreviewsAndClearHelp(): void {
    $form = file_get_contents(dirname(__DIR__, 3) . '/src/Form/VendorBrandingForm.php');
    self::assertIsString($form);

    self::assertStringContainsString('buildAssetPreview(', $form);
    self::assertStringContainsString('shared with your organiser profile', $form);
    self::assertStringContainsString('Choose a colour with strong contrast', $form);
    self::assertStringContainsString('← Back to Account settings', $form);
  }

}
