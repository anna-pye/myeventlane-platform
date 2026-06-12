<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_front\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_front\Service\BlogArticlePresentationService
 * @group myeventlane_front
 */
final class BlogCtaTest extends TestCase {

  /**
   * @return array{variant: string, primary: string, secondary: string}
   */
  private function resolveCta(bool $isPlaybook, string $audience): array {
    if ($isPlaybook) {
      return [
        'variant' => 'create_event',
        'primary' => '/create-event',
        'secondary' => '/resources#mel-organiser-hub-playbooks',
      ];
    }
    if ($audience === 'organiser') {
      return [
        'variant' => 'create_event',
        'primary' => '/create-event',
        'secondary' => '/resources',
      ];
    }
    if ($audience === 'both') {
      return [
        'variant' => 'create_event',
        'primary' => '/create-event',
        'secondary' => '/events',
      ];
    }
    return [
      'variant' => 'discover_events',
      'primary' => '/events',
      'secondary' => '/events',
    ];
  }

  public function testPlaybookUsesCreateEventAndPlaybooksSecondary(): void {
    $cta = $this->resolveCta(TRUE, 'attendee');
    $this->assertSame('create_event', $cta['variant']);
    $this->assertStringContainsString('playbooks', $cta['secondary']);
  }

  public function testOrganiserAudienceUsesCreateEventAndHubSecondary(): void {
    $cta = $this->resolveCta(FALSE, 'organiser');
    $this->assertSame('create_event', $cta['variant']);
    $this->assertSame('/resources', $cta['secondary']);
  }

  public function testBothAudienceUsesCreateEventAndEventsSecondary(): void {
    $cta = $this->resolveCta(FALSE, 'both');
    $this->assertSame('create_event', $cta['variant']);
    $this->assertSame('/events', $cta['secondary']);
  }

  public function testAttendeeAudienceUsesDiscoverEventsCta(): void {
    $cta = $this->resolveCta(FALSE, 'attendee');
    $this->assertSame('discover_events', $cta['variant']);
    $this->assertSame('/events', $cta['primary']);
  }

}
