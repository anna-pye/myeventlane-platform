<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_vendor\Service\EventVendorAccessChecker
 *
 * @group myeventlane_vendor
 */
final class EventVendorAccessCheckerTest extends TestCase {

  private EventVendorAccessChecker $checker;

  protected function setUp(): void {
    parent::setUp();
    $this->checker = new EventVendorAccessChecker();
  }

  /**
   * @covers ::accountHasWorkspaceParityForEvent
   */
  public function testEventAuthorAllowed(): void {
    $account = $this->account(10);
    $event = $this->event(authorId: 10);
    $this->assertTrue($this->checker->accountHasWorkspaceParityForEvent($event, $account));
  }

  /**
   * @covers ::accountHasWorkspaceParityForEvent
   */
  public function testOrganiserEntityOwnerAllowedWhenNotAuthor(): void {
    $account = $this->account(20);
    $vendor = $this->vendor(ownerId: 20, teamUserIds: []);
    $event = $this->event(authorId: 99, vendor: $vendor);
    $this->assertTrue($this->checker->accountHasWorkspaceParityForEvent($event, $account));
  }

  /**
   * @covers ::accountHasWorkspaceParityForEvent
   */
  public function testTeamMemberAllowed(): void {
    $account = $this->account(30);
    $vendor = $this->vendor(ownerId: 20, teamUserIds: [30]);
    $event = $this->event(authorId: 99, vendor: $vendor);
    $this->assertTrue($this->checker->accountHasWorkspaceParityForEvent($event, $account));
  }

  /**
   * @covers ::accountHasWorkspaceParityForEvent
   */
  public function testUnrelatedOrganiserDenied(): void {
    $account = $this->account(40);
    $vendor = $this->vendor(ownerId: 20, teamUserIds: [30]);
    $event = $this->event(authorId: 99, vendor: $vendor);
    $this->assertFalse($this->checker->accountHasWorkspaceParityForEvent($event, $account));
  }

  /**
   * @covers ::accountHasWorkspaceParityForEvent
   */
  public function testRemovedTeamMemberDenied(): void {
    $account = $this->account(30);
    $vendor = $this->vendor(ownerId: 20, teamUserIds: [31]);
    $event = $this->event(authorId: 99, vendor: $vendor);
    $this->assertFalse($this->checker->accountHasWorkspaceParityForEvent($event, $account));
  }

  private function account(int $uid): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    return $account;
  }

  private function event(int $authorId, ?object $vendor = NULL): NodeInterface {
    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn('event');
    $event->method('getOwnerId')->willReturn($authorId);
    $event->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_event_vendor' && $vendor !== NULL,
    );

    if ($vendor === NULL) {
      return $event;
    }

    $list = new class($vendor) {
      public object $entity;

      public function __construct(object $vendor) {
        $this->entity = $vendor;
      }

      public function isEmpty(): bool {
        return FALSE;
      }
    };

    $event->method('get')->with('field_event_vendor')->willReturn($list);
    return $event;
  }

  /**
   * @param list<int> $teamUserIds
   */
  private function vendor(int $ownerId, array $teamUserIds): object {
    return new class($ownerId, $teamUserIds) {
      public function __construct(
        private readonly int $ownerId,
        private readonly array $teamUserIds,
      ) {}

      public function getOwnerId(): int {
        return $this->ownerId;
      }

      public function hasField(string $field): bool {
        return $field === 'field_vendor_users';
      }

      public function get(string $field): object {
        $values = array_map(
          static fn (int $uid): array => ['target_id' => $uid],
          $this->teamUserIds,
        );
        return new class($values) {
          public function __construct(private readonly array $values) {}

          public function getValue(): array {
            return $this->values;
          }
        };
      }
    };
  }

}
