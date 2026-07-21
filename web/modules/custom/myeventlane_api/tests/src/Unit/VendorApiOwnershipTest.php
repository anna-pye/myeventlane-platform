<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_api\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\myeventlane_api\Controller\VendorApiBaseController;
use Drupal\myeventlane_api\Service\ApiAuthenticationService;
use Drupal\myeventlane_api\Service\ApiResponseFormatter;
use Drupal\myeventlane_api\Service\RateLimiterService;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Vendor API event ownership.
 *
 * @coversDefaultClass \Drupal\myeventlane_api\Controller\VendorApiBaseController
 *
 * @group myeventlane_api
 */
final class VendorApiOwnershipTest extends TestCase {

  /**
   * Linked vendor with owner parity is allowed.
   */
  public function testLinkedVendorWithOwnerParityAllowed(): void {
    $controller = $this->controller(hasParity: TRUE);
    $vendor = $this->vendor(1, 10);
    $event = $this->event(authorId: 99, linkedVendorId: 1);
    $this->assertTrue($this->invokeOwns($controller, $vendor, $event));
  }

  /**
   * Foreign vendor identity is denied even if owner somehow has parity.
   */
  public function testForeignVendorLinkDenied(): void {
    $controller = $this->controller(hasParity: TRUE);
    $vendor = $this->vendor(1, 10);
    $event = $this->event(authorId: 10, linkedVendorId: 2);
    $this->assertFalse($this->invokeOwns($controller, $vendor, $event));
  }

  /**
   * Unlinked event authored by vendor owner is allowed via parity.
   */
  public function testUnlinkedAuthorOwnedEventAllowed(): void {
    $controller = $this->controller(hasParity: TRUE);
    $vendor = $this->vendor(1, 10);
    $event = $this->event(authorId: 10, linkedVendorId: NULL);
    $this->assertTrue($this->invokeOwns($controller, $vendor, $event));
  }

  /**
   * Foreign vendor without parity is denied.
   */
  public function testForeignVendorWithoutParityDenied(): void {
    $controller = $this->controller(hasParity: FALSE);
    $vendor = $this->vendor(2, 40);
    $event = $this->event(authorId: 99, linkedVendorId: 1);
    $this->assertFalse($this->invokeOwns($controller, $vendor, $event));
  }

  /**
   * Vendor without resolvable owner account is denied.
   */
  public function testVendorWithoutOwnerDenied(): void {
    $controller = $this->controller(hasParity: TRUE, ownerLoadable: FALSE);
    $vendor = $this->vendor(1, 0);
    $event = $this->event(authorId: 10, linkedVendorId: 1);
    $this->assertFalse($this->invokeOwns($controller, $vendor, $event));
  }

  /**
   * Team members are not granted access merely via vendor entity ID on the key.
   *
   * Vendor-wide keys act as the vendor owner account only.
   */
  public function testTeamNotRepresentedByVendorWideKey(): void {
    $raw = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Controller/VendorApiBaseController.php');
    $this->assertStringContainsString('vendor-wide API keys', $raw);
    $this->assertStringContainsString('resolveVendorActingAccount', $raw);
    $this->assertStringContainsString('accountHasWorkspaceParityForEvent', $raw);
    $this->assertStringNotContainsString('field_vendor_users', $raw);
  }

  /**
   * Forbidden API messages must not include customer PII markers.
   */
  public function testForbiddenMessageHasNoPiiMarkers(): void {
    $formatter = new ApiResponseFormatter();
    $response = $formatter->error('FORBIDDEN', 'You do not have access to this event.', 403);
    $content = $response->getContent() ?: '';
    $this->assertStringNotContainsString('@', $content);
    $this->assertStringNotContainsString('customer', strtolower($content));
    $this->assertStringNotContainsString('email', strtolower($content));
    $this->assertStringNotContainsString('order', strtolower($content));
  }

  /**
   * Invokes protected vendorOwnsEvent via reflection.
   */
  private function invokeOwns(VendorApiBaseController $controller, Vendor $vendor, NodeInterface $event): bool {
    $method = new \ReflectionMethod(VendorApiBaseController::class, 'vendorOwnsEvent');
    $method->setAccessible(TRUE);
    return (bool) $method->invoke($controller, $vendor, $event);
  }

  /**
   * Builds a Vendor API base controller with stubbed ownership deps.
   *
   * @param bool $hasParity
   *   Whether the checker reports workspace parity.
   * @param bool $ownerLoadable
   *   Whether the vendor owner user can be loaded.
   */
  private function controller(bool $hasParity, bool $ownerLoadable = TRUE): VendorApiBaseController {
    $checker = $this->createMock(EventVendorAccessCheckerInterface::class);
    $checker->method('accountHasWorkspaceParityForEvent')->willReturn($hasParity);

    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(10);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturnCallback(
      static function ($id) use ($ownerLoadable, $user) {
        if (!$ownerLoadable || (int) $id <= 0) {
          return NULL;
        }
        return $user;
      }
    );

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('user')->willReturn($storage);

    $db = $this->createMock(Connection::class);
    $time = $this->createMock(TimeInterface::class);

    return new class(
      new ApiAuthenticationService($etm),
      new ApiResponseFormatter(),
      new RateLimiterService($db, $time),
      $checker,
      $etm,
    ) extends VendorApiBaseController {};
  }

  /**
   * Builds a stub vendor entity.
   *
   * @param int $id
   *   Vendor entity ID.
   * @param int $ownerId
   *   Vendor owner UID.
   */
  private function vendor(int $id, int $ownerId): Vendor {
    $vendor = $this->createMock(Vendor::class);
    $vendor->method('id')->willReturn($id);
    $vendor->method('getOwnerId')->willReturn($ownerId);
    return $vendor;
  }

  /**
   * Builds a stub event node.
   *
   * @param int $authorId
   *   Event author UID.
   * @param int|null $linkedVendorId
   *   Linked vendor ID, or NULL when unlinked.
   */
  private function event(int $authorId, ?int $linkedVendorId): NodeInterface {
    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn('event');
    $event->method('id')->willReturn(100);
    $event->method('getOwnerId')->willReturn($authorId);
    $event->method('hasField')->willReturnCallback(
      static fn(string $name): bool => $name === 'field_event_vendor'
    );

    if ($linkedVendorId === NULL) {
      $list = new class() {

        /**
         * Reports whether the field is empty.
         */
        public function isEmpty(): bool {
          return TRUE;
        }

      };
    }
    else {
      $linked = $this->createMock(Vendor::class);
      $linked->method('id')->willReturn($linkedVendorId);
      $list = new class($linked) {

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
    }
    $event->method('get')->with('field_event_vendor')->willReturn($list);
    return $event;
  }

}
