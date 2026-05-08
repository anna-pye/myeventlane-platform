<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_event_studio\Service\EventStudioGovernanceComponentBuilder;
use Drupal\myeventlane_core\MelReadinessHelper;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\EventStudioGovernanceComponentBuilder
 *
 * @group myeventlane_event_studio
 */
final class EventStudioGovernanceComponentBuilderTest extends TestCase {

  /**
   * @covers ::allowedComponents
   * @covers ::isAllowedComponent
   */
  public function testAllowedComponentsAreBounded(): void {
    $builder = new EventStudioGovernanceComponentBuilder($this->translator(), $this->readiness());

    $this->assertSame([
      'intelligence',
      'workflow',
      'state',
      'continuity',
    ], $builder->allowedComponents());
    $this->assertTrue($builder->isAllowedComponent('workflow'));
    $this->assertFalse($builder->isAllowedComponent('observability'));
  }

  /**
   * @covers ::buildComponent
   */
  public function testStateComponentUsesServerReadinessPayload(): void {
    $builder = new EventStudioGovernanceComponentBuilder($this->translator(), $this->readiness());

    $build = $builder->buildComponent([
      'twig' => [
        'state' => [
          'readiness_summaries' => ['Ticket setup is ready.', 'Venue details are ready.'],
        ],
      ],
      'js' => [
        'publishReadinessLead' => 'Ticket setup is ready. Venue details are ready.',
      ],
      '#cache' => ['contexts' => ['user'], 'tags' => ['node:12']],
    ], 'state');

    $this->assertSame('inline_template', $build['#type']);
    $this->assertStringContainsString('data-mel-governance-component="state"', $build['#template']);
    $this->assertSame(['user'], $build['#cache']['contexts']);
    $this->assertSame(['node:12'], $build['#cache']['tags']);
    $this->assertSame(
      'Ticket setup is ready. Venue details are ready.',
      $build['#context']['content']['readiness']['#content']['text']['#plain_text'],
    );
  }

  /**
   * @covers ::buildComponent
   */
  public function testWorkflowComponentUsesServerCtaDescriptor(): void {
    $builder = new EventStudioGovernanceComponentBuilder($this->translator(), $this->readiness());

    $build = $builder->buildComponent([
      'twig' => ['workflow_primary' => []],
      'js' => [
        'nextBestText' => 'Finish ticket setup.',
        'primaryCta' => [
          'mode' => 'workflow_navigate',
          'label' => 'Continue setup',
          'href' => '/vendor/events/12/edit/tickets',
        ],
      ],
    ], 'workflow');

    $next = $build['#context']['content']['next']['#content']['#context'];
    $this->assertSame('Finish ticket setup.', $next['next_best']);
    $this->assertSame('Continue setup', $next['cta_label']);
    $this->assertSame('/vendor/events/12/edit/tickets', $next['href']);
  }

  /**
   * @covers ::buildComponent
   */
  public function testIntelligenceComponentUsesSuppressedServerRowsOnly(): void {
    $builder = new EventStudioGovernanceComponentBuilder($this->translator(), $this->readiness());

    $build = $builder->buildComponent([
      'twig' => [
        'surface' => 'vendor',
        'intelligence' => [
          'attributes' => ['class' => ['mel-intelligence-panel']],
          'items' => [
            ['id' => 'visible', 'headline' => 'Visible prompt'],
          ],
          'insights' => [],
        ],
      ],
      'js' => [
        'checklist' => [
          ['id' => 'visible', 'text' => 'Visible prompt'],
        ],
      ],
    ], 'intelligence');

    $panel = $build['#context']['content']['panel'];
    $this->assertSame('mel_intelligence_panel', $panel['#theme']);
    $this->assertCount(1, $panel['#items']);
    $this->assertSame('visible', $panel['#items'][0]['id']);
    $this->assertFalse($panel['#explainability_details']);
    $this->assertArrayHasKey('#dismiss_hint_template', $panel);
    $this->assertNotSame('', $panel['#intelligence_region_heading']);
  }

  /**
   * @covers ::buildComponent
   */
  public function testIntelligenceComponentStaffExplainabilityFlag(): void {
    $builder = new EventStudioGovernanceComponentBuilder($this->translator(), $this->readiness());

    $build = $builder->buildComponent([
      'twig' => [
        'surface' => 'staff',
        'intelligence' => [
          'attributes' => [],
          'items' => [],
          'insights' => [
            ['id' => 'staff_fixture', 'headline' => 'Operational note'],
          ],
          'explainability_details' => TRUE,
        ],
      ],
      'js' => ['checklist' => []],
    ], 'intelligence');

    $panel = $build['#context']['content']['panel'];
    $this->assertTrue($panel['#explainability_details']);
    $this->assertNotSame('', $panel['#explainability_summary_label']);
    $this->assertSame('', $panel['#dismiss_hint_template']);
  }

  /**
   * @covers ::buildComponent
   */
  public function testUnknownComponentFailsClosed(): void {
    $builder = new EventStudioGovernanceComponentBuilder($this->translator(), $this->readiness());

    $this->expectException(\InvalidArgumentException::class);
    $builder->buildComponent([], 'observability');
  }

  private function translator(): TranslationInterface {
    $translator = $this->createMock(TranslationInterface::class);
    $translator
      ->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString());
    return $translator;
  }

  private function readiness(): MelReadinessHelper {
    return new MelReadinessHelper($this->translator());
  }

}
