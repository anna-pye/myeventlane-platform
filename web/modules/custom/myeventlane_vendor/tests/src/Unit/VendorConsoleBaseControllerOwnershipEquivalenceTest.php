<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Proves assertEventOwnership delegates to the canonical checker without drift.
 *
 * Side-by-side: legacy inline membership algorithm vs EventVendorAccessChecker
 * vs the thin controller wrapper (for event bundles under console trust).
 *
 * @coversDefaultClass \Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController
 *
 * @group myeventlane_vendor
 */
final class VendorConsoleBaseControllerOwnershipEquivalenceTest extends TestCase {

  /**
   * Canonical checker used for side-by-side comparison.
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
   * Legacy inline membership must match the canonical checker for events.
   *
   * @dataProvider ownershipActorProvider
   */
  public function testLegacyInlineMatchesCanonicalCheckerForEvents(
    int $uid,
    int $authorId,
    int $vendorOwnerId,
    array $teamUserIds,
    bool $expectedParity,
  ): void {
    $account = $this->account($uid);
    $vendor = $this->vendor($vendorOwnerId, $teamUserIds);
    $event = $this->event($authorId, $vendor);

    $legacy = $this->legacyInlineOwnershipAllows($event, $account);
    $canonical = $this->checker->accountHasWorkspaceParityForEvent($event, $account);

    $this->assertSame($expectedParity, $legacy, 'Legacy inline membership');
    $this->assertSame($expectedParity, $canonical, 'Canonical checker');
    $this->assertSame($legacy, $canonical, 'Legacy ≡ canonical');
  }

  /**
   * Thin wrapper must match the canonical checker for event actors.
   *
   * @dataProvider ownershipActorProvider
   */
  public function testWrapperMatchesCanonicalChecker(
    int $uid,
    int $authorId,
    int $vendorOwnerId,
    array $teamUserIds,
    bool $expectedParity,
  ): void {
    $vendor = $this->vendor($vendorOwnerId, $teamUserIds);
    $event = $this->event($authorId, $vendor);
    $controller = $this->controller(
      uid: $uid,
      permissions: ['access vendor console' => TRUE, 'administer nodes' => FALSE],
      checker: $this->checker,
      skipVendorAccess: TRUE,
    );

    if ($expectedParity) {
      $controller->exposeAssertEventOwnership($event);
      $this->addToAssertionCount(1);
      return;
    }

    $this->expectException(AccessDeniedHttpException::class);
    $controller->exposeAssertEventOwnership($event);
  }

  /**
   * Administer-nodes bypass remains on the wrapper, not the checker.
   */
  public function testAdministratorBypassDoesNotRequireParity(): void {
    $vendor = $this->vendor(20, [30]);
    $event = $this->event(99, $vendor);
    $controller = $this->controller(
      uid: 1,
      permissions: [
        'access vendor console' => TRUE,
        'administer nodes' => TRUE,
        'administer site configuration' => TRUE,
      ],
      checker: $this->checker,
      skipVendorAccess: TRUE,
    );

    $controller->exposeAssertEventOwnership($event);
    $this->assertFalse(
      $this->checker->accountHasWorkspaceParityForEvent($event, $this->account(1)),
      'Checker itself must not grant admin',
    );
  }

  /**
   * Anonymous users are denied at console trust before ownership.
   */
  public function testAnonymousDeniedAtConsoleTrust(): void {
    $vendor = $this->vendor(20, [30]);
    $event = $this->event(99, $vendor);
    $controller = $this->controller(
      uid: 0,
      permissions: [],
      checker: $this->checker,
      skipVendorAccess: FALSE,
      anonymous: TRUE,
    );

    $this->expectException(AccessDeniedHttpException::class);
    $controller->exposeAssertEventOwnership($event);
  }

