<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager
 *
 * @group myeventlane_commerce
 */
final class OperationalMerchandiseManagerTest extends UnitTestCase {

  private function manager(): OperationalMerchandiseManager {
    $etm = $this->createMock(\Drupal\Core\Entity\EntityTypeManagerInterface::class);
    $logger = $this->createMock(LoggerInterface::class);
    return new OperationalMerchandiseManager(
      $etm,
      $this->getStringTranslationStub(),
      $logger,
    );
  }

  public function testNormalizeProductFieldValueStripsForbiddenKeys(): void {
    $raw = [
      'operational_product_type' => 'merch_pickup',
      'fulfillment_mode' => 'collect',
      'inventory_quantity' => 99,
      'qr_payload' => 'secret',
      'pickup_mode' => 'counter',
    ];
    $out = $this->manager()->normalizeProductFieldValue($raw);
    $this->assertTrue($out[OperationalMerchandiseManager::CONTRACT_FLAG]);
    $this->assertArrayNotHasKey('inventory_quantity', $out);
    $this->assertArrayNotHasKey('qr_payload', $out);
    $this->assertSame('counter', $out['pickup_mode']);
  }

  public function testBuildCustomerSafeProductPresentationOmitsNonCustomerKeys(): void {
    $raw = [
      'operational_product_type' => 'hospitality_package',
      'operational_summary' => 'VIP lounge',
      'fulfillment_mode' => 'collect',
      'capability_reference' => 'ref-should-not-leak-to-customer-row',
      'operational_chips' => [
        ['label' => 'After check-in', 'tone' => 'info'],
      ],
      'customer_visibility' => 'visible',
      'pickup_mode' => 'counter',
      'readiness_mode' => 'authoring',
    ];
    $safe = $this->manager()->buildCustomerSafeProductPresentation($raw);
    $this->assertTrue($safe[OperationalMerchandiseManager::CONTRACT_FLAG]);
    $this->assertArrayNotHasKey('fulfillment_mode', $safe);
    $this->assertArrayNotHasKey('capability_reference', $safe);
    $this->assertCount(1, $safe['operational_chips']);
    $this->assertSame('After check-in', $safe['operational_chips'][0]['label']);
  }

  public function testStripForbiddenRecursiveRemovesNestedForbidden(): void {
    $data = [
      'outer' => [
        'inner' => [
          'warehouse_ids' => [1],
          'label' => 'ok',
        ],
      ],
      'replay_token' => 'no',
    ];
    $clean = $this->manager()->stripForbiddenRecursive($data);
    $this->assertArrayNotHasKey('replay_token', $clean);
    $this->assertSame(['label' => 'ok'], $clean['outer']['inner']);
  }

}
