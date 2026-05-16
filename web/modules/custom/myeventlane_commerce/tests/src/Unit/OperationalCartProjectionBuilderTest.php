<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\myeventlane_commerce\Service\OperationalCartProjectionBuilder;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\OperationalCartProjectionBuilder
 *
 * @group myeventlane_commerce
 */
final class OperationalCartProjectionBuilderTest extends UnitTestCase {

  private function builder(): OperationalCartProjectionBuilder {
    return new OperationalCartProjectionBuilder($this->getStringTranslationStub());
  }

  public function testBuildMobileSectionsMapsGroupsAndCards(): void {
    $contract = [
      'operational_banners' => [['tone' => 'info']],
      'checkout_groups' => [
        [
          'group_key' => 'merchandise',
          'label' => 'Merch',
          'lines' => [
            [
              'product_id' => 9,
              'quantity' => '2',
              'presentation' => [
                'operational_summary' => 'Pickup counter',
                'operational_chips' => [
                  ['label' => 'After entry', 'tone' => 'info'],
                ],
              ],
            ],
          ],
        ],
      ],
    ];
    $out = $this->builder()->buildMobileSections($contract);
    $this->assertSame(1, $out['banner_count']);
    $this->assertCount(1, $out['sections']);
    $this->assertSame('merchandise', $out['sections'][0]['group_key']);
    $this->assertSame('Pickup counter', $out['sections'][0]['cards'][0]['title']);
    $this->assertCount(1, $out['sections'][0]['cards'][0]['chips']);
  }

}
