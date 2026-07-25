<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\myeventlane_event_studio\DTO\EventReadinessResult;
use Drupal\myeventlane_event_studio\Service\EventStudioWorkspacePresentation;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use ReflectionClass;

/**
 * Proves workspace surfaces share one presentation source per state.
 *
 * @group myeventlane_event_studio
 */
final class EventStudioWorkspacePresentationContractTest extends UnitTestCase {

  private EventStudioWorkspacePresentation $presentation;

  protected function setUp(): void {
    parent::setUp();
    $dateFormatter = $this->createMock(DateFormatterInterface::class);
    $dateFormatter->method('format')->willReturn('10 Jun 2026 - 16:46');
    $dateFormatter->method('formatTimeDiffSince')->willReturn('1 min');
    $translator = $this->createMock(\Drupal\Core\StringTranslation\TranslationInterface::class);
    $translator->method('translateString')->willReturnCallback(
      static fn (\Drupal\Core\StringTranslation\TranslatableMarkup $markup): string => $markup->getUntranslatedString(),
    );
    $homepageReadinessRender = (new ReflectionClass(\Drupal\myeventlane_event\Service\FeaturedEventReadinessRenderBuilder::class))
      ->newInstanceWithoutConstructor();
    $eventCardViewModel = (new ReflectionClass(\Drupal\myeventlane_event\EventCard\EventCardViewModel::class))
      ->newInstanceWithoutConstructor();
    $this->presentation = new EventStudioWorkspacePresentation($dateFormatter, $homepageReadinessRender, $eventCardViewModel, $translator);
  }

  public function testStripAndAjaxPayloadShareReadinessSummaryFields(): void {
    $node = $this->node(TRUE, 201);
    $bundle = $this->bundle(
      ready: TRUE,
      promotion_ready: FALSE,
      warnings: ['Review capacity'],
    );

    $summary = $this->presentation->buildReadinessSummary($bundle, $node);
    $ajax = $this->presentation->buildAjaxReadinessPayloadFromBundle($bundle, $node);

    $this->assertSame($summary['show_publish_strip'], $ajax['show_publish_strip']);
    $this->assertSame($summary['strip_title'], $ajax['strip_title']);
    $this->assertSame($summary['ready'], $ajax['ready']);
    $this->assertSame($summary['state'], $ajax['state']);
    $this->assertSame($summary['errors'], $ajax['errors']);
    $this->assertSame($summary['warnings'], $ajax['warnings']);
    $this->assertSame($summary['recommendations'], $ajax['recommendations']);
  }

  public function testEventHealthPublishRowUsesSamePublishResultAsStrip(): void {
    $node = $this->node(FALSE, 202);
    $bundle = $this->bundle(ready: FALSE, promotion_ready: FALSE, errors: ['Add title']);

    $summary = $this->presentation->buildReadinessSummary($bundle, $node);
    $health = $this->presentation->buildEventHealth($bundle, $node, NULL);

    $this->assertSame('Needs Attention', $summary['state']);
    $this->assertSame('Draft', $health['items'][0]['value']);
    $this->assertSame('Needs attention', $health['items'][0]['detail']);
    $this->assertSame('attention', $health['items'][0]['tone']);
  }

  public function testEventHealthPromotionUsesFacadePromotionPayload(): void {
    $node = $this->node(TRUE, 203);
    $bundle = $this->bundle(ready: TRUE, promotion_ready: TRUE);
    $bundle['promotion']['status_label'] = 'Ready for homepage promotion';

    $health = $this->presentation->buildEventHealth($bundle, $node, NULL);

    $this->assertSame('Ready for homepage promotion', $health['items'][1]['value']);
    $this->assertSame('ready', $health['items'][1]['tone']);
  }

