<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the canonical workspace parity checker.
 *
 * @coversDefaultClass \Drupal\myeventlane_vendor\Service\EventVendorAccessChecker
 *
 * @group myeventlane_vendor
 */
final class EventVendorAccessCheckerTest extends TestCase {

  /**
   * The checker under test.
   */
  private EventVendorAccessChecker $checker;

  /**
   * {@inheritdoc}
   */
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

  /**
   * @covers ::accountHasWorkspaceParityForEvent
   */
  public function testAnonymousDenied(): void {
    $account = $this->account(0);
    $vendor = $this->vendor(ownerId: 20, teamUserIds: [30]);
    $event = $this->event(authorId: 99, vendor: $vendor);
    $this->assertFalse($this->checker->accountHasWorkspaceParityForEvent($event, $account));
  }

  /**
   * @covers ::accountHasWorkspaceParityForEvent
   */
  public function testAuthenticatedNonOrganiserDenied(): void {
    $account = $this->account(50);
    $vendor = $this->vendor(ownerId: 20, teamUserIds: [30]);
    $event = $this->event(authorId: 99, vendor: $vendor);
    $this->assertFalse($this->checker->accountHasWorkspaceParityForEvent($event, $account));
  }

  /**
   * Staff bypass is intentionally NOT granted by the checker.
   *
   * @covers ::accountHasWorkspaceParityForEvent
   */
  public function testAdministratorWithoutMembershipDeniedByChecker(): void {
    $account = $this->account(1);
    $vendor = $this->vendor(ownerId: 20, teamUserIds: [30]);
    $event = $this->event(authorId: 99, vendor: $vendor);
    $this->assertFalse($this->checker->accountHasWorkspaceParityForEvent($event, $account));
  }

  /**
   * @covers ::accountHasWorkspaceParityForEvent
   */
  public function testNonEventBundleDeniedEvenForAuthor(): void {
    $account = $this->account(10);
    $event = $this->event(authorId: 10, vendor: NULL, bundle: 'page');
    $this->assertFalse($this->checker->accountHasWorkspaceParityForEvent($event, $account));
  }

  /**
   * Builds a stub account.
   */
  private function account(int $uid): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    return $account;
  }

  /**
   * Builds a stub event node.
   */
  private function event(int $authorId, ?object $vendor = NULL, string $bundle = 'event'): NodeInterface {
    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn($bundle);
    $event->method('getOwnerId')->willReturn($authorId);
    $event->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_event_vendor' && $vendor !== NULL,
    );

    if ($vendor === NULL) {
      return $event;
    }

    $list = new class($vendor) {

      /**
       * Linked vendor entity stub.
       *
       * @var object
       */
      public object $entity;

      /**
       * Constructs the field item list stub.
       */
      public function __construct(object $vendor) {
        $this->entity = $vendor;
      }

      /**
       * Reports whether the field is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $event->method('get')->with('field_event_vendor')->willReturn($list);
    return $event;
  }

  /**
   * Builds a stub vendor entity.
   *
   * @param int $ownerId
   *   Vendor entity owner UID.
   * @param list<int> $teamUserIds
   *   Team member UIDs on field_vendor_users.
   */
  private function vendor(int $ownerId, array $teamUserIds): object {
    return new class($ownerId, $teamUserIds) {

      /**
       * Constructs the vendor stub.
       *
       * @param int $ownerId
       *   Vendor owner UID.
       * @param list<int> $teamUserIds
       *   Team member UIDs.
       */
      public function __construct(
        private readonly int $ownerId,
        private readonly array $teamUserIds,
      ) {}

      /**
       * Returns the vendor owner UID.
       */
      public function getOwnerId(): int {
        return $this->ownerId;
      }

      /**
       * Reports whether a field exists.
       */
      public function hasField(string $field): bool {
        return $field === 'field_vendor_users';
      }

      /**
       * Returns a field item list stub.
       */
      public function get(string $field): object {
        $values = array_map(
          static fn (int $uid): array => ['target_id' => $uid],
          $this->teamUserIds,
        );
        return new class($values) {

          /**
           * Constructs the values stub.
           *
           * @param list<array{target_id: int}> $values
           *   Field values.
           */
          public function __construct(private readonly array $values) {}

          /**
           * Returns field values.
           *
           * @return list<array{target_id: int}>
           *   Field values.
           */
          public function getValue(): array {
            return $this->values;
          }

        };
      }

    };
  }

}
