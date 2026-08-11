<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_governance\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the public Help and Search routes against core Search dependencies.
 */
#[Group('myeventlane_governance')]
final class CoreSearchIndependenceContractTest extends TestCase {

  /**
   * The Help Centre search is a Views surface and does not require core Search.
   */
  public function testHelpCentreSearchIsIndependentOfCoreSearch(): void {
    $moduleDirectory = $this->customModulesDirectory() . '/myeventlane_help_centre';
    $info = Yaml::parseFile($moduleDirectory . '/myeventlane_help_centre.info.yml');
    $routing = Yaml::parseFile($moduleDirectory . '/myeventlane_help_centre.routing.yml');
    $view = Yaml::parseFile($this->configSyncDirectory() . '/views.view.mel_help_search.yml');

    self::assertIsArray($info);
    self::assertIsArray($routing);
    self::assertIsArray($view);
    self::assertNotContains('drupal:search', $info['dependencies'] ?? []);
    self::assertContains('drupal:views', $info['dependencies'] ?? []);
    self::assertSame(
      '\\Drupal\\myeventlane_help_centre\\Controller\\HelpCentreController::searchIndex',
      $routing['myeventlane_help_centre.search']['defaults']['_controller'] ?? NULL,
    );
    self::assertSame('node_field_data', $view['base_table'] ?? NULL);
    self::assertNotContains('search', $view['dependencies']['module'] ?? []);
  }

  /**
   * Public discovery search is backed by Search API, not core Search.
   */
  public function testPublicSearchIsBackedBySearchApi(): void {
    $moduleDirectory = $this->customModulesDirectory() . '/myeventlane_search';
    $info = Yaml::parseFile($moduleDirectory . '/myeventlane_search.info.yml');
    $routing = Yaml::parseFile($moduleDirectory . '/myeventlane_search.routing.yml');

    self::assertIsArray($info);
    self::assertIsArray($routing);
    self::assertContains('search_api:search_api', $info['dependencies'] ?? []);
    self::assertContains('search_api:search_api_db', $info['dependencies'] ?? []);
    self::assertNotContains('drupal:search', $info['dependencies'] ?? []);
    self::assertSame('/search', $routing['mel_search.view']['path'] ?? NULL);
    self::assertSame(
      '\\Drupal\\myeventlane_search\\Controller\\SearchController::build',
      $routing['mel_search.view']['defaults']['_controller'] ?? NULL,
    );
    self::assertSame('/search/autocomplete', $routing['mel_search.autocomplete']['path'] ?? NULL);
    self::assertSame(
      '\\Drupal\\myeventlane_search\\Controller\\SearchAutocompleteController::autocomplete',
      $routing['mel_search.autocomplete']['defaults']['_controller'] ?? NULL,
    );
  }

  /**
   * Returns the custom modules directory.
   */
  private function customModulesDirectory(): string {
    return dirname(__DIR__, 4);
  }

  /**
   * Returns the configuration synchronisation directory.
   */
  private function configSyncDirectory(): string {
    return dirname(__DIR__, 7) . '/config/sync';
  }

}
