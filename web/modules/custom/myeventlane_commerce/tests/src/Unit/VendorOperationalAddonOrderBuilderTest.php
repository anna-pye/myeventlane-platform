<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\myeventlane_commerce\Service\VendorOperationalAddonOrderBuilder;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\VendorOperationalAddonOrderBuilder
 *
 * @group myeventlane_commerce
 */
final class VendorOperationalAddonOrderBuilderTest extends UnitTestCase {

  private function stripHelper(): VendorOperationalAddonOrderBuilder {
    $ref = new \ReflectionClass(VendorOperationalAddonOrderBuilder::class);
    /** @var \Drupal\myeventlane_commerce\Service\VendorOperationalAddonOrderBuilder $o */
    $o = $ref->newInstanceWithoutConstructor();
    return $o;
  }

  public function testForbiddenKeysListIncludesVendorFields(): void {
    $keys = VendorOperationalAddonOrderBuilder::FORBIDDEN_VENDOR_ORDER_KEYS;
    $this->assertContains('attendee_answers', $keys);
    $this->assertContains('qr_payload', $keys);
    $this->assertContains('warehouse_ids', $keys);
  }

  public function testStripForbiddenRemovesNestedKeys(): void {
    $data = [
      'a' => 1,
      'qr_payload' => ['x' => 1],
      'nested' => [
        'scanner_secret' => 'nope',
        'attendee_answers' => ['q' => 'a'],
        'ok' => 'yes',
      ],
    ];
    $out = $this->stripHelper()->stripForbiddenRecursive($data);
    $json = json_encode($out);
    $this->assertStringNotContainsString('qr_payload', (string) $json);
    $this->assertStringNotContainsString('scanner_secret', (string) $json);
    $this->assertStringNotContainsString('attendee_answers', (string) $json);
    $this->assertStringContainsString('yes', (string) $json);
  }

  public function testStripPreservesListKeysForChips(): void {
    $data = [
      'chips' => [
        0 => ['label' => 'A', 'tone' => 'info'],
        1 => ['label' => 'B', 'tone' => 'muted'],
      ],
    ];
    $out = $this->stripHelper()->stripForbiddenRecursive($data);
    $this->assertSame([0, 1], array_keys($out['chips']));
  }

}
