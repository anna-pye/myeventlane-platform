<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_escalations_ai\Unit;

use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\myeventlane_escalations_ai\Service\EscalationAiJobEnqueuer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests short-lived duplicate suppression before queue writes.
 */
#[Group('myeventlane_escalations_ai')]
final class EscalationAiJobEnqueuerTest extends TestCase {

  /**
   * Tests that an active marker prevents another queue write.
   */
  public function testDuplicateIsNotQueued(): void {
    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->expects($this->never())->method('get');
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->expects($this->once())->method('has')->with('reply_suggestion:42')->willReturn(TRUE);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $lock->expects($this->once())->method('release');

    $service = new EscalationAiJobEnqueuer(
      $queueFactory,
      $store,
      $lock,
      $this->createMock(LoggerInterface::class),
    );

    $this->assertFalse($service->enqueue(42, 'reply_suggestion', 1));
  }

  /**
   * Tests that the first request is marked and queued.
   */
  public function testFirstRequestIsQueuedAndMarked(): void {
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->once())->method('createItem')->with($this->callback(
      static fn (array $item): bool => $item['escalation_id'] === 42 && $item['task'] === 'reply_suggestion',
    ));
    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->method('get')->willReturn($queue);
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('has')->willReturn(FALSE);
    $store->expects($this->once())->method('setWithExpire')->with(
      'reply_suggestion:42',
      $this->callback(static fn (mixed $value): bool => is_int($value)),
      300,
    );
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);

    $service = new EscalationAiJobEnqueuer(
      $queueFactory,
      $store,
      $lock,
      $this->createMock(LoggerInterface::class),
    );

    $this->assertTrue($service->enqueue(42, 'reply_suggestion', 1));
  }

}
