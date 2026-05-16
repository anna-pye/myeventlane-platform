<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\myeventlane_commerce\Service\EventOperationalAddonBuilder;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\EventOperationalAddonBuilder
 *
 * @group myeventlane_commerce
 */
final class EventOperationalAddonBuilderTest extends UnitTestCase {

  private function builder(): EventOperationalAddonBuilder {
    $etm = $this->createMock(\Drupal\Core\Entity\EntityTypeManagerInterface::class);
    $logger = $this->createMock(LoggerInterface::class);
    $merch = new OperationalMerchandiseManager(
      $etm,
      $this->getStringTranslationStub(),
      $logger,
    );
    return new EventOperationalAddonBuilder(
      $etm,
      $merch,
      $this->getStringTranslationStub(),
    );
  }

  public function testSanitizeAddonStripsForbiddenKeys(): void {
    $dirty = [
      'title' => 'Tee',
      'qr_payload' => 'secret',
      'nested' => [
        'scanner_secret' => 'x',
        'ok' => 1,
      ],
    ];
    $clean = $this->builder()->sanitizeAddon($dirty);
    $this->assertSame('Tee', $clean['title']);
    $this->assertArrayNotHasKey('qr_payload', $clean);
    $this->assertArrayNotHasKey('scanner_secret', $clean['nested']);
    $this->assertSame(1, $clean['nested']['ok']);
  }

  public function testSanitizeAddonStripsStockAndWarehouseKeys(): void {
    $dirty = [
      'stock_count' => 5,
      'warehouse_ids' => [1],
      'vendor_margin' => '10',
    ];
    $clean = $this->builder()->sanitizeAddon($dirty);
    $this->assertSame([], $clean);
  }

}
