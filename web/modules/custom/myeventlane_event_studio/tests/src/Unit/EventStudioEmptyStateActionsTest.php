<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event_studio\Service\EventStudioEmptyStateBuilder;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\EventStudioEmptyStateBuilder
 * @group myeventlane_event_studio
 */
final class EventStudioEmptyStateActionsTest extends UnitTestCase {

  /**
   * @covers ::build
   */
  public function testBuildPassesActionsIntoThemeVariables(): void {
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')->willReturnCallback(
      static fn (string $string, array $args = [], array $options = []) => $string,
    );

    $builder = new EventStudioEmptyStateBuilder($translation);
    $actions = [
      '#type' => 'link',
      '#title' => 'Go to publishing',
      '#url' => Url::fromRoute('<front>'),
    ];
    $build = $builder->build(
      'Marketing',
      'Publish first.',
      'Then share.',
      ['Finish publishing first.'],
      'spark',
      'default',
      $actions,
    );

    $this->assertSame('mel_event_studio_empty_state', $build['#theme']);
    $this->assertSame($actions, $build['#actions']);
  }

  public function testMarketingDraftCtaUsesThemeActionsNotSiblingKey(): void {
    $renderer = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioSectionRenderer.php');
    $template = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio-empty-state.html.twig');
    $module = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_studio.module');
    $this->assertIsString($renderer);
    $this->assertIsString($template);
    $this->assertIsString($module);

    $this->assertStringContainsString("'#actions' => \$actions", file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioEmptyStateBuilder.php'));
    $this->assertStringContainsString("mel-event-studio-empty-state__actions", $template);
    $this->assertStringContainsString("'actions' => []", $module);
    $this->assertStringContainsString('Go to publishing', $renderer);
    $this->assertStringNotContainsString(") + [\n        'actions' => [", $renderer);
  }

}
