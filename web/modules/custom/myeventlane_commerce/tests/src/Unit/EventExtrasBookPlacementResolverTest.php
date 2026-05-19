<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_commerce\Service\EventExtrasBookPlacementResolver;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\EventExtrasBookPlacementResolver
 *
 * @group myeventlane_commerce
 */
final class EventExtrasBookPlacementResolverTest extends UnitTestCase {

  private EventExtrasBookPlacementResolver $resolver;

  protected function setUp(): void {
    parent::setUp();
    $string_translation = $this->getStringTranslationStub();
    $this->resolver = new EventExtrasBookPlacementResolver(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(AccountProxyInterface::class),
      $string_translation,
    );
  }

  /**
   * @covers ::resolve
   */
  public function testMissingFieldReturnsDefault(): void {
    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn('event');
    $event->method('hasField')->willReturn(FALSE);

    $this->assertSame(
      EventExtrasBookPlacementResolver::DEFAULT_PLACEMENT,
      $this->resolver->resolve($event),
    );
    $this->assertTrue($this->resolver->isInline($event));
    $this->assertFalse($this->resolver->isSidebar($event));
  }

  /**
   * @covers ::resolve
   */
  public function testEmptyFieldReturnsDefault(): void {
    $field = new class () {
      public function isEmpty(): bool {
        return TRUE;
      }
    };
    $event = $this->createEventWithField($field);

    $this->assertSame(EventExtrasBookPlacementResolver::PLACEMENT_MAIN_BEFORE_ACCESS, $this->resolver->resolve($event));
  }

  /**
   * @covers ::resolve
   */
  public function testInvalidValueReturnsDefault(): void {
    $field = $this->createFieldValue('not-valid');
    $event = $this->createEventWithField($field);

    $this->assertSame(EventExtrasBookPlacementResolver::DEFAULT_PLACEMENT, $this->resolver->resolve($event));
  }

  /**
   * @covers ::resolve
   * @covers ::isInline
   */
  public function testInlineValueResolves(): void {
    $event = $this->createEventWithField($this->createFieldValue(EventExtrasBookPlacementResolver::PLACEMENT_MAIN_BEFORE_ACCESS));

    $this->assertSame(EventExtrasBookPlacementResolver::PLACEMENT_MAIN_BEFORE_ACCESS, $this->resolver->resolve($event));
    $this->assertTrue($this->resolver->isInline($event));
    $this->assertFalse($this->resolver->isSidebar($event));
  }

  /**
   * @covers ::resolve
   * @covers ::isSidebar
   */
  public function testSidebarValueResolves(): void {
    $event = $this->createEventWithField($this->createFieldValue(EventExtrasBookPlacementResolver::PLACEMENT_SIDEBAR));

    $this->assertSame(EventExtrasBookPlacementResolver::PLACEMENT_SIDEBAR, $this->resolver->resolve($event));
    $this->assertFalse($this->resolver->isInline($event));
    $this->assertTrue($this->resolver->isSidebar($event));
  }

  /**
   * @covers ::labels
   */
  public function testLabelsIncludeBothOptions(): void {
    $labels = $this->resolver->labels();
    $this->assertArrayHasKey(EventExtrasBookPlacementResolver::PLACEMENT_MAIN_BEFORE_ACCESS, $labels);
    $this->assertArrayHasKey(EventExtrasBookPlacementResolver::PLACEMENT_SIDEBAR, $labels);
  }

  private function createEventWithField(object $field): NodeInterface {
    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn('event');
    $event->method('hasField')->with(EventExtrasBookPlacementResolver::FIELD_NAME)->willReturn(TRUE);
    $event->method('get')->with(EventExtrasBookPlacementResolver::FIELD_NAME)->willReturn($field);
    return $event;
  }

  private function createFieldValue(string $value): object {
    return new class ($value) {
      public function __construct(public string $value) {}
      public function isEmpty(): bool {
        return FALSE;
      }
    };
  }

}