  public function testAjaxPayloadIncludesHomepageVisibilityFlag(): void {
    $published = $this->node(TRUE, 204);
    $draft = $this->node(FALSE, 205);
    $readyBundle = $this->bundle(ready: TRUE, promotion_ready: TRUE);
    $issueBundle = $this->bundle(ready: TRUE, promotion_ready: FALSE);

    $this->assertFalse(
      $this->presentation->buildAjaxReadinessPayloadFromBundle($readyBundle, $published)['show_homepage_readiness'],
    );
    $this->assertTrue(
      $this->presentation->buildAjaxReadinessPayloadFromBundle($issueBundle, $published)['show_homepage_readiness'],
    );
    $this->assertTrue(
      $this->presentation->buildAjaxReadinessPayloadFromBundle($readyBundle, $draft)['show_homepage_readiness'],
    );
  }

  public function testDegradedPromotionPayloadDoesNotBreakEventHealth(): void {
    $node = $this->node(TRUE, 206);
    $bundle = [
      'publish' => EventReadinessResult::create(completed: ['Ready']),
      'promotion' => [
        'ready' => FALSE,
        'items' => [],
        'required' => [],
        'recommended' => [],
      ],
      'recommended' => [],
      'promotion_ready' => FALSE,
    ];

    $health = $this->presentation->buildEventHealth($bundle, $node, NULL);

    $this->assertCount(3, $health['items']);
    $this->assertSame('Needs attention before homepage promotion', $health['items'][1]['value']);
    $this->assertSame('Not active', $health['items'][2]['value']);
  }

  public function testTopbarLocationContractUsesEventCardViewModel(): void {
    $topbar = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio-topbar.html.twig');
    $presentation = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioWorkspacePresentation.php');
    $this->assertIsString($topbar);
    $this->assertIsString($presentation);
    $this->assertStringContainsString('data-mel-topbar-location', $topbar);
    $this->assertStringContainsString('buildTopbarLocation', $presentation);
    $this->assertStringContainsString('buildTopbarDateLabel', $presentation);
    $this->assertStringContainsString('buildTopbarVenueLabel', $presentation);
    $this->assertStringContainsString('buildTopbarStatus', $presentation);
    $this->assertStringContainsString('data-mel-hero-primary-key', $topbar);
    $this->assertStringContainsString('eventCardViewModel', $presentation);
  }

  public function testTopbarStatusPastWhenEventEndPassed(): void {
    $past = $this->createMock(NodeInterface::class);
    $past->method('isPublished')->willReturn(TRUE);
    $past->method('bundle')->willReturn('event');
    $past->method('hasField')->willReturnCallback(static fn(string $field): bool => $field === 'field_event_end');
    $pastDate = new \DateTimeImmutable('2020-01-01T12:00:00');
    $endItem = new class($pastDate) {
      public object $date;
      public function __construct(object $date) {
        $this->date = $date;
      }
    };
    $endField = $this->createMock(\Drupal\Core\Field\FieldItemListInterface::class);
    $endField->method('isEmpty')->willReturn(FALSE);
    $endField->method('first')->willReturn($endItem);
    $past->method('get')->willReturnCallback(static function (string $field) use ($endField) {
      return $field === 'field_event_end' ? $endField : NULL;
    });

    $status = $this->presentation->buildTopbarStatus($past);
    $this->assertSame('Past', $status['label']);
    $this->assertSame('past', $status['key']);
  }

  public function testTopbarStatusDraftWhenUnpublished(): void {
    $draft = $this->node(FALSE, 301);
    $draft->method('bundle')->willReturn('event');
    $draft->method('hasField')->willReturn(FALSE);
    $status = $this->presentation->buildTopbarStatus($draft);
    $this->assertSame('Draft', $status['label']);
    $this->assertSame('draft', $status['key']);
  }

