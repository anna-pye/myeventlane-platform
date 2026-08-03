<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_auth\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_auth\Service\EmailUserAuthentication;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserAuthenticationInterface;
use Drupal\user\UserInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_auth\Service\EmailUserAuthentication
 * @group myeventlane_auth
 */
final class EmailUserAuthenticationTest extends UnitTestCase {

  /**
   * @covers ::lookupAccount
   * @covers ::authenticateAccount
   */
  public function testEmailResolvesWithoutChangingPrivateUsername(): void {
    $account = $this->createMock(UserInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects(self::once())
      ->method('loadByProperties')
      ->with(['mail' => 'person@example.test'])
      ->willReturn([42 => $account]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('user')->willReturn($storage);

    $inner = $this->createMock(UserAuthenticationInterface::class);
    $inner->expects(self::once())
      ->method('authenticateAccount')
      ->with($account, 'password')
      ->willReturn(TRUE);

    $authentication = new EmailUserAuthentication($inner, $entity_type_manager);
    self::assertSame($account, $authentication->lookupAccount(' person@example.test '));
    self::assertTrue($authentication->authenticateAccount($account, 'password'));
  }

  /**
   * @covers ::lookupAccount
   */
  public function testNonEmailIdentifierDelegatesForInternalCompatibility(): void {
    $account = $this->createMock(UserInterface::class);
    $inner = $this->createMock(UserAuthenticationInterface::class);
    $inner->expects(self::once())
      ->method('lookupAccount')
      ->with('mel_private_identifier')
      ->willReturn($account);

    $authentication = new EmailUserAuthentication(
      $inner,
      $this->createMock(EntityTypeManagerInterface::class),
    );
    self::assertSame($account, $authentication->lookupAccount('mel_private_identifier'));
  }

}
