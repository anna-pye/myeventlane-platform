<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_front\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_front\Service\OrganiserHubEditorialService
 * @group myeventlane_front
 */
final class BlogCardBadgeTest extends TestCase {

  /**
   * Mirrors badge priority used by OrganiserHubEditorialService::buildBlogCardBadge().
   */
  private function resolveBadge(bool $isPlaybook, ?string $category, string $audience): ?string {
    if ($isPlaybook) {
      return 'Playbook';
    }
    if ($category !== NULL && $category !== '') {
      return $category;
    }
    return match ($audience) {
      'organiser' => 'Organiser',
      'both' => 'For everyone',
      default => 'Attendee',
    };
  }

  /**
   * @covers ::buildBlogCardBadge
   */
  public function testPlaybookTakesPriority(): void {
    $this->assertSame('Playbook', $this->resolveBadge(TRUE, 'Marketing', 'attendee'));
  }

  /**
   * @covers ::buildBlogCardBadge
   */
  public function testOrganiserCategoryWhenNotPlaybook(): void {
    $this->assertSame('Marketing', $this->resolveBadge(FALSE, 'Marketing', 'organiser'));
  }

  /**
   * @covers ::buildBlogCardBadge
   */
  public function testAudienceFallback(): void {
    $this->assertSame('For everyone', $this->resolveBadge(FALSE, NULL, 'both'));
    $this->assertSame('Attendee', $this->resolveBadge(FALSE, NULL, 'attendee'));
  }

}
