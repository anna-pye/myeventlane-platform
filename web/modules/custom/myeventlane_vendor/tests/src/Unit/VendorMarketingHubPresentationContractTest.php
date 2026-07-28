<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the compact portfolio-level Marketing presentation.
 *
 * @group myeventlane_vendor
 */
final class VendorMarketingHubPresentationContractTest extends TestCase {

  public function testMarketingHubKeepsLargeEventPortfoliosCompact(): void {
    $template = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/templates/marketing-hub.html.twig');
    self::assertIsString($template);

    self::assertStringContainsString('share.events|first', $template);
    self::assertStringContainsString("'Choose another event'|t", $template);
    self::assertStringContainsString('boost.campaigns|slice(0, 5)', $template);
    self::assertStringContainsString('boost.eligible|slice(0, 5)', $template);
    self::assertStringContainsString('widgets.events|slice(0, 5)', $template);
    self::assertStringContainsString('mel-marketing-hub__tools-grid', $template);
  }

  public function testBuilderAvoidsWastefulAndMisleadingPortfolioRows(): void {
    $builder = file_get_contents(dirname(__DIR__, 3) . '/src/Service/VendorMarketingHubBuilder.php');
    self::assertIsString($builder);

    self::assertStringContainsString('$qrGenerated = FALSE;', $builder);
    self::assertStringContainsString("['ended', 'past', 'archived']", $builder);
  }

}
