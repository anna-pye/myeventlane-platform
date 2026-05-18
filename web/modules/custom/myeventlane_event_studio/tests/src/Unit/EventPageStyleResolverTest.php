<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\myeventlane_event_studio\Service\EventPageStyleResolver;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\EventPageStyleResolver
 *
 * @group myeventlane_event_studio
 */
final class EventPageStyleResolverTest extends UnitTestCase {

  private EventPageStyleResolver $resolver;

  protected function setUp(): void {
    parent::setUp();
    $this->resolver = new EventPageStyleResolver();
  }

  /**
   * @covers ::sanitizeStyle
   * @covers ::sanitizeColour
   */
  public function testInvalidValuesSanitizeToDefaults(): void {
    $this->assertSame(EventPageStyleResolver::STYLE_CLASSIC, $this->resolver->sanitizeStyle('not-a-style'));
    $this->assertSame(EventPageStyleResolver::COLOUR_CORAL, $this->resolver->sanitizeColour('neon'));
  }

  /**
   * @covers ::resolveForPublicRender
   */
  public function testMissingFieldsDefaultClassicCoral(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturn(FALSE);

    $resolved = $this->resolver->resolveForPublicRender($node);

    $this->assertSame(EventPageStyleResolver::STYLE_CLASSIC, $resolved['style']);
    $this->assertSame(EventPageStyleResolver::COLOUR_CORAL, $resolved['colour']);
    $this->assertSame(
      ['mel-event-page--classic', 'mel-event-page--colour-coral'],
      $resolved['classes']
    );
  }

  /**
   * @covers ::resolveForPublicRender
   */
  public function testStoredValuesResolveToClasses(): void {
    $style_field = new class () {
      public function isEmpty(): bool {
        return FALSE;
      }
      public string $value = 'immersive';
    };

    $colour_field = new class () {
      public function isEmpty(): bool {
        return FALSE;
      }
      public string $value = 'purple';
    };

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnMap([
      ['field_mel_page_style', TRUE],
      ['field_mel_theme_colour', TRUE],
    ]);
    $node->method('get')->willReturnMap([
      ['field_mel_page_style', $style_field],
      ['field_mel_theme_colour', $colour_field],
    ]);

    $resolved = $this->resolver->resolveForPublicRender($node);

    $this->assertSame('immersive', $resolved['style']);
    $this->assertSame('purple', $resolved['colour']);
    $this->assertContains('mel-event-page--immersive', $resolved['classes']);
    $this->assertContains('mel-event-page--colour-purple', $resolved['classes']);
  }

}
