<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_event\Service\TicketTierDeletionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event\Service\TicketTierDeletionGuard
 *
 * @group myeventlane_event
 */
final class TicketTierDeletionGuardTest extends TestCase {

  public function testAllowsDeletionWithoutOperationalReferences(): void {
    $guard = $this->guardWithCounts([]);

    $result = $guard->evaluate($this->ticket());

    $this->assertTrue($result['allowed']);
    $this->assertSame([], $result['blockers']);
    $this->assertSame([
      'order_items' => 0,
      'issued_tickets' => 0,
      'waitlist_entries' => 0,
      'access_codes' => 0,
    ], $result['counts']);
  }

  /**
   * @param array<string, int> $counts
   * @param list<string> $expectedBlockers
   */
  #[DataProvider('blockingReferenceProvider')]
  public function testBlocksDeletionForOperationalReferences(array $counts, array $expectedBlockers): void {
    $guard = $this->guardWithCounts($counts);

    $result = $guard->evaluate($this->ticket());

    $this->assertFalse($result['allowed']);
    $this->assertSame($expectedBlockers, $result['blockers']);
  }

  /**
   * @return iterable<string, array{array<string, int>, list<string>}>
   */
  public static function blockingReferenceProvider(): iterable {
    yield 'order item' => [['commerce_order_item' => 1], ['order_items']];
    yield 'issued ticket' => [['myeventlane_ticket' => 1], ['issued_tickets']];
    yield 'waitlist entry' => [['mel_ticket_waitlist_entry' => 1], ['waitlist_entries']];
    yield 'access code' => [['mel_access_code' => 1], ['access_codes']];
  }

  public function testFailsClosedWhenInspectionThrows(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('hasDefinition')->willReturn(TRUE);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willThrowException(new \RuntimeException('Storage unavailable.'));
    $entityTypeManager->method('getStorage')->willReturn($storage);

    $guard = new TicketTierDeletionGuard($entityTypeManager, $this->loggerFactory());
    $result = $guard->evaluate($this->ticket());

    $this->assertFalse($result['allowed']);
    $this->assertSame(['inspection_failed'], $result['blockers']);
  }

  public function testFailsClosedWhenRequiredReferenceTypeIsUnavailable(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('hasDefinition')->willReturn(FALSE);

    $guard = new TicketTierDeletionGuard($entityTypeManager, $this->loggerFactory());
    $result = $guard->evaluate($this->ticket());

    $this->assertFalse($result['allowed']);
    $this->assertSame(['inspection_failed'], $result['blockers']);
  }

  /**
   * @param array<string, int> $counts
   */
  private function guardWithCounts(array $counts): TicketTierDeletionGuard {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('hasDefinition')->willReturn(TRUE);
    $entityTypeManager->method('getStorage')->willReturnCallback(
      function (string $entityTypeId) use ($counts): EntityStorageInterface {
        $query = $this->createMock(QueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('condition')->willReturnSelf();
        $query->method('count')->willReturnSelf();
        $query->method('execute')->willReturn($counts[$entityTypeId] ?? 0);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);
        return $storage;
      },
    );

    return new TicketTierDeletionGuard($entityTypeManager, $this->loggerFactory());
  }

  private function ticket(): TicketTypeInterface {
    $variation = $this->createMock(FieldItemListInterface::class);
    $variation->method('isEmpty')->willReturn(FALSE);
    $variation->method('__get')->with('target_id')->willReturn(501);

    $ticket = $this->createMock(TicketTypeInterface::class);
    $ticket->method('id')->willReturn(42);
    $ticket->method('hasField')->with('commerce_variation')->willReturn(TRUE);
    $ticket->method('get')->with('commerce_variation')->willReturn($variation);
    return $ticket;
  }

  private function loggerFactory(): LoggerChannelFactoryInterface {
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    return $factory;
  }

}
