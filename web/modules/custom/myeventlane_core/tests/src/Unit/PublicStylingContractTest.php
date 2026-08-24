<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_core\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the customer-facing styling and messaging architecture.
 *
 * @group myeventlane_core
 */
final class PublicStylingContractTest extends TestCase {

  /**
   * Absolute repository root.
   */
  private string $repositoryRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->repositoryRoot = dirname(__DIR__, 7);
  }

  /**
   * Commerce-specific CSS must not ship in the global bundle.
   */
  public function testCommerceCssIsRouteScoped(): void {
    $main = $this->source('web/themes/custom/myeventlane_theme/src/scss/main.scss');
    $commerce = $this->source('web/themes/custom/myeventlane_theme/src/scss/commerce.scss');
    $libraries = $this->source('web/themes/custom/myeventlane_theme/myeventlane_theme.libraries.yml');
    $theme = $this->source('web/themes/custom/myeventlane_theme/myeventlane_theme.theme');

    self::assertStringContainsString("@use 'commerce/commerce' as commerce with (\$include-transactional: false)", $main);
    self::assertStringNotContainsString("@use 'components/checkout'", $main);
    self::assertStringContainsString("@use 'commerce/commerce'", $commerce);
    self::assertStringContainsString("@use 'components/checkout'", $commerce);
    self::assertStringContainsString('commerce-styling:', $libraries);
    self::assertStringContainsString("\$manifest['scss/commerce.scss']", $theme);
  }

  /**
   * Feature components must not replace the global brand token cascade.
   */
  public function testWizardDoesNotLeakGlobalBrandTokens(): void {
    $wizard = $this->source('web/themes/custom/myeventlane_theme/src/scss/components/_wizard.scss');
    $tokens = $this->source('web/themes/custom/myeventlane_theme/src/scss/base/_tokens.scss');

    self::assertStringNotContainsString(':root', $wizard);
    self::assertStringNotContainsString('--mel-primary:', $wizard);
    self::assertStringContainsString('--mel-color-primary: #6b46ff', $tokens);
  }

  /**
   * Error and maintenance pages must not depend on product-page chrome.
   */
  public function testRecoveryPagesRemainCalmAndDependencyLight(): void {
    $maintenanceSettings = $this->source('web/sites/default/settings.mel_shared_session.php');
    self::assertStringContainsString("\$settings['maintenance_theme'] = 'mel_maintenance';", $maintenanceSettings);

    foreach ([
      'web/themes/custom/mel_maintenance/templates/system/page--403.html.twig',
      'web/themes/custom/mel_maintenance/templates/system/page--404.html.twig',
      'web/themes/custom/myeventlane_theme/templates/system/mel-403.html.twig',
      'web/themes/custom/myeventlane_theme/templates/system/mel-404.html.twig',
    ] as $path) {
      $source = $this->source($path);
      self::assertStringContainsString('mel-err__panel', $source, $path);
      self::assertStringNotContainsString('mascot', strtolower($source), $path);
      self::assertStringNotContainsString('invite-only', strtolower($source), $path);
    }
  }

  /**
   * Platform email and SMS defaults must use the current MEL brand.
   */
  public function testPlatformMessagingUsesCurrentBrandDefaults(): void {
    $email = $this->source('web/modules/custom/myeventlane_messaging/templates/email/mel-email-base.html.twig');
    $sms = $this->source('web/modules/custom/myeventlane_rsvp/templates/sms/rsvp-confirm-sms.txt.twig');

    self::assertStringContainsString("default('#6b46ff')", $email);
    self::assertStringContainsString("set page_bg = '#fff7ee'", $email);
    self::assertStringContainsString("MyEventLane: You're confirmed", $sms);
    self::assertStringNotContainsString("\n", trim($sms));

    $ticketDefaults = $this->source('web/modules/custom/myeventlane_tickets/config/install/myeventlane_tickets.settings.yml');
    self::assertStringContainsString("brand_accent_color: '#6b46ff'", $ticketDefaults);

    $templatePaths = array_merge(
      glob($this->repositoryRoot . '/config/sync/myeventlane_messaging.template.*.yml') ?: [],
      glob($this->repositoryRoot . '/web/modules/custom/myeventlane_messaging/config/install/myeventlane_messaging.template.*.yml') ?: [],
    );
    self::assertNotEmpty($templatePaths);
    foreach ($templatePaths as $path) {
      $source = (string) file_get_contents($path);
      self::assertDoesNotMatchRegularExpression(
        '/#(?:f26d5b|6e7ef2|5360bf|ff6b9d|8e7cff|6c5ce7|f5c04c)/i',
        $source,
        $path,
      );
    }
  }

  /**
   * Sales-open active config must converge with its owning module default.
   */
  public function testSalesOpenMessagingConfigConverges(): void {
    $relativePath = 'myeventlane_messaging.template.sales_open.yml';
    $sync = Yaml::parse($this->source('config/sync/' . $relativePath));
    $install = Yaml::parse($this->source(
      'web/modules/custom/myeventlane_automation/config/install/' . $relativePath,
    ));

    self::assertIsArray($sync);
    self::assertIsArray($install);
    self::assertIsBool($sync['enabled'] ?? NULL);
    self::assertTrue($sync['enabled']);
    self::assertSame($sync, $install);
  }

  /**
   * Reads a repository source file.
   */
  private function source(string $relativePath): string {
    $source = file_get_contents($this->repositoryRoot . '/' . $relativePath);
    self::assertIsString($source, $relativePath);
    return $source;
  }

}
