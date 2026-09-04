<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
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
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $service = new EventStudioAutosaveService($factory, $logger, $entityTypeManager);

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
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $service = new EventStudioAutosaveService($factory, $logger, $entityTypeManager);

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

  /**
   * Equivalent revision bookkeeping does not invalidate a submission.
   */
  public function testEquivalentBaseRevisionIsNotStale(): void {
    $factory = $this->createMock(PrivateTempStoreFactory::class);
    $logger = $this->createMock(LoggerInterface::class);
    $storage = $this->createMock(RevisionableStorageInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('node')->willReturn($storage);

    $base = $this->eventRevision(42, 7, 100, 'Untitled event');
    $current = $this->eventRevision(42, 8, 100, 'Untitled event');
    $storage->method('loadRevision')->with(7)->willReturn($base);

    $service = new EventStudioAutosaveService($factory, $logger, $entityTypeManager);

    $this->assertFalse($service->isStaleSubmission($current, 100, 7));
  }

  /**
   * A real field change still invalidates a submission.
   */
  public function testChangedContentRemainsStale(): void {
    $factory = $this->createMock(PrivateTempStoreFactory::class);
    $logger = $this->createMock(LoggerInterface::class);
    $storage = $this->createMock(RevisionableStorageInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('node')->willReturn($storage);

    $base = $this->eventRevision(42, 7, 100, 'Untitled event');
    $current = $this->eventRevision(42, 8, 100, 'Updated event');
    $storage->method('loadRevision')->with(7)->willReturn($base);

    $service = new EventStudioAutosaveService($factory, $logger, $entityTypeManager);

    $this->assertTrue($service->isStaleSubmission($current, 100, 7));
  }

  /**
   * A revision belonging to another event cannot bypass the safety check.
   */
  public function testOtherEventBaseRevisionRemainsStale(): void {
    $factory = $this->createMock(PrivateTempStoreFactory::class);
    $logger = $this->createMock(LoggerInterface::class);
    $storage = $this->createMock(RevisionableStorageInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('node')->willReturn($storage);

    $otherEvent = $this->eventRevision(99, 7, 100, 'Untitled event');
    $current = $this->eventRevision(42, 8, 100, 'Untitled event');
    $storage->method('loadRevision')->with(7)->willReturn($otherEvent);

    $service = new EventStudioAutosaveService($factory, $logger, $entityTypeManager);

    $this->assertTrue($service->isStaleSubmission($current, 100, 7));
  }

  /**
   * A newer changed timestamp still invalidates a submission immediately.
   */
  public function testNewerChangedTimeRemainsStale(): void {
    $factory = $this->createMock(PrivateTempStoreFactory::class);
    $logger = $this->createMock(LoggerInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())->method('getStorage');
    $current = $this->eventRevision(42, 8, 101, 'Updated event');

    $service = new EventStudioAutosaveService($factory, $logger, $entityTypeManager);

    $this->assertTrue($service->isStaleSubmission($current, 100, 7));
  }

  /**
   * Builds a node revision test double with representative field values.
   */
  private function eventRevision(int $id, int $revisionId, int $changed, string $title): NodeInterface {
    $event = $this->createMock(NodeInterface::class);
    $event->method('id')->willReturn($id);
    $event->method('getRevisionId')->willReturn($revisionId);
    $event->method('getChangedTime')->willReturn($changed);
    $event->method('toArray')->willReturn([
      'nid' => [['value' => $id]],
      'vid' => [['value' => $revisionId]],
      'changed' => [['value' => $changed]],
      'title' => [['value' => $title]],
      'revision_timestamp' => [['value' => $changed + $revisionId]],
    ]);

    return $event;
  }

}
