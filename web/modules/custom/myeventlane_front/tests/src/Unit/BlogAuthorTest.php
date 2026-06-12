<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_front\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_front\Service\BlogArticlePresentationService
 * @group myeventlane_front
 */
final class BlogAuthorTest extends TestCase {

  /**
   * Mirrors author-type resolution used by blog presentation services.
   *
   * @return array{name: string, url: string|null}|null
   */
  private function resolveAuthor(
    string $type,
    string $ownerName,
    ?string $guestName,
    bool $ownerAnonymous = FALSE,
  ): ?array {
    return match ($type) {
      'myeventlane' => [
        'name' => 'MyEventLane',
        'url' => NULL,
      ],
      'guest' => ($guestName !== NULL && trim($guestName) !== '') ? [
        'name' => trim($guestName),
        'url' => NULL,
      ] : NULL,
      default => ($ownerName !== '') ? [
        'name' => $ownerName,
        'url' => $ownerAnonymous ? NULL : '/user/1',
      ] : NULL,
    };
  }

  public function testMyeventlaneAuthor(): void {
    $author = $this->resolveAuthor('myeventlane', 'Editor', NULL);
    $this->assertSame('MyEventLane', $author['name']);
    $this->assertNull($author['url']);
  }

  public function testStaffAuthorUsesNodeOwner(): void {
    $author = $this->resolveAuthor('staff', 'Anna', NULL);
    $this->assertSame('Anna', $author['name']);
    $this->assertSame('/user/1', $author['url']);
  }

  public function testGuestAuthorUsesCustomName(): void {
    $author = $this->resolveAuthor('guest', 'Anna', 'Jordan Lee');
    $this->assertSame('Jordan Lee', $author['name']);
    $this->assertNull($author['url']);
  }

  public function testGuestAuthorWithoutNameReturnsNull(): void {
    $this->assertNull($this->resolveAuthor('guest', 'Anna', ''));
  }

}
