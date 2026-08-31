<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_help_centre\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the organiser event-collection Help Centre guide.
 */
final class EventCollectionHelpContentContractTest extends TestCase {

  public function testShippedAndExportedGuideMatch(): void {
    $root = dirname(__DIR__, 7);
    $sync = Yaml::parseFile($root . '/config/sync/myeventlane_help_centre.help_content.yml');
    $install = Yaml::parseFile($root . '/web/modules/custom/myeventlane_help_centre/config/install/myeventlane_help_centre.help_content.yml');
    $key = 'managing_event_collection';

    self::assertSame($sync['help_articles'][$key], $install['help_articles'][$key]);
    self::assertSame('/help/organisers/setting-up-and-managing-event-collection', $sync['help_articles'][$key]['alias']);
    self::assertStringContainsString('does not mark an item as collected', $sync['help_articles'][$key]['body']);
    self::assertStringContainsString('Use Add-on orders', $sync['help_articles'][$key]['body']);
  }

  public function testUpdateSeedsOnlyTheCollectionGuide(): void {
    $root = dirname(__DIR__, 7);
    $install = (string) file_get_contents($root . '/web/modules/custom/myeventlane_help_centre/myeventlane_help_centre.install');

    self::assertStringContainsString('function myeventlane_help_centre_update_10040()', $install);
    self::assertStringContainsString("\$seedKey = 'managing_event_collection';", $install);
    self::assertStringContainsString("seedHelpArticles([\$seedKey])", $install);
  }

}
