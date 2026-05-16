<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\myeventlane_commerce\Service\OperationalAddonGuidanceBuilder;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\OperationalAddonGuidanceBuilder
 *
 * @group myeventlane_commerce
 */
final class OperationalAddonGuidanceBuilderTest extends UnitTestCase {

  private function builder(): OperationalAddonGuidanceBuilder {
    return new OperationalAddonGuidanceBuilder($this->getStringTranslationStub());
  }

  public function testOmitsGuidanceForTicketOnlyComposition(): void {
    $composition = [
      'groups' => [
        'merchandise' => [],
        'hospitality' => [],
        'timed_collection' => [],
        'bundles' => [],
        'parking' => [],
      ],
    ];
    $this->assertSame([], $this->builder()->buildFromComposition($composition));
    $order = $this->createMock(OrderInterface::class);
    $order->method('getCacheTags')->willReturn(['commerce_order:99']);
    $this->assertNull($this->builder()->buildRenderArray($order, $composition));
  }

  public function testReturnsEmptyWhenNoOperationalGroups(): void {
    $composition = [
      'groups' => [
        'merchandise' => [],
        'hospitality' => [],
        'timed_collection' => [],
        'bundles' => [],
        'parking' => [],
      ],
    ];
    $this->assertSame([], $this->builder()->buildFromComposition($composition));
  }

  public function testBuildsMerchGuidance(): void {
    $composition = [
      'groups' => [
        'merchandise' => [
          [
            'presentation' => [
              'operational_summary' => 'Pickup at merch tent',
              'operational_chips' => [
                ['label' => 'After doors', 'tone' => 'info'],
              ],
              'readiness_mode' => 'nominal',
            ],
          ],
        ],
        'hospitality' => [],
        'timed_collection' => [],
        'bundles' => [],
        'parking' => [],
      ],
    ];
    $out = $this->builder()->buildFromComposition($composition);
    $this->assertNotEmpty($out);
    $this->assertArrayHasKey('sections', $out);
    $this->assertCount(1, $out['sections']);
    $this->assertSame('merchandise', $out['sections'][0]['group_key']);
    $this->assertContains('Pickup at merch tent', $out['sections'][0]['summary_lines']);
    $this->assertCount(1, $out['sections'][0]['chips']);
    $this->assertSame('After doors', $out['sections'][0]['chips'][0]['label']);
    $this->assertSame('info', $out['sections'][0]['chips'][0]['tone']);
    $this->assertSame('nominal', $out['sections'][0]['readiness']);
  }

  public function testBuildsHospitalityGuidance(): void {
    $composition = [
      'groups' => [
        'merchandise' => [],
        'hospitality' => [
          [
            'presentation' => [
              'operational_summary' => 'Lounge access included',
              'readiness_mode' => 'attention',
            ],
          ],
        ],
        'timed_collection' => [],
        'bundles' => [],
        'parking' => [],
      ],
    ];
    $checkout = [
      'customer_guidance' => [
        ['type' => 'hospitality', 'body' => 'Hospitality benefits activate according to organiser instructions—usually after admission.'],
      ],
    ];
    $out = $this->builder()->buildFromComposition($composition, $checkout);
    $this->assertCount(1, $out['sections']);
    $this->assertSame('hospitality', $out['sections'][0]['group_key']);
    $this->assertStringContainsString('Hospitality', $out['sections'][0]['next_step']);
    $this->assertStringContainsString('Hospitality benefits activate', $out['sections'][0]['next_step']);
  }

  public function testBuildsTimedCollectionGuidance(): void {
    $composition = [
      'groups' => [
        'merchandise' => [],
        'hospitality' => [],
        'timed_collection' => [
          [
            'presentation' => [
              'operational_summary' => 'Window 2–3pm',
            ],
          ],
        ],
        'bundles' => [],
        'parking' => [],
      ],
    ];
    $checkout = [
      'customer_guidance' => [
        ['type' => 'timed_collection', 'body' => 'Timed collection windows are shown for planning.'],
      ],
    ];
    $out = $this->builder()->buildFromComposition($composition, $checkout);
    $this->assertCount(1, $out['sections']);
    $this->assertSame('timed_collection', $out['sections'][0]['group_key']);
    $this->assertStringContainsString('Timed collection', $out['sections'][0]['next_step']);
  }

  public function testStripsForbiddenKeysRecursively(): void {
    $composition = [
      'groups' => [
        'merchandise' => [
          [
            'presentation' => [
              'operational_summary' => 'Visible',
              'qr_payload' => ['secret' => 'x'],
              'nested' => [
                'scanner_secret' => 'no',
                'inventory_quantity' => 99,
              ],
            ],
          ],
        ],
        'hospitality' => [],
        'timed_collection' => [],
        'bundles' => [],
        'parking' => [],
      ],
    ];
    $out = $this->builder()->buildFromComposition($composition);
    $encoded = json_encode($out);
    $this->assertIsString($encoded);
    $this->assertStringNotContainsString('qr_payload', $encoded);
    $this->assertStringNotContainsString('scanner_secret', $encoded);
    $this->assertStringNotContainsString('inventory_quantity', $encoded);
    $this->assertStringContainsString('Visible', $encoded);
  }

  public function testStripRecursivePreservesListKeys(): void {
    $nested = [
      'chips' => [
        0 => ['label' => 'A', 'tone' => 'info'],
        1 => ['label' => 'B', 'tone' => 'muted'],
      ],
    ];
    $stripped = $this->builder()->stripForbiddenRecursive($nested);
    $this->assertSame([0, 1], array_keys($stripped['chips']));
  }

  public function testDoesNotInventShippingQrScannerCopyInMerchSection(): void {
    $composition = [
      'groups' => [
        'merchandise' => [
          ['presentation' => ['operational_summary' => 'Hat']],
        ],
        'hospitality' => [],
        'timed_collection' => [],
        'bundles' => [],
        'parking' => [],
      ],
    ];
    $out = $this->builder()->buildFromComposition($composition);
    $step = (string) ($out['sections'][0]['next_step'] ?? '');
    $this->assertStringNotContainsStringIgnoringCase('QR', $step);
    $this->assertStringNotContainsStringIgnoringCase('scanner', $step);
    $this->assertStringNotContainsStringIgnoringCase('ship', $step);
    $hint = (string) ($out['support_hint'] ?? '');
    $this->assertStringContainsStringIgnoringCase('Shipping', $hint);
    $this->assertStringContainsStringIgnoringCase('QR', $hint);
  }

  public function testBuildRenderArrayReturnsNullWhenEmpty(): void {
    $order = $this->createMock(OrderInterface::class);
    $order->method('getCacheTags')->willReturn(['commerce_order:1']);
    $composition = [
      'groups' => [
        'merchandise' => [],
        'hospitality' => [],
        'timed_collection' => [],
        'bundles' => [],
        'parking' => [],
      ],
    ];
    $this->assertNull($this->builder()->buildRenderArray($order, $composition));
  }

}
