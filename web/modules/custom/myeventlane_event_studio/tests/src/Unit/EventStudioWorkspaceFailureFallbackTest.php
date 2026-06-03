<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormAjaxException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_event_studio\Controller\EventStudioController;
use Drupal\myeventlane_event_studio\EventStudioSectionManager;
use Drupal\myeventlane_event_studio\Plugin\EventStudioSection\EventStudioSectionInterface;
use Drupal\myeventlane_event_studio\Service\EventStudioEmptyStateBuilder;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\myeventlane_vendor\Service\VendorEventStudioCreateService;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Controller\EventStudioController
 *
 * @group myeventlane_event_studio
 */
final class EventStudioWorkspaceFailureFallbackTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * @covers ::buildWorkspaceSectionContent
   */
  public function testWorkspaceInformationFailureFallback(): void {
    $content = $this->invokeSectionContentFallback(
      'information',
      'form:Drupal\myeventlane_event_studio\Form\EventInformationForm',
      'Event information',
    );

    $this->assertUnavailableSectionFallback($content, 'Event information');
  }

  /**
   * @covers ::buildWorkspaceSectionContent
   */
  public function testWorkspaceTicketsFailureFallback(): void {
    $content = $this->invokeSectionContentFallback(
      'tickets',
      'tickets_stack',
      'Tickets',
    );

    $this->assertUnavailableSectionFallback($content, 'Tickets');
  }

  /**
   * @covers ::buildWorkspaceSectionContent
   */
  public function testWorkspaceSectionContentReThrowsFormAjaxException(): void {
    $translator = $this->translator();
    $createMock = fn (string $class): object => $this->createMock($class);
    $node = $this->eventNode();
    $sectionPlugin = $this->sectionPlugin('branding', 'form:Drupal\myeventlane_event_studio\Form\EventBrandingForm', 'Branding');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('error');

    $controller = new EventStudioController(
      $this->createMock(EntityTypeManagerInterface::class),
      (new ReflectionClass(VendorEventStudioCreateService::class))->newInstanceWithoutConstructor(),
      $logger,
      new \Symfony\Component\HttpFoundation\RequestStack(),
      new EventVendorAccessChecker(),
      $this->createMock(DateFormatterInterface::class),
      EventStudioWorkspaceTestFactory::readinessService($translator, $createMock),
      EventStudioWorkspaceTestFactory::autosaveService($createMock),
      (new ReflectionClass(EventStudioSectionManager::class))->newInstanceWithoutConstructor(),
      EventStudioWorkspaceTestFactory::formAjaxThrowingSectionRenderer($translator, $createMock),
      new EventStudioEmptyStateBuilder($translator),
      EventStudioWorkspaceTestFactory::domainDetector($createMock),
    );

    $method = new ReflectionMethod(EventStudioController::class, 'buildWorkspaceSectionContent');
    $method->setAccessible(TRUE);

    $this->expectException(FormAjaxException::class);
    $method->invoke($controller, $sectionPlugin, $node);
  }

  /**
   * @covers ::workspace
   */
  public function testWorkspaceShellDelegatesToSectionFallbackBuilder(): void {
    $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Controller/EventStudioController.php');
    $this->assertStringContainsString('buildWorkspaceSectionContent($sectionPlugin, $node)', $source);
    $this->assertStringContainsString("'#theme' => 'mel_event_studio_workspace'", $source);
    $this->assertStringContainsString('unavailableSection($sectionPlugin->title())', $source);
    $this->assertStringContainsString('catch (\Throwable $exception)', $source);
    $this->assertStringContainsString('catch (FormAjaxException $exception)', $source);
    $this->assertStringContainsString('catch (EnforcedResponseException $exception)', $source);
  }

  /**
   * @return array<string, mixed>
   */
  private function invokeSectionContentFallback(string $sectionId, string $renderTarget, string $sectionTitle): array {
    $translator = $this->translator();
    $createMock = fn (string $class): object => $this->createMock($class);
    $node = $this->eventNode();
    $sectionPlugin = $this->sectionPlugin($sectionId, $renderTarget, $sectionTitle);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Event Studio section render failed'),
        $this->callback(static function (array $context) use ($sectionId, $renderTarget): bool {
          return isset($context['@eid'], $context['@section'], $context['@target'], $context['@message'], $context['@class'], $context['@file'], $context['@line'])
            && $context['@section'] === $sectionId
            && $context['@target'] === $renderTarget
            && $context['@message'] === 'Simulated section render failure';
        }),
      );

    $controller = new EventStudioController(
      $this->createMock(EntityTypeManagerInterface::class),
      (new ReflectionClass(VendorEventStudioCreateService::class))->newInstanceWithoutConstructor(),
      $logger,
      new \Symfony\Component\HttpFoundation\RequestStack(),
      new EventVendorAccessChecker(),
      $this->createMock(DateFormatterInterface::class),
      EventStudioWorkspaceTestFactory::readinessService($translator, $createMock),
      EventStudioWorkspaceTestFactory::autosaveService($createMock),
      (new ReflectionClass(EventStudioSectionManager::class))->newInstanceWithoutConstructor(),
      EventStudioWorkspaceTestFactory::throwingSectionRenderer($translator, $createMock),
      new EventStudioEmptyStateBuilder($translator),
      EventStudioWorkspaceTestFactory::domainDetector($createMock),
    );

    $method = new ReflectionMethod(EventStudioController::class, 'buildWorkspaceSectionContent');
    $method->setAccessible(TRUE);

    return $method->invoke($controller, $sectionPlugin, $node);
  }

  /**
   * @param array<string, mixed> $content
   */
  private function assertUnavailableSectionFallback(array $content, string $sectionTitle): void {
    $this->assertSame('mel_event_studio_empty_state', $content['#theme']);
    $this->assertSame('unavailable', $content['#state']);
    $this->assertSame($sectionTitle, $content['#title']);
  }

  private function sectionPlugin(string $sectionId, string $renderTarget, string $title): EventStudioSectionInterface&MockObject {
    $plugin = $this->createMock(EventStudioSectionInterface::class);
    $plugin->method('getPluginId')->willReturn($sectionId);
    $plugin->method('renderTarget')->willReturn($renderTarget);
    $plugin->method('title')->willReturn($title);
    return $plugin;
  }

  private function eventNode(): NodeInterface&MockObject {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn('99');
    return $node;
  }

  private function translator(): TranslationInterface {
    $translator = $this->createMock(TranslationInterface::class);
    $translator
      ->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString());
    return $translator;
  }

}
