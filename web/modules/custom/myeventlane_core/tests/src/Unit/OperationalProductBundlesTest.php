<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_core\Unit;

use Drupal\myeventlane_core\Commerce\OperationalProductBundles;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_core\Commerce\OperationalProductBundles
 *
 * @group myeventlane_core
 */
final class OperationalProductBundlesTest extends UnitTestCase {

  /**
   * @covers ::productBundleClassifications
   * @covers ::classificationForProductBundle
   */
  public function testOperationalBundleClassificationMap(): void {
    $map = OperationalProductBundles::productBundleClassifications();
    $this->assertSame('merchandise', $map['operational_merchandise']);
    $this->assertSame('addon', $map['operational_bundle']);
    $this->assertSame('addon', $map['hospitality_package']);
    $this->assertSame('addon', $map['timed_collection_product']);
    $this->assertSame('merchandise', OperationalProductBundles::classificationForProductBundle('operational_merchandise'));
    $this->assertSame('', OperationalProductBundles::classificationForProductBundle('default'));
  }

}
