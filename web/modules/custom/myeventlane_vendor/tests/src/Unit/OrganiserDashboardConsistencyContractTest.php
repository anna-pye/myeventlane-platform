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

  /**
   * Protects portfolio route shell titles.
   */
  public function testPortfolioRoutesDoNotUseEventStudioAsTheirShellTitle(): void {
    $theme = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme');
    self::assertIsString($theme);

    foreach ([
      'myeventlane_vendor.console.dashboard' => 'Organiser home',
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

  /**
   * The approved dashboard integrates payment health instead of duplicating it.
   */
  public function testDashboardOwnsItsPaymentAndHeaderHierarchy(): void {
    $webRoot = dirname(__DIR__, 6);
    $theme = (string) file_get_contents($webRoot . '/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme');
    $layout = (string) file_get_contents($webRoot . '/themes/custom/myeventlane_vendor_theme/templates/layout/page.html.twig');
    $bell = (string) file_get_contents($webRoot . '/modules/custom/myeventlane_notifications/templates/mel-notification-bell.html.twig');

    self::assertStringContainsString("'myeventlane_vendor.console.dashboard',", $theme);
    self::assertStringContainsString('hide_shell_title: false', $layout);
    self::assertStringContainsString('is_dashboard_route: is_dashboard_route', $layout);
    self::assertStringContainsString("{{ 'Updates'|t }}", $bell);
  }

  /**
   * Ensures the shell title remains subordinate to the page heading.
   */
  public function testShellTitleDoesNotCompeteWithThePageHeading(): void {
    $header = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/templates/includes/vendor-shell-header.html.twig');
    self::assertIsString($header);

    self::assertStringContainsString('<p class="mel-shell-header__title">', $header);
    self::assertStringNotContainsString('<h1 class="mel-shell-header__title">', $header);
  }

  /**
   * Protects current account-settings terminology.
   */
  public function testSupportUsesCurrentAccountSettingsLanguage(): void {
    $builder = file_get_contents(dirname(__DIR__, 3) . '/src/Service/VendorSupportHubBuilder.php');
    self::assertIsString($builder);

    self::assertStringContainsString("\$this->t('Account settings')", $builder);
    self::assertStringNotContainsString("\$this->t('Workspace Settings')", $builder);

    $routing = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_vendor.routing.yml');
    self::assertIsString($routing);
    self::assertStringContainsString("_title: 'Account settings'", $routing);
    self::assertStringContainsString(
      "_form: '\\Drupal\\myeventlane_vendor\\Form\\OrganiserProfileSettingsForm'",
      $routing,
    );
  }

  /**
   * Support uses the shared organiser hierarchy without losing its tools.
   */
  public function testSupportUsesTheSharedOrganiserPresentation(): void {
    $webRoot = dirname(__DIR__, 6);
    $template = file_get_contents($webRoot . '/themes/custom/myeventlane_vendor_theme/templates/support-hub.html.twig');
    $controller = file_get_contents($webRoot . '/modules/custom/myeventlane_escalations_portal/src/Controller/VendorEscalationController.php');
    self::assertIsString($template);
    self::assertIsString($controller);
    self::assertStringContainsString('mel-support-hub mel-organiser-page', $template);
    self::assertStringContainsString('mel-support-hub__tools-grid', $template);
    self::assertStringContainsString('mel-support-hub__reference-grid', $template);
    self::assertStringContainsString('#requests_table', $controller);
    self::assertStringContainsString("'#title' => NULL", $controller);
  }

}
