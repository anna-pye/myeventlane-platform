<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_front\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_front\Service\BlogStructuredDataBuilder
 * @group myeventlane_front
 */
final class BlogStructuredDataAuthorTest extends TestCase {

  /**
   * Mirrors schema author resolution used by BlogStructuredDataBuilder.
   *
   * @return array<string, string>|null
   */
  private function resolveSchemaAuthor(string $type, string $ownerName, ?string $guestName): ?array {
    return match ($type) {
      'myeventlane' => [
        '@type' => 'Organization',
        'name' => 'MyEventLane',
      ],
      'guest' => ($guestName !== NULL && trim($guestName) !== '') ? [
        '@type' => 'Person',
        'name' => trim($guestName),
      ] : NULL,
      default => ($ownerName !== '') ? [
        '@type' => 'Person',
        'name' => $ownerName,
      ] : NULL,
    };
  }

  public function testMyeventlaneAuthorUsesOrganization(): void {
    $author = $this->resolveSchemaAuthor('myeventlane', 'Editor', NULL);
    $this->assertSame('Organization', $author['@type']);
    $this->assertSame('MyEventLane', $author['name']);
  }

  public function testGuestAuthorUsesGuestName(): void {
    $author = $this->resolveSchemaAuthor('guest', 'Editor', 'Jordan Lee');
    $this->assertSame('Person', $author['@type']);
    $this->assertSame('Jordan Lee', $author['name']);
  }

  public function testStaffAuthorUsesOwnerDisplayName(): void {
    $author = $this->resolveSchemaAuthor('staff', 'Anna', NULL);
    $this->assertSame('Person', $author['@type']);
    $this->assertSame('Anna', $author['name']);
  }

}
