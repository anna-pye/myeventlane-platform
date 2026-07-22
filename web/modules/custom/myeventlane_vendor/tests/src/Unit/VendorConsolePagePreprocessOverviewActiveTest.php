<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Utility\UnroutedUrlAssemblerInterface;
use Drupal\myeventlane_vendor\Hook\VendorConsolePagePreprocess;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_vendor\Hook\VendorConsolePagePreprocess
 * @group myeventlane_vendor
 */
final class VendorConsolePagePreprocessOverviewActiveTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * @covers ::preprocess
   */
  public function testOverviewTabActiveOnStaffMissionControlRoute(): void {
    $variables = $this->preprocessForRoute('myeventlane_vendor.console.event_workspace');
    $overview = $this->overviewTab($variables);
    $this->assertTrue($overview['active'], 'Overview must be active on staff mission control.');
    $this->assertFalse($this->anyOtherTabActive($variables), 'No other tab should be active on mission control.');
    $this->assertSame('myeventlane_event_studio.workspace', $overview['route']);
  }

  /**
   * @covers ::preprocess
   */
  public function testOverviewTabActiveOnStudioWorkspaceRoute(): void {
    $variables = $this->preprocessForRoute('myeventlane_event_studio.workspace');
    $overview = $this->overviewTab($variables);
    $this->assertTrue($overview['active']);
  }

  /**
   * @covers ::preprocess
   */
  public function testOverviewTabInactiveOnTicketsRoute(): void {
    $variables = $this->preprocessForRoute('myeventlane_event_studio.workspace_tickets');
    $overview = $this->overviewTab($variables);
    $this->assertFalse($overview['active']);
    $tickets = NULL;
    foreach ($variables['workspace']['tabs'] as $tab) {
      if (($tab['key'] ?? '') === 'tickets') {
        $tickets = $tab;
        break;
      }
    }
    $this->assertIsArray($tickets);
    $this->assertTrue($tickets['active']);
  }

  /**
   * @return array<string, mixed>
   */
  private function preprocessForRoute(string $route_name): array {
    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn('event');
    $event->method('id')->willReturn(42);
    $event->method('label')->willReturn('Test event');
    $event->method('isPublished')->willReturn(FALSE);
    $event->method('hasField')->willReturn(FALSE);

    $routeMatch = $this->createMock(RouteMatchInterface::class);
    $routeMatch->method('getParameter')->with('event')->willReturn($event);
    $routeMatch->method('getRouteName')->willReturn($route_name);

    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')->willReturnCallback(
      static fn (string $string, array $args = [], array $options = []) => $string,
    );

    $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    $urlGenerator->method('generateFromRoute')->willReturnCallback(
      static fn (string $name, array $parameters = [], array $options = []): string => '/' . str_replace('.', '/', $name),
    );
    $unrouted = $this->createMock(UnroutedUrlAssemblerInterface::class);
    $unrouted->method('assemble')->willReturn('/');

    $container = new ContainerBuilder();
    $container->set('string_translation', $translation);
    $container->set('url_generator', $urlGenerator);
    $container->set('unrouted_url_assembler', $unrouted);
    \Drupal::setContainer($container);

    $preprocess = new VendorConsolePagePreprocess($routeMatch, $translation, NULL);
    $variables = [];
    $preprocess->preprocess($variables);
    return $variables;
  }

  /**
   * @param array<string, mixed> $variables
   *
   * @return array<string, mixed>
   */
  private function overviewTab(array $variables): array {
    $this->assertArrayHasKey('workspace', $variables);
    foreach ($variables['workspace']['tabs'] as $tab) {
      if (($tab['key'] ?? '') === 'overview') {
        return $tab;
      }
    }
    $this->fail('Overview tab missing.');
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function anyOtherTabActive(array $variables): bool {
    foreach ($variables['workspace']['tabs'] as $tab) {
      if (($tab['key'] ?? '') !== 'overview' && !empty($tab['active'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
