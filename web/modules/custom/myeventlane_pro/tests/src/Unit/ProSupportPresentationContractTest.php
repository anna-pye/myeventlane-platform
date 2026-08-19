<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the dedicated organiser-facing MEL Pro support journey.
 *
 * @group myeventlane_pro
 */
final class ProSupportPresentationContractTest extends TestCase {

  /**
   * The manage and plan pages link to dedicated Pro guidance.
   */
  public function testProPagesUseDedicatedSupportRoute(): void {
    $root = dirname(__DIR__, 3);
    $manage = file_get_contents($root . '/templates/vendor-pro-manage.html.twig');
    $overview = file_get_contents($root . '/templates/vendor-pro-overview.html.twig');
    $routing = file_get_contents($root . '/myeventlane_pro.routing.yml');

    self::assertIsString($manage);
    self::assertIsString($overview);
    self::assertIsString($routing);
    self::assertStringContainsString("path('myeventlane_pro.support')", $manage);
    self::assertStringContainsString("path('myeventlane_pro.support')", $overview);
    self::assertStringContainsString("path: '/vendor/pro/support'", $routing);
  }

  /**
   * Guidance covers the supported Pro lifecycle without mixing Connect billing.
   */
  public function testSupportPageExplainsVerifiedLifecycle(): void {
    $template = file_get_contents(dirname(__DIR__, 3) . '/templates/vendor-pro-support.html.twig');

    self::assertIsString($template);
    self::assertStringContainsString('30-day free trial', $template);
    self::assertStringContainsString('available once per organiser', $template);
    self::assertStringContainsString('seven-day grace period', $template);
    self::assertStringContainsString('cancel at period end', $template);
    self::assertStringContainsString('Reactivate from Manage Pro', $template);
    self::assertStringContainsString('without another trial', $template);
    self::assertStringContainsString('billed separately from your event-ticket and Connect payments', $template);
    self::assertStringContainsString('No Boost payment is created', $template);
  }

  /**
   * The page provides accessible navigation and disclosure controls.
   */
  public function testSupportPageHasAccessibleStructure(): void {
    $template = file_get_contents(dirname(__DIR__, 3) . '/templates/vendor-pro-support.html.twig');

    self::assertIsString($template);
    self::assertStringContainsString('aria-label=', $template);
    self::assertStringContainsString('aria-labelledby=', $template);
    self::assertStringContainsString('<details>', $template);
    self::assertStringContainsString('<summary>', $template);
    self::assertStringContainsString("'Contact Pro support'|t", $template);
    self::assertStringNotContainsString('<main class=', $template);
  }

  /**
   * The Vendor Studio shell identifies the page as part of MEL Pro.
   */
  public function testVendorShellUsesProTitle(): void {
    $theme = file_get_contents(
      dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme',
    );

    self::assertIsString($theme);
    self::assertStringContainsString("'myeventlane_pro.support'", $theme);
  }

}
