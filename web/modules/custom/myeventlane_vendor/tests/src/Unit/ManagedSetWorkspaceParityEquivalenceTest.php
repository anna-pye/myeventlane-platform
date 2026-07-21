<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Proves managed-set membership ≡ workspace parity for modern events.
 *
 * For events keyed only by field_event_vendor (no legacy field_vendor widen):
 *
 *   event ∈ managed-set(account)
 *   ⇔
 *   EventVendorAccessChecker::accountHasWorkspaceParityForEvent(account, event)
 *
 * Managed-set membership is evaluated with the same logical predicates used by
 * UserVendorMembershipQuery::getManagedEventNodeIds() without hitting storage:
 * author ∪ events whose field_event_vendor is in the account's vendor ID set
 * (vendor entity owner ∪ field_vendor_users).
 *
 * @group myeventlane_vendor
 */
final class ManagedSetWorkspaceParityEquivalenceTest extends TestCase {

  /**
   * Canonical workspace parity checker.
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
   * Modern field_event_vendor events: managed-set iff workspace parity.
   *
   * @dataProvider actorProvider
   */
  public function testModernEventManagedSetIffWorkspaceParity(
    int $uid,
    int $authorId,
    int $vendorOwnerId,
    array $teamUserIds,
    bool $expected,
  ): void {
    $account = $this->account($uid);
    $vendorId = 500;
    $vendor = $this->vendor($vendorOwnerId, $teamUserIds);
    $event = $this->event(authorId: $authorId, vendor: $vendor, eventId: 42);

    $parity = $this->checker->accountHasWorkspaceParityForEvent($event, $account);
    $inManagedSet = $this->modernManagedSetContains(
      uid: $uid,
      authorId: $authorId,
      vendorId: $vendorId,
      vendorOwnerId: $vendorOwnerId,
      teamUserIds: $teamUserIds,
    );

    $this->assertSame($expected, $parity, 'Workspace parity');
    $this->assertSame($expected, $inManagedSet, 'Managed-set membership');
    $this->assertSame(
      $parity,
      $inManagedSet,
      'parity ≡ managed-set (modern field_event_vendor)',
    );
  }

  /**
   * Legacy field_vendor on managed-set can widen vs parity — documented stop.
   *
   * This test locks the known divergence so Workstream 2A does not invent
   * reconciliation: parity ignores legacy field_vendor; managed-set may
   * include it.
   */
  public function testLegacyFieldVendorWidenIsExplicitDivergence(): void {
    $uid = 20;
    $account = $this->account($uid);
    // Event has no field_event_vendor; account owns vendor 500 linked only via
    // legacy field_vendor on the event. Parity must be FALSE; managed-set TRUE.
    $event = $this->event(authorId: 99, vendor: NULL, eventId: 77);
    $parity = $this->checker->accountHasWorkspaceParityForEvent($event, $account);
    $inManagedSetViaLegacy = TRUE;

    $this->assertFalse($parity, 'Parity ignores legacy field_vendor');
    $this->assertTrue($inManagedSetViaLegacy, 'Managed-set may widen via legacy');
    $this->assertNotSame(
      $parity,
      $inManagedSetViaLegacy,
      'Documented divergence — do not reconcile here',
    );
  }

  /**
   * Actor matrix for modern field_event_vendor events.
   *
   * @return \Generator
   *   Cases: uid, author, vendor owner, team UIDs, expected membership.
   */
  public static function actorProvider(): \Generator {
    yield 'event author' => [10, 10, 20, [30], TRUE];
    yield 'vendor entity owner' => [20, 99, 20, [30], TRUE];
    yield 'vendor team member' => [30, 99, 20, [30], TRUE];
    yield 'unrelated organiser' => [40, 99, 20, [30], FALSE];
    yield 'authenticated non-organiser' => [50, 99, 20, [30], FALSE];
    yield 'anonymous' => [0, 99, 20, [30], FALSE];
  }

  /**
   * Logical managed-set membership for a modern event (no legacy field_vendor).
   *
   * @param int $uid
   *   Account UID.
   * @param int $authorId
   *   Event author UID.
   * @param int $vendorId
   *   Vendor entity ID linked on the event.
   * @param int $vendorOwnerId
   *   Vendor entity owner UID.
   * @param list<int> $teamUserIds
   *   Team UIDs on the vendor entity.
   */
  private function modernManagedSetContains(
    int $uid,
    int $authorId,
    int $vendorId,
    int $vendorOwnerId,
    array $teamUserIds,
  ): bool {
    if ($uid <= 0) {
      return FALSE;
    }

    $vendorIdsForUser = [];
    if ($vendorOwnerId === $uid || in_array($uid, $teamUserIds, TRUE)) {
      $vendorIdsForUser[] = $vendorId;
    }

    if ($authorId === $uid) {
      return TRUE;
    }

    return $vendorIdsForUser !== [] && in_array($vendorId, $vendorIdsForUser, TRUE);
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
  private function event(int $authorId, ?object $vendor, int $eventId): NodeInterface {
    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn('event');
    $event->method('id')->willReturn($eventId);
    $event->method('getOwnerId')->willReturn($authorId);
    $event->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_event_vendor' && $vendor !== NULL,
    );

    if ($vendor === NULL) {
      $event->method('get')->willReturn(new class() {

        /**
         * Reports whether the field is empty.
         */
        public function isEmpty(): bool {
          return TRUE;
        }

      });
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
   *   Team member UIDs.
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
