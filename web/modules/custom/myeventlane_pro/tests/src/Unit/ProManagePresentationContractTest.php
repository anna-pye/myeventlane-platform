<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the organiser-facing MEL Pro subscription home.
 *
 * @group myeventlane_pro
 */
final class ProManagePresentationContractTest extends TestCase {

  public function testManagePageExplainsPlanToolsAndCancellationTiming(): void {
    $template = file_get_contents(dirname(__DIR__, 3) . '/templates/vendor-pro-manage.html.twig');
    self::assertIsString($template);

    self::assertStringContainsString("'Your Pro toolkit'|t", $template);
    self::assertStringContainsString("'Deeper analytics'|t", $template);
    self::assertStringContainsString("'Pro email templates'|t", $template);
    self::assertStringContainsString("'Marketing and branding tools'|t", $template);
    self::assertStringContainsString("'Cancel at period end'|t", $template);
    self::assertStringContainsString("'Your Pro access will continue until @date.'|t", $template);
  }

  public function testZeroRoiUsesPlainLanguage(): void {
    $template = file_get_contents(dirname(__DIR__, 3) . '/templates/vendor-pro-manage.html.twig');
    self::assertIsString($template);

    self::assertStringContainsString('roi_summary.roi_multiple > 0', $template);
    self::assertStringContainsString('No recovered revenue has been attributed', $template);
  }

  public function testCancellationRemainsAvailableWhenLegacyConfigKeyIsMissing(): void {
    $service = file_get_contents(dirname(__DIR__, 3) . '/src/Service/ProSubscriptionStatusService.php');
    self::assertIsString($service);

    self::assertStringContainsString("get('cancel_request_enabled') ?? TRUE", $service);
  }

  public function testUpgradePageUsesVerifiedBenefitLanguageAndOneSubscribeForm(): void {
    $template = file_get_contents(dirname(__DIR__, 3) . '/templates/vendor-pro-overview.html.twig');
    self::assertIsString($template);

    self::assertStringContainsString("'Advanced organiser analytics'|t", $template);
    self::assertStringContainsString("'Pro email templates'|t", $template);
    self::assertStringContainsString('Cancel at the end of any billing period.', $template);
    self::assertSame(1, substr_count($template, '{{ subscribe_form }}'));
    self::assertStringNotContainsString('Automated refunds', $template);
    self::assertStringNotContainsString('Revenue Growth', $template);
  }

}
