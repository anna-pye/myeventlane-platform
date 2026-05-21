<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event\Unit;

use Drupal\myeventlane_event\Service\EventSidebarCarouselBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_event\Service\EventSidebarCarouselBuilder
 * @group myeventlane_event
 */
final class EventSidebarCarouselBuilderTest extends UnitTestCase {

  /**
   * @covers ::buildFromEventPageVariables
   */
  public function testBuildIncludesBookingAndGallerySlides(): void {
    $builder = new EventSidebarCarouselBuilder($this->getStringTranslationStub());
    $node = $this->createMock(NodeInterface::class);

    $result = $builder->buildFromEventPageVariables([
      'node' => $node,
      'mel_event_gallery' => [
        'count' => 2,
        'items' => [
          ['index' => 0, 'alt' => 'A', 'urls' => ['card' => '/a.jpg']],
          ['index' => 1, 'alt' => 'B', 'urls' => ['card' => '/b.jpg']],
        ],
      ],
      'mel_show_booking_trust_copy' => TRUE,
      'google_calendar_url' => 'https://calendar.example/add',
    ]);

    $this->assertSame(3, $result['count']);
    $types = array_column($result['slides'], 'type');
    $this->assertSame(['booking', 'gallery', 'trust'], $types);
    $this->assertCount(2, $result['slides'][1]['items']);
  }

}
