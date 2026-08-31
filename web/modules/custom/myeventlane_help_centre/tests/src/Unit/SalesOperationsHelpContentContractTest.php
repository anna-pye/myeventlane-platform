<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_help_centre\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the organiser sales-operations Help Centre guide.
 */
final class SalesOperationsHelpContentContractTest extends TestCase {

  public function testShippedAndExportedGuideMatch(): void {
    $root = dirname(__DIR__, 7);
    $sync = Yaml::parseFile($root . '/config/sync/myeventlane_help_centre.help_content.yml');
    $install = Yaml::parseFile($root . '/web/modules/custom/myeventlane_help_centre/config/install/myeventlane_help_centre.help_content.yml');
    $key = 'managing_event_sales_operations';

    self::assertSame($sync['help_articles'][$key], $install['help_articles'][$key]);
    self::assertSame('/help/organisers/managing-event-sales-orders-add-ons-and-refunds', $sync['help_articles'][$key]['alias']);
    self::assertStringContainsString('original payment method', $sync['help_articles'][$key]['body']);
    self::assertStringContainsString('status says completed', $sync['help_articles'][$key]['body']);
  }

  public function testUpdateSeedsOnlyTheNewGuide(): void {
    $root = dirname(__DIR__, 7);
    $install = (string) file_get_contents($root . '/web/modules/custom/myeventlane_help_centre/myeventlane_help_centre.install');

    self::assertStringContainsString('function myeventlane_help_centre_update_10039()', $install);
    self::assertStringContainsString("seedHelpArticles([\$seedKey])", $install);
  }

  public function testRefundContextFallsBackToTheNewGuide(): void {
    $root = dirname(__DIR__, 7);
    $resolver = (string) file_get_contents($root . '/web/modules/custom/myeventlane_help_centre/src/Service/ContextualHelpResolver.php');

    self::assertStringContainsString("'refunds_help' => ['V008', 'H003']", $resolver);
    self::assertStringContainsString("'V008' => 'Managing event orders, add-ons and refunds'", $resolver);
  }

}