  /**
   * Ownership actor matrix for event bundles.
   *
   * @return iterable<string, array{int, int, int, list<int>, bool}>
   *   Actor fixtures: uid, author, vendor owner, team, expected parity.
   */
  public static function ownershipActorProvider(): iterable {
    yield 'event author' => [10, 10, 20, [30], TRUE];
    yield 'vendor owner' => [20, 99, 20, [30], TRUE];
    yield 'team member' => [30, 99, 20, [30], TRUE];
    yield 'unrelated organiser' => [40, 99, 20, [30], FALSE];
    yield 'authenticated non-organiser' => [50, 99, 20, [30], FALSE];
  }

  /**
   * Pre-Workstream-1 inline membership from VendorConsoleBaseController.
   *
   * Kept here as the equivalence oracle — do not "improve" this copy.
   */
  private function legacyInlineOwnershipAllows(NodeInterface $event, AccountInterface $account): bool {
    if ((int) $event->getOwnerId() === (int) $account->id()) {
      return TRUE;
    }

    if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      $vendor = $event->get('field_event_vendor')->entity;
      if ($vendor) {
        if (method_exists($vendor, 'getOwnerId')
          && (int) $vendor->getOwnerId() === (int) $account->id()) {
          return TRUE;
        }
        if ($vendor->hasField('field_vendor_users')) {
          foreach ($vendor->get('field_vendor_users')->getValue() as $item) {
            if (isset($item['target_id']) && (int) $item['target_id'] === (int) $account->id()) {
              return TRUE;
            }
          }
        }
      }
    }

    return FALSE;
  }

  /**
   * Builds a testable console controller.
   *
   * @param int $uid
   *   Current user ID.
   * @param array<string, bool> $permissions
   *   Permission map for the stub account.
   * @param \Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface $checker
   *   Canonical ownership checker.
   * @param bool $skipVendorAccess
   *   TRUE to skip assertVendorAccess (isolate ownership).
   * @param bool $anonymous
   *   Whether the account is anonymous.
   */
  private function controller(
    int $uid,
    array $permissions,
    EventVendorAccessCheckerInterface $checker,
    bool $skipVendorAccess = TRUE,
    bool $anonymous = FALSE,
  ): TestVendorConsoleController {
    $domain = new DomainDetector(
      new RequestStack(),
      $this->createMock(ConfigFactoryInterface::class),
    );

    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('isAnonymous')->willReturn($anonymous);
    $account->method('hasPermission')->willReturnCallback(
      static fn (string $permission): bool => !empty($permissions[$permission]),
    );
    $account->method('hasRole')->willReturn(FALSE);

    $messenger = $this->createMock(MessengerInterface::class);

    return new TestVendorConsoleController(
      $domain,
      $account,
      $messenger,
      $checker,
      $skipVendorAccess,
    );
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
   * Builds a stub event node with a linked vendor.
   */
  private function event(int $authorId, object $vendor): NodeInterface {
    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn('event');
    $event->method('getOwnerId')->willReturn($authorId);
    $event->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_event_vendor',
    );

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

/**
 * Concrete controller exposing assertEventOwnership for unit tests.
 */
final class TestVendorConsoleController extends VendorConsoleBaseController {

  /**
   * Constructs the test controller.
   *
   * @param \Drupal\myeventlane_core\Service\DomainDetector $domainDetector
   *   Domain detector.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   Current user.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Messenger.
   * @param \Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface $eventVendorAccessChecker
   *   Canonical ownership checker.
   * @param bool $skipVendorAccess
   *   TRUE to skip assertVendorAccess during ownership tests.
   */
  public function __construct(
    DomainDetector $domainDetector,
    AccountProxyInterface $currentUser,
    MessengerInterface $messenger,
    EventVendorAccessCheckerInterface $eventVendorAccessChecker,
    private readonly bool $skipVendorAccess = TRUE,
  ) {
    parent::__construct($domainDetector, $currentUser, $messenger, $eventVendorAccessChecker);
  }

  /**
   * Exposes assertEventOwnership for assertions.
   */
  public function exposeAssertEventOwnership(NodeInterface $event): void {
    $this->assertEventOwnership($event);
  }

  /**
   * {@inheritdoc}
   */
  protected function assertVendorAccess(): void {
    if ($this->skipVendorAccess) {
      return;
    }
    parent::assertVendorAccess();
  }

}
