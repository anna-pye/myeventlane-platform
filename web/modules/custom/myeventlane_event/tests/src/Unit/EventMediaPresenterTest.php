<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event\Unit;

use Drupal\myeventlane_event\Service\EventMediaPresenter;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_event\Service\EventMediaPresenter
 * @group myeventlane_event
 */
final class EventMediaPresenterTest extends UnitTestCase {

  /**
   * @covers ::buildGalleryViewModel
   */
  public function testBuildGalleryViewModelEmptyWhenFieldMissing(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('field_mel_event_gallery')->willReturn(FALSE);

    $presenter = new EventMediaPresenter($this->createMock(LoggerInterface::class));
    $result = $presenter->buildGalleryViewModel($node);

    $this->assertSame(EventMediaPresenter::ROLE_GALLERY, $result['role']);
    $this->assertSame(0, $result['count']);
    $this->assertSame([], $result['items']);
  }

  /**
   * @covers ::resolveComposition
   * @dataProvider compositionCountProvider
   */
  public function testResolveComposition(int $count, string $expected): void {
    $presenter = new EventMediaPresenter($this->createMock(LoggerInterface::class));
    $this->assertSame($expected, $presenter->resolveComposition($count));
  }

  /**
   * @return array<string, array{int, string}>
   */
  public static function compositionCountProvider(): array {
    return [
      'empty' => [0, EventMediaPresenter::COMPOSITION_SINGLE],
      'single' => [1, EventMediaPresenter::COMPOSITION_SINGLE],
      'duo' => [2, EventMediaPresenter::COMPOSITION_DUO],
      'trio' => [3, EventMediaPresenter::COMPOSITION_TRIO],
      'editorial' => [6, EventMediaPresenter::COMPOSITION_EDITORIAL],
    ];
  }

  /**
   * @covers ::buildEventMediaViewModel
   */
  public function testBuildEventMediaViewModelNonFullSkipsGalleryItems(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturn(FALSE);

    $presenter = new EventMediaPresenter($this->createMock(LoggerInterface::class));
    $result = $presenter->buildEventMediaViewModel($node, 'teaser');

    $this->assertFalse($result['has_gallery']);
    $this->assertSame(0, $result['gallery']['count']);
  }

}
