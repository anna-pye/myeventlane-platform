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

  /**
   * Protects the four account-level settings groups.
   */
  public function testSettingsHubUsesFourAccountLevelGroups(): void {
    $template = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/templates/settings-hub.html.twig');
    self::assertIsString($template);

    self::assertStringContainsString("'Profile & brand'|t", $template);
    self::assertStringContainsString("'Preferences'|t", $template);
    self::assertStringContainsString("'Help & account'|t", $template);
    self::assertStringContainsString("item.tone != 'success'", $template);
    self::assertStringContainsString('mel-settings-hub__groups', $template);
    self::assertStringContainsString('mel-settings-hub mel-organiser-page', $template);
    self::assertStringContainsString('mel-organiser-page__title', $template);

    $controller = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/VendorSettingsHubController.php');
    self::assertIsString($controller);
    self::assertStringContainsString("'title' => NULL", $controller);
  }

  /**
   * Protects account-focused settings language.
   */
  public function testSettingsHubUsesAccountLanguage(): void {
    $builder = file_get_contents(dirname(__DIR__, 3) . '/src/Service/VendorSettingsHubBuilder.php');
    self::assertIsString($builder);

    self::assertStringContainsString("'Account settings'", $builder);
    self::assertStringContainsString('Event-specific choices stay inside each Event Studio.', $builder);

    $health = file_get_contents(dirname(__DIR__, 3) . '/src/Service/VendorWorkspaceHealthService.php');
    self::assertIsString($health);
    self::assertStringContainsString('A few account settings need attention', $health);
  }

  /**
   * Ensures long profile sections remain collapsed initially.
   */
  public function testLongProfileSectionsDefaultClosed(): void {
    $form = file_get_contents(dirname(__DIR__, 3) . '/src/Form/OrganiserProfileSettingsForm.php');
    self::assertIsString($form);

    foreach (['contact', 'public_page', 'store', 'team'] as $section) {
      self::assertMatchesRegularExpression(
        '/\$form\[\'' . preg_quote($section, '/') . '\'\]\s*=\s*\[.*?\'#open\'\s*=>\s*FALSE,/s',
        $form,
        sprintf('The %s section should default to closed.', $section),
      );
    }
  }

  /**
   * Ensures the profile hub enhances rather than replaces the Drupal form.
   */
  public function testOrganiserProfileHubKeepsTheCanonicalFormContract(): void {
    $module_root = dirname(__DIR__, 3);
    $form = file_get_contents($module_root . '/src/Form/OrganiserProfileSettingsForm.php');
    $library = file_get_contents($module_root . '/myeventlane_vendor.libraries.yml');
    $script = file_get_contents($module_root . '/js/mel-organiser-profile-hub.js');
    self::assertIsString($form);
    self::assertIsString($library);
    self::assertIsString($script);

    foreach (['public', 'profile', 'visual', 'contact', 'venues', 'business', 'team', 'notifications'] as $card) {
      self::assertStringContainsString("'data-mel-organiser-card' => '$card'", $form);
    }

    self::assertStringContainsString("'#type' => 'submit'", $form);
    self::assertStringContainsString("'#value' => \$this->t('Save changes')", $form);
    self::assertStringContainsString('js/mel-organiser-profile-hub.js', $library);
    self::assertStringContainsString('input[name="public_page[published]"]', $script);
    self::assertStringContainsString("actions.hidden = true", $script);
  }

  /**
   * Keeps the ATO-facing GST declaration consistent with onboarding.
   */
  public function testBusinessDetailsUseCurrentGstRegistrationLanguage(): void {
    $form = file_get_contents(dirname(__DIR__, 3) . '/src/Form/OrganiserProfileSettingsForm.php');
    self::assertIsString($form);

    self::assertStringContainsString('currently registered for GST with the Australian Taxation Office (ATO)', $form);
    self::assertStringContainsString('An active ABN does not automatically mean the organisation is registered for GST', $form);
    self::assertStringContainsString('“Registered from” date shown for Goods & Services Tax on ABN Lookup', $form);
    self::assertStringContainsString('separate platform fee may still include GST', $form);
    self::assertStringContainsString('match the Australian Business Register', $form);
  }

  /**
   * Ensures the retired compatibility module cannot be re-enabled.
   */
  public function testCompatibilityModuleIsRetired(): void {
    $module_root = dirname(__DIR__, 4) . '/myeventlane_vendor_settings';
    self::assertFileDoesNotExist($module_root . '/myeventlane_vendor_settings.info.yml');

    $extensions = file_get_contents(dirname(__DIR__, 7) . '/config/sync/core.extension.yml');
    self::assertIsString($extensions);
    self::assertStringNotContainsString('myeventlane_vendor_settings:', $extensions);
  }

}
