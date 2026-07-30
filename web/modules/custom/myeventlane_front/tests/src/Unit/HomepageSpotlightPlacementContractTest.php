<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_front\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects boosted-event placement on the homepage.
 *
 * @group myeventlane_front
 */
final class HomepageSpotlightPlacementContractTest extends TestCase {

  public function testBrandedHeroDoesNotReserveFirstSpotlightEvent(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $merchandising = (string) file_get_contents(
      $moduleRoot . '/src/Service/HomepageMerchandising.php',
    );
    $homeHero = (string) file_get_contents(
      $moduleRoot . '/src/Plugin/Block/HomeHeroBlock.php',
    );

    $this->assertStringContainsString("'#featured_events' => NULL", $homeHero);
    $this->assertStringContainsString(
      "public function getHeroEventIds(): array {\n    return [];\n  }",
      $merchandising,
    );
    $this->assertStringContainsString(
      '$this->spotlightEventIds = $this->loadMarketplaceReadyPromotedUpcomingEventIds();',
      $merchandising,
    );
    $this->assertStringNotContainsString(
      '$hero = $this->getHeroEventIds();',
      $merchandising,
    );
  }

}
