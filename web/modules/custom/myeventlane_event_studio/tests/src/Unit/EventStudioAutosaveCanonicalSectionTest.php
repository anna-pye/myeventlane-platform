<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\myeventlane_event_studio\Service\EventStudioAutosaveService;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Autosave draft key canonicalisation for shared information form sections.
 *
 * @group myeventlane_event_studio
 */
final class EventStudioAutosaveCanonicalSectionTest extends UnitTestCase {

  public function testScheduleAndVenueShareInformationDraftKey(): void {
    $store = $this->createMock(PrivateTempStore::class);
    $bag = [];
    $store->method('set')->willReturnCallback(
      static function (string $key, mixed $value) use (&$bag): void {
        $bag[$key] = $value;
      },
    );
    $store->method('get')->willReturnCallback(
      static function (string $key) use (&$bag): mixed {
        return $bag[$key] ?? NULL;
      },
    );
    $store->method('delete')->willReturnCallback(
      static function (string $key) use (&$bag): void {
        unset($bag[$key]);
      },
    );

    $factory = $this->createMock(PrivateTempStoreFactory::class);
    $factory->method('get')->willReturn($store);
    $logger = $this->createMock(LoggerInterface::class);
    $service = new EventStudioAutosaveService($factory, $logger);

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(42);
    $node->method('getChangedTime')->willReturn(100);
    $node->method('getRevisionId')->willReturn(7);

    $this->assertSame('information', $service->canonicalSection('schedule'));
    $this->assertSame('information', $service->canonicalSection('venue'));
    $this->assertSame('information', $service->canonicalSection('details'));
    $this->assertSame('information', $service->canonicalSection('information'));
    $this->assertSame('tickets', $service->canonicalSection('tickets'));

    $service->storeDraft($node, 'schedule', ['title' => 'From schedule'], 1.0, 100, 7);

    $this->assertArrayHasKey('node.42.information', $bag);
    $this->assertArrayNotHasKey('node.42.schedule', $bag);
    $this->assertTrue($service->hasDraft($node, 'venue'));
    $this->assertTrue($service->hasDraft($node, 'information'));
    $this->assertSame('From schedule', $service->getDraft($node, 'details')['mel']['title'] ?? NULL);

    $service->clearDraft($node, 'venue');
    $this->assertFalse($service->hasDraft($node, 'schedule'));
    $this->assertFalse($service->hasDraft($node, 'information'));
  }

  public function testLegacyAliasDraftMigratesToCanonicalKey(): void {
    $store = $this->createMock(PrivateTempStore::class);
    $bag = [
      'node.9.venue' => [
        'event_id' => 9,
        'section' => 'venue',
        'mel' => ['title' => 'Legacy venue draft'],
        'autosave_ts' => 1.0,
        'base_changed' => 50,
        'base_revision_id' => 3,
        'stored_at' => time(),
      ],
    ];
    $store->method('set')->willReturnCallback(
      static function (string $key, mixed $value) use (&$bag): void {
        $bag[$key] = $value;
      },
    );
    $store->method('get')->willReturnCallback(
      static function (string $key) use (&$bag): mixed {
        return $bag[$key] ?? NULL;
      },
    );
    $store->method('delete')->willReturnCallback(
      static function (string $key) use (&$bag): void {
        unset($bag[$key]);
      },
    );

    $factory = $this->createMock(PrivateTempStoreFactory::class);
    $factory->method('get')->willReturn($store);
    $logger = $this->createMock(LoggerInterface::class);
    $service = new EventStudioAutosaveService($factory, $logger);

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(9);
    $node->method('getChangedTime')->willReturn(50);
    $node->method('getRevisionId')->willReturn(3);

    $draft = $service->getDraft($node, 'information');
    $this->assertNotNull($draft);
    $this->assertSame('Legacy venue draft', $draft['mel']['title'] ?? NULL);
    $this->assertArrayHasKey('node.9.information', $bag);
    $this->assertArrayNotHasKey('node.9.venue', $bag);
  }

}
