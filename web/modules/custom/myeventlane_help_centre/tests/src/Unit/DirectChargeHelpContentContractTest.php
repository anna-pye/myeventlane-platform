<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_help_centre\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards required Stage 14 direct-charge Help Centre coverage.
 */
final class DirectChargeHelpContentContractTest extends TestCase {

  private const REQUIRED = [
    'how_to_request_refund',
    'how_ticket_payments_work',
    'connecting_stripe',
    'receiving_ticket_money',
    'understanding_stripe_payouts',
    'mel_and_stripe_fees',
    'organiser_refunds',
    'cancelling_an_event',
    'disputes_and_chargebacks',
    'stripe_payment_delays',
    'stripe_needs_attention',
    'updating_bank_account',
    'reconciling_sales_with_stripe',
    'mel_stripe_responsibilities',
    'refund_policy',
    'trust_and_safety',
  ];

  public function testAllRequiredSubjectsHaveOneStableSeed(): void {
    $path = dirname(__DIR__, 7) . '/config/sync/myeventlane_help_centre.help_content.yml';
    $config = Yaml::parseFile($path);
    $articles = $config['help_articles'] ?? [];
    $install = Yaml::parseFile(dirname(__DIR__, 3) . '/config/install/myeventlane_help_centre.help_content.yml');
    $installArticles = $install['help_articles'] ?? [];

    foreach (self::REQUIRED as $key) {
      self::assertArrayHasKey($key, $articles);
      self::assertArrayHasKey($key, $installArticles);
      self::assertSame($key, $articles[$key]['seed_key']);
      self::assertSame($articles[$key], $installArticles[$key]);
      self::assertNotEmpty($articles[$key]['body']);
      self::assertNotEmpty($articles[$key]['alias']);
    }
  }

  public function testCanonicalPaymentAndRefundMeaningsArePresent(): void {
    $path = dirname(__DIR__, 7) . '/config/sync/myeventlane_help_centre.help_content.yml';
    $config = Yaml::parseFile($path);
    $articles = $config['help_articles'];

    self::assertStringContainsString('MyEventLane does not hold or manually release your ticket-sale funds.', $articles['how_ticket_payments_work']['body']);
    self::assertStringContainsString('the refunded money comes from your connected Stripe account', $articles['organiser_refunds']['body']);
    self::assertStringContainsString('cannot decide the dispute', $articles['disputes_and_chargebacks']['body']);
  }

  public function testSeederCanMigrateAnExistingArticleByAlias(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Service/HelpContentSeeder.php');
    self::assertIsString($source);
    self::assertStringContainsString("getStorage('path_alias')", $source);
    self::assertStringContainsString("node->bundle() === 'help_article'", $source);

    $config = Yaml::parseFile(dirname(__DIR__, 7) . '/config/sync/myeventlane_help_centre.help_content.yml');
    self::assertSame(
      ['/help/payouts-and-fees'],
      $config['help_articles']['how_ticket_payments_work']['legacy_aliases'],
    );
    self::assertStringContainsString("(array) (\$item['legacy_aliases'] ?? [])", $source);
  }

  public function testDeploymentUpdateSeedsAllGovernedArticlesBeforeConfigImport(): void {
    $install = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_help_centre.install');
    self::assertIsString($install);
    self::assertStringContainsString('function myeventlane_help_centre_update_10038()', $install);
    self::assertStringContainsString("getEditable('myeventlane_help_centre.help_content')", $install);
    foreach (self::REQUIRED as $key) {
      self::assertStringContainsString("'{$key}'", $install);
    }
  }

  public function testLegacyAliasLookupKeepsCanonicalFirstAndRemovesDuplicates(): void {
    require_once dirname(__DIR__, 3) . '/src/Service/HelpContentSeeder.php';
    $reflection = new \ReflectionClass(\Drupal\myeventlane_help_centre\Service\HelpContentSeeder::class);
    $method = $reflection->getMethod('buildAliasCandidates');

    self::assertSame(
      ['/help/vendors/payouts-and-fees', '/help/payouts-and-fees'],
      $method->invoke(NULL, '/help/vendors/payouts-and-fees', [
        ' /help/payouts-and-fees ',
        '/help/vendors/payouts-and-fees',
        '',
      ]),
    );
  }

}
