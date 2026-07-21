<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkin\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_checkin\Service\CheckInAttendeeEventBinder;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Attendee↔event bind for check-in toggle (PII-08).
 *
 * @coversDefaultClass \Drupal\myeventlane_checkin\Service\CheckInAttendeeEventBinder
 *
 * @group myeventlane_checkin
 */
final class CheckInAttendeeEventBindTest extends TestCase {

  /**
   * @covers ::belongsToEvent
   */
  public function testRsvpBelongsToRouteEvent(): void {
    $rsvp = new class {

      public function getEventId(): int {
        return 10;
      }

    };

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(5)->willReturn($rsvp);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->with('rsvp_submission')->willReturn(TRUE);
    $etm->method('getStorage')->with('rsvp_submission')->willReturn($storage);

    $this->assertTrue($this->binder($etm)->belongsToEvent($this->event(10), 5, 'rsvp'));
  }

  /**
   * @covers ::belongsToEvent
   */
  public function testForeignRsvpDenied(): void {
    $rsvp = new class {

      public function getEventId(): int {
        return 99;
      }

    };

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(5)->willReturn($rsvp);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->with('rsvp_submission')->willReturn(TRUE);
    $etm->method('getStorage')->with('rsvp_submission')->willReturn($storage);

    $this->assertFalse($this->binder($etm)->belongsToEvent($this->event(10), 5, 'rsvp'));
  }

  /**
   * @covers ::belongsToEvent
   */
  public function testMissingRsvpDeniedWithoutDisclosure(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn(NULL);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->with('rsvp_submission')->willReturn(TRUE);
    $etm->method('getStorage')->with('rsvp_submission')->willReturn($storage);

    $this->assertFalse($this->binder($etm)->belongsToEvent($this->event(10), 5, 'rsvp'));
  }

  /**
   * @covers ::belongsToEvent
   */
  public function testTicketBelongsToRouteEvent(): void {
    $attendee = $this->createMock(EventAttendee::class);
    $attendee->method('hasField')->with('event')->willReturn(TRUE);
    $attendee->method('get')->with('event')->willReturn(new class {
      public function isEmpty(): bool {
        return FALSE;
      }

      public int $target_id = 10;
    });

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(7)->willReturn($attendee);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->willReturnCallback(
      static fn (string $id): bool => $id === 'event_attendee'
    );
    $etm->method('getStorage')->with('event_attendee')->willReturn($storage);

    $this->assertTrue($this->binder($etm)->belongsToEvent($this->event(10), 7, 'ticket'));
  }

  /**
   * @covers ::belongsToEvent
   */
  public function testTogglePathRequiresBindInControllerAndStorage(): void {
    $controller = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Controller/CheckInController.php');
    $storage = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Service/CheckInStorage.php');
    $this->assertStringContainsString('attendeeBelongsToEvent', $controller);
    $this->assertStringContainsString('AccessDeniedHttpException', $controller);
    $this->assertStringContainsString('attendeeBelongsToEvent', $storage);
    $this->assertStringContainsString('AccessDeniedHttpException', $storage);
    $this->assertStringContainsString('NodeInterface $event', $storage);
  }

  private function binder(EntityTypeManagerInterface $etm): CheckInAttendeeEventBinder {
    return new CheckInAttendeeEventBinder($etm, $this->loggerFactory());
  }

  private function event(int $nid): NodeInterface {
    $event = $this->createMock(NodeInterface::class);
    $event->method('id')->willReturn($nid);
    return $event;
  }

  private function loggerFactory(): LoggerChannelFactoryInterface {
    $channel = $this->createMock(LoggerChannelInterface::class);
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($channel);
    return $factory;
  }

}
