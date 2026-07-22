<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_event_studio\Service\EventWorkspaceOverviewBuilder;
use Drupal\Tests\UnitTestCase;
use ReflectionClass;

/**
 * Overview human checklist tone separation.
 *
 * @group myeventlane_event_studio
 */
final class EventWorkspaceOverviewChecklistTest extends UnitTestCase {

  public function testWarningsAreNotPresentedAsBlockingAttentionRows(): void {
    $translator = $this->createMock(TranslationInterface::class);
    $translator->method('translateString')->willReturnCallback(
      static fn (\Drupal\Core\StringTranslation\TranslatableMarkup $markup): string => $markup->getUntranslatedString(),
    );

    $builder = (new ReflectionClass(EventWorkspaceOverviewBuilder::class))
      ->newInstanceWithoutConstructor();
    $property = (new ReflectionClass(EventWorkspaceOverviewBuilder::class))
      ->getProperty('stringTranslation');
    $property->setValue($builder, $translator);

    $method = (new ReflectionClass(EventWorkspaceOverviewBuilder::class))
      ->getMethod('buildHumanChecklist');

    $items = $method->invoke(
      $builder,
      ['Event title added.'],
      ['Add tickets.'],
      ['Cover image could be sharper.'],
      ['Add a short summary.'],
    );

    $byTone = [];
    foreach ($items as $item) {
      $byTone[$item['tone']][] = $item;
    }

    $this->assertCount(1, $byTone['success']);
    $this->assertTrue($byTone['success'][0]['complete']);
    $this->assertCount(1, $byTone['attention']);
    $this->assertFalse($byTone['attention'][0]['complete']);
    $this->assertSame('Add tickets', $byTone['attention'][0]['label']);
    $this->assertCount(1, $byTone['warning']);
    $this->assertFalse($byTone['warning'][0]['complete']);
    $this->assertSame('Cover image could be sharper', $byTone['warning'][0]['label']);
    $this->assertCount(1, $byTone['idea']);
  }

  public function testOverviewUsesReadinessFacadeForMergedIdeas(): void {
    $builder = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventWorkspaceOverviewBuilder.php');
    $services = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_studio.services.yml');
    $twig = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio-overview.html.twig');
    $module = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_studio.module');
    $this->assertIsString($builder);
    $this->assertIsString($services);
    $this->assertIsString($twig);
    $this->assertIsString($module);

    $this->assertStringContainsString('EventReadinessFacade $readinessFacade', $builder);
    $this->assertStringContainsString('$this->readinessFacade->evaluate', $builder);
    $this->assertStringContainsString('$recommended', $builder);
    $this->assertStringContainsString("'@myeventlane_event_studio.readiness_facade'", $services);
    $this->assertStringContainsString('mel-event-workspace-home', $twig);
    $this->assertStringContainsString('event_ready', $module);
    $this->assertStringContainsString('View checklist', $twig);
  }

  public function testActivityOrderIdSortIsTableQualified(): void {
    $builder = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventWorkspaceOverviewBuilder.php');
    $this->assertIsString($builder);
    $this->assertStringContainsString("orderBy('o.order_id', 'DESC')", $builder);
    $this->assertStringNotContainsString("orderBy('order_id', 'DESC')", $builder);
  }

}
