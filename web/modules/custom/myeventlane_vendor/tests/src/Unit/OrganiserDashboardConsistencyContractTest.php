<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects cross-dashboard naming and page-heading consistency.
 *
 * @group myeventlane_vendor
 */
final class OrganiserDashboardConsistencyContractTest extends TestCase {

  public function testPortfolioRoutesDoNotUseEventStudioAsTheirShellTitle(): void {
    $theme = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme');
    self::assertIsString($theme);

    foreach ([
      'myeventlane_vendor.console.events' => 'Events',
      'myeventlane_checkout_flow.vendor_attendees' => 'Attendees',
      'myeventlane_vendor.console.messages' => 'Messages',
      'myeventlane_escalations_portal.vendor_list' => 'Support',
    ] as $route => $title) {
      self::assertStringContainsString("'{$route}'", $theme);
      self::assertStringContainsString("t('{$title}')", $theme);
    }

    self::assertStringContainsString(
      "str_starts_with(\$current_path, '/vendor/support')",
      $theme,
    );
  }

  public function testShellTitleDoesNotCompeteWithThePageHeading(): void {
    $header = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/templates/includes/vendor-shell-header.html.twig');
    self::assertIsString($header);

    self::assertStringContainsString('<p class="mel-shell-header__title">', $header);
    self::assertStringNotContainsString('<h1 class="mel-shell-header__title">', $header);
  }

  public function testSupportUsesCurrentAccountSettingsLanguage(): void {
    $builder = file_get_contents(dirname(__DIR__, 3) . '/src/Service/VendorSupportHubBuilder.php');
    self::assertIsString($builder);

    self::assertStringContainsString("\$this->t('Account settings')", $builder);
    self::assertStringNotContainsString("\$this->t('Workspace Settings')", $builder);

    $routing = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_vendor_settings/myeventlane_vendor_settings.routing.yml');
    self::assertIsString($routing);
    self::assertStringContainsString("_title: 'Account settings'", $routing);
  }

}
