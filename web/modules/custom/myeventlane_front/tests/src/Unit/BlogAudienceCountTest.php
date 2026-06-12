<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_front\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @group myeventlane_front
 */
final class BlogAudienceCountTest extends TestCase {

  /**
   * Mirrors audience matching used by BlogLandingPageBuilder::countByAudience().
   */
  private function matchesAudience(?string $storedAudience, string $cardAudience): bool {
    $resolved = ($storedAudience === NULL || $storedAudience === '') ? 'attendee' : $storedAudience;
    return match ($cardAudience) {
      'both' => $resolved === 'both',
      'attendee' => in_array($resolved, ['attendee', 'both'], TRUE),
      'organiser' => in_array($resolved, ['organiser', 'both'], TRUE),
      default => FALSE,
    };
  }

  public function testEmptyAudienceCountsAsAttendee(): void {
    $this->assertTrue($this->matchesAudience(NULL, 'attendee'));
    $this->assertFalse($this->matchesAudience(NULL, 'organiser'));
  }

  public function testBothAudienceIncludedInAttendeeAndOrganiserCards(): void {
    $this->assertTrue($this->matchesAudience('both', 'attendee'));
    $this->assertTrue($this->matchesAudience('both', 'organiser'));
    $this->assertTrue($this->matchesAudience('both', 'both'));
  }

}
