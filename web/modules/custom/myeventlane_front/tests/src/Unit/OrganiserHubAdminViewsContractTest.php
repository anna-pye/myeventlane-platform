<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_front\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the Organiser Hub administration Views contract.
 *
 * @group myeventlane_front
 */
final class OrganiserHubAdminViewsContractTest extends TestCase {

  public function testCoverageUsesItsOwnTaxonomyBasedView(): void {
    $root = dirname(__DIR__, 7);
    $editorial = Yaml::parseFile($root . '/config/sync/views.view.mel_organiser_hub_admin.yml');
    $coverage = Yaml::parseFile($root . '/config/sync/views.view.mel_organiser_hub_coverage.yml');

    self::assertSame('node_field_data', $editorial['base_table']);
    self::assertArrayNotHasKey('page_coverage', $editorial['display']);
    self::assertSame('taxonomy_term_field_data', $coverage['base_table']);
    self::assertSame(
      'admin/content/organiser-hub/coverage',
      $coverage['display']['page_coverage']['display_options']['path'],
    );
    self::assertSame(
      'access content overview',
      $coverage['display']['default']['display_options']['access']['options']['perm'],
    );
  }

  public function testCalculatedFieldsDoNotAddDatabaseColumns(): void {
    $root = dirname(__DIR__, 7);
    $pluginDirectory = $root . '/web/modules/custom/myeventlane_front/src/Plugin/views/field/';

    foreach ([
      'OrganiserHubCategoryCoverage.php',
      'OrganiserHubCompleteness.php',
      'OrganiserHubDownloadCount.php',
      'OrganiserHubLinkingIssues.php',
    ] as $plugin) {
      $source = file_get_contents($pluginDirectory . $plugin);
      self::assertIsString($source);
      self::assertStringContainsString('public function query()', $source, $plugin);
      self::assertStringNotContainsString('parent::query()', $source, $plugin);
    }
  }

  public function testCalculatedMarkupUsesRenderArrays(): void {
    $root = dirname(__DIR__, 7);
    $pluginDirectory = $root . '/web/modules/custom/myeventlane_front/src/Plugin/views/field/';

    foreach ([
      'OrganiserHubCategoryCoverage.php',
      'OrganiserHubCompleteness.php',
      'OrganiserHubLinkingIssues.php',
    ] as $plugin) {
      $source = file_get_contents($pluginDirectory . $plugin);
      self::assertIsString($source);
      self::assertStringContainsString("return ['#markup' =>", $source, $plugin);
    }
  }

}