  public function testPublishControllerUsesFacadeBundleForAjaxPayload(): void {
    $publish = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/EventStudioPublishController.php');
    $this->assertIsString($publish);
    $this->assertStringContainsString('readinessFacade->evaluate', $publish);
    $this->assertStringContainsString('buildAjaxReadinessPayloadFromBundle', $publish);
    $this->assertStringContainsString('resolveAuthoritativePrimaryCta', $publish);
    $this->assertStringContainsString('mission_control', $publish);
    $this->assertStringContainsString('homepage_readiness_html', $publish);
    $this->assertStringNotContainsString('buildAjaxReadinessPayload(', $publish);
    // Topbar badge uses presentation status (Draft / Live / Past) — not hardcoded Live-only.
    $this->assertStringContainsString('buildTopbarStatus', $publish);
    $this->assertStringNotContainsString("'status' => \$node->isPublished() ? (string) \$this->t('Published')", $publish);
  }

  public function testShellJsSyncsMissionControlAfterPublish(): void {
    $js = file_get_contents(dirname(__DIR__, 3) . '/js/mel-event-studio-shell.js');
    $this->assertIsString($js);
    $this->assertStringContainsString('updateMissionControl', $js);
    $this->assertStringContainsString('mission_control', $js);
    $this->assertStringContainsString('data-mel-mc-quality', $js);
  }

  public function testPublishControllerUsesOverviewBuilderForHomeAjax(): void {
    $publish = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/EventStudioPublishController.php');
    $services = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_studio.services.yml');
    $presentation = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioWorkspacePresentation.php');
    $overview = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventWorkspaceOverviewBuilder.php');
    $this->assertIsString($publish);
    $this->assertIsString($services);
    $this->assertIsString($presentation);
    $this->assertIsString($overview);
    $this->assertStringContainsString('buildHomeAjaxGuideSnapshot', $publish);
    $this->assertStringContainsString('overviewBuilder', $publish);
    $this->assertStringContainsString("ajax_readiness['home']", $publish);
    $this->assertStringContainsString("ajax_readiness['mission_control']", $publish);
    $this->assertStringContainsString('@myeventlane_event_studio.overview_builder', $services);
    $this->assertStringContainsString('function buildHomeAjaxGuideSnapshot', $overview);
    $this->assertStringContainsString('buildStripeHealth', $overview);
    $this->assertStringContainsString('resolveNextRecommendedAction', $overview);
    // Presentation must not own a simplified Home AJAX path that skips Stripe.
    $this->assertStringNotContainsString('buildHomeAjaxSnapshot', $presentation);
    $this->assertStringNotContainsString('buildHomeNextAction', $presentation);
  }

  public function testShellJsAppliesMissionControlAfterPublish(): void {
    $js = file_get_contents(dirname(__DIR__, 3) . '/js/mel-event-studio-shell.js');
    $this->assertIsString($js);
    $this->assertStringContainsString('function updateMissionControl', $js);
    $this->assertStringContainsString('updateMissionControl(shell, readiness)', $js);
    $this->assertStringContainsString('readiness.home', $js);
    $this->assertStringContainsString('mission_control', $js);
  }

  /**
   * @param list<string> $errors
   * @param list<string> $warnings
   *
   * @return array<string, mixed>
   */
  private function bundle(bool $ready, bool $promotion_ready, array $errors = [], array $warnings = []): array {
    return [
      'publish' => EventReadinessResult::create($errors, $warnings, completed: $ready ? ['Ready'] : []),
      'promotion' => [
        'ready' => $promotion_ready,
        'status_label' => $promotion_ready
          ? 'Ready for homepage promotion'
          : 'Needs attention before homepage promotion',
        'short_status_label' => $promotion_ready ? 'Ready for homepage promotion' : 'Needs attention',
      ],
      'recommended' => ['Add banner image'],
      'promotion_ready' => $promotion_ready,
    ];
  }

  private function node(bool $published, int $nid): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('isPublished')->willReturn($published);
    $node->method('id')->willReturn($nid);
    $node->method('getChangedTime')->willReturn(1710000000);
    return $node;
  }

}
