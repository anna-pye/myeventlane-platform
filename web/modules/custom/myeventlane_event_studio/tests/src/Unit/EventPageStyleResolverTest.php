<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_event_studio\Service\EventPageStyleResolver;
use Drupal\myeventlane_event_studio\Service\EventStyleAccessManager;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\EventPageStyleResolver
 *
 * @group myeventlane_event_studio
 */
final class EventPageStyleResolverTest extends UnitTestCase {

  /**
   * @covers ::sanitizeStyle
   * @covers ::sanitizeColour
   */
  public function testInvalidValuesSanitizeToDefaults(): void {
    $resolver = new EventPageStyleResolver();

    $this->assertSame(EventPageStyleResolver::STYLE_CLASSIC, $resolver->sanitizeStyle('not-a-style'));
    $this->assertSame(EventPageStyleResolver::COLOUR_CORAL, $resolver->sanitizeColour('neon'));
  }

  /**
   * @covers ::resolveForPublicRender
   */
  public function testMissingFieldsDefaultClassicCoral(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturn(FALSE);

    $resolved = (new EventPageStyleResolver())->resolveForPublicRender($node);

    $this->assertSame(EventPageStyleResolver::STYLE_CLASSIC, $resolved['style']);
    $this->assertSame(EventPageStyleResolver::COLOUR_CORAL, $resolved['colour']);
    $this->assertSame(
      ['mel-event-page--classic', 'mel-event-page--colour-coral'],
      $resolved['classes']
    );
    $this->assertFalse($resolved['is_pro_style']);
    $this->assertFalse($resolved['is_customized']);
  }

  /**
   * @covers ::resolveForPublicRender
   */
  public function testNonProOwnerStoredImmersivePurpleResolvesClassicCoral(): void {
    $owner = $this->createVendorAccount(FALSE);
    $node = $this->createEventNodeWithStyle('immersive', 'purple', 42);

    $resolver = new EventPageStyleResolver(
      new EventStyleAccessManager(NULL),
      $this->createOwnerEntityTypeManager($owner, 42),
    );
    $resolved = $resolver->resolveForPublicRender($node);

    $this->assertSame(EventPageStyleResolver::STYLE_CLASSIC, $resolved['style']);
    $this->assertSame(EventPageStyleResolver::COLOUR_CORAL, $resolved['colour']);
    $this->assertContains('mel-event-page--classic', $resolved['classes']);
    $this->assertContains('mel-event-page--colour-coral', $resolved['classes']);
    $this->assertNotContains('mel-event-page--immersive', $resolved['classes']);
    $this->assertNotContains('mel-event-page--colour-purple', $resolved['classes']);
  }

  /**
   * @covers ::resolveForPublicRender
   */
  public function testProOwnerStoredImmersivePurpleResolvesImmersivePurple(): void {
    $owner = $this->createVendorAccount(TRUE);
    $node = $this->createEventNodeWithStyle('immersive', 'purple', 99);

    $resolver = new EventPageStyleResolver(
      new EventStyleAccessManager(),
      $this->createOwnerEntityTypeManager($owner, 99),
    );
    $resolved = $resolver->resolveForPublicRender($node);

    $this->assertSame(EventPageStyleResolver::STYLE_IMMERSIVE, $resolved['style']);
    $this->assertSame(EventPageStyleResolver::COLOUR_PURPLE, $resolved['colour']);
    $this->assertContains('mel-event-page--immersive', $resolved['classes']);
    $this->assertTrue($resolved['is_pro_style']);
  }

  /**
   * @covers ::resolveForPersistence
   */
  public function testPersistenceDowngradesProChoicesForNonPro(): void {
    $node = $this->createMock(NodeInterface::class);
    $account = $this->createVendorAccount(FALSE);

    $resolver = new EventPageStyleResolver(new EventStyleAccessManager(NULL));
    $resolved = $resolver->resolveForPersistence($node, [
      'field_mel_page_style' => EventPageStyleResolver::STYLE_IMMERSIVE,
      'field_mel_theme_colour' => EventPageStyleResolver::COLOUR_PURPLE,
    ], $account);

    $this->assertSame(EventPageStyleResolver::STYLE_CLASSIC, $resolved['style']);
    $this->assertSame(EventPageStyleResolver::COLOUR_CORAL, $resolved['colour']);
  }

  /**
   * @covers ::resolveForPersistence
   */
  public function testPersistenceAllowsProChoicesForAdmin(): void {
    $node = $this->createMock(NodeInterface::class);
    $account = $this->createVendorAccount(TRUE);

    $resolver = new EventPageStyleResolver(new EventStyleAccessManager());
    $resolved = $resolver->resolveForPersistence($node, [
      'field_mel_page_style' => EventPageStyleResolver::STYLE_IMMERSIVE,
      'field_mel_theme_colour' => EventPageStyleResolver::COLOUR_MINT,
    ], $account);

    $this->assertSame(EventPageStyleResolver::STYLE_IMMERSIVE, $resolved['style']);
    $this->assertSame(EventPageStyleResolver::COLOUR_MINT, $resolved['colour']);
  }

  /**
   * @covers ::colourOptions
   */
  public function testColourLabelsIncludeFriendlyNames(): void {
    $labels = (new EventPageStyleResolver())->colourOptions();

    $this->assertSame('Coral Pop', $labels[EventPageStyleResolver::COLOUR_CORAL]);
    $this->assertSame('Violet Pulse', $labels[EventPageStyleResolver::COLOUR_PURPLE]);
    $this->assertArrayHasKey(EventPageStyleResolver::COLOUR_MINT, $labels);
  }

  /**
   * @covers ::styleOptions
   */
  public function testStyleLabelsIncludeFriendlyNames(): void {
    $labels = (new EventPageStyleResolver())->styleOptions();

    $this->assertSame('Warm & Energetic', $labels[EventPageStyleResolver::STYLE_CLASSIC]);
    $this->assertSame('Bold & Immersive', $labels[EventPageStyleResolver::STYLE_IMMERSIVE]);
  }

  private function createEventNodeWithStyle(string $style, string $colour, int $uid): NodeInterface {
    $style_field = new class ($style) {
      public function __construct(public string $value) {}
      public function isEmpty(): bool {
        return FALSE;
      }
    };

    $colour_field = new class ($colour) {
      public function __construct(public string $value) {}
      public function isEmpty(): bool {
        return FALSE;
      }
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
    $node->method('getOwnerId')->willReturn($uid);

    return $node;
  }

  private function createOwnerEntityTypeManager(UserInterface $owner, int $uid): EntityTypeManagerInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with($uid)->willReturn($owner);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('user')->willReturn($storage);

    return $entity_type_manager;
  }

  private function createVendorAccount(bool $is_admin): AccountInterface&UserInterface {
    $account = $this->createMock(UserInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(static fn (string $permission): bool => $is_admin && $permission === 'administer nodes');

    return $account;
  }

}
