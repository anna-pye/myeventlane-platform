<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

require_once dirname(__DIR__, 3) . '/src/Service/OrganiserTaxProfileManager.php';

use Drupal\myeventlane_vendor\Service\OrganiserTaxProfileManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests organiser tax profile rules that do not require Drupal storage.
 *
 * @group myeventlane_vendor
 */
final class OrganiserTaxProfileManagerTest extends TestCase {

  /**
   * Tests valid and invalid Australian Business Number checksums.
   */
  public function testAbnChecksum(): void {
    $manager = new OrganiserTaxProfileManager();

    self::assertTrue($manager->isValidAbn('11 304 813 593'));
    self::assertFalse($manager->isValidAbn('11 304 813 594'));
    self::assertFalse($manager->isValidAbn('1234'));
  }

}
