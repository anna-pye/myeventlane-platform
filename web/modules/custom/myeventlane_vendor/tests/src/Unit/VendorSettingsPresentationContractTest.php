<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the consolidated account Settings presentation.
 *
 * @group myeventlane_vendor
 */
final class VendorSettingsPresentationContractTest extends TestCase {

  public function testSettingsHubUsesFourAccountLevelGroups(): void {
    $template = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/templates/settings-hub.html.twig');
    self::assertIsString($template);

    self::assertStringContainsString("'Profile & brand'|t", $template);
    self::assertStringContainsString("'Preferences'|t", $template);
    self::assertStringContainsString("'Help & account'|t", $template);
    self::assertStringContainsString("item.tone != 'success'", $template);
    self::assertStringContainsString('mel-settings-hub__groups', $template);
  }

  public function testSettingsHubUsesAccountLanguage(): void {
    $builder = file_get_contents(dirname(__DIR__, 3) . '/src/Service/VendorSettingsHubBuilder.php');
    self::assertIsString($builder);

    self::assertStringContainsString("'Account settings'", $builder);
    self::assertStringContainsString('Event-specific choices stay inside each Event Studio.', $builder);

    $health = file_get_contents(dirname(__DIR__, 3) . '/src/Service/VendorWorkspaceHealthService.php');
    self::assertIsString($health);
    self::assertStringContainsString('A few account settings need attention', $health);
  }

  public function testLongProfileSectionsDefaultClosed(): void {
    $form = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_vendor_settings/src/Form/VendorSettingsForm.php');
    self::assertIsString($form);

    foreach (['contact', 'public_page', 'store', 'team'] as $section) {
      self::assertMatchesRegularExpression(
        '/\$form\[\'' . preg_quote($section, '/') . '\'\]\s*=\s*\[.*?\'#open\'\s*=>\s*FALSE,/s',
        $form,
        sprintf('The %s section should default to closed.', $section),
      );
    }
  }

}
