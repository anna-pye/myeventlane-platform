<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_auth\Unit;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\myeventlane_auth\Service\PublicRegistrationUsernameGenerator;
use Drupal\Tests\UnitTestCase;

/**
 * Tests private public-registration username generation.
 *
 * @group myeventlane_auth
 */
final class PublicRegistrationUsernameGeneratorTest extends UnitTestCase {

  /**
   * The generator retries collisions and never derives identity from email.
   */
  public function testCollisionRetryProducesOpaqueUniqueUsername(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->expects($this->exactly(2))
      ->method('execute')
      ->willReturnOnConsecutiveCalls([1], []);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('user')->willReturn($storage);

    $uuid = $this->createMock(UuidInterface::class);
    $uuid->expects($this->exactly(2))
      ->method('generate')
      ->willReturnOnConsecutiveCalls(
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
      );

    $generator = new PublicRegistrationUsernameGenerator($entity_type_manager, $uuid);
    $username = $generator->generate();

    $this->assertSame('mel_bbbbbbbbbbbb4bbb8bbbbbbb', $username);
    $this->assertDoesNotMatchRegularExpression('/@|customer|example/i', $username);
  }

}
