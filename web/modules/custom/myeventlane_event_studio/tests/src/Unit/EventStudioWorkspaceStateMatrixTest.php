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
 * State matrix and presentation contract tests for Event Studio workspace.
 *
 * @group myeventlane_event_studio
 */
final class EventStudioWorkspaceStateMatrixTest extends UnitTestCase {

  private EventStudioWorkspacePresentation $presentation;

  protected function setUp(): void {
    parent::setUp();
    $dateFormatter = $this->createMock(DateFormatterInterface::class);
    $dateFormatter->method('format')->willReturn('10 Jun 2026 - 16:46');
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

  public function testDraftReadyShowsPublishStrip(): void {
    $node = $this->node(FALSE, 100);
    $bundle = $this->bundle(ready: TRUE, promotion_ready: TRUE);
    $summary = $this->presentation->buildReadinessSummary($bundle, $node);

    $this->assertTrue($summary['show_publish_strip']);
    $this->assertSame('Ready to publish', $summary['strip_title']);
  }

  public function testDraftBlockedShowsPublishStrip(): void {
    $node = $this->node(FALSE, 101);
    $bundle = $this->bundle(ready: FALSE, promotion_ready: FALSE, errors: ['Add an event title.']);
    $summary = $this->presentation->buildReadinessSummary($bundle, $node);

    $this->assertTrue($summary['show_publish_strip']);
    $this->assertSame("You're almost there…", $summary['strip_title']);
    $this->assertStringContainsString('One more thing before publishing', $summary['strip_explanation']);
    $this->assertNotEmpty($summary['checklist']);
  }

  public function testPublishedReadyHidesPublishStripWithoutBlockers(): void {
    $node = $this->node(TRUE, 102);
    $bundle = $this->bundle(ready: TRUE, promotion_ready: TRUE);
    $summary = $this->presentation->buildReadinessSummary($bundle, $node);

    $this->assertFalse($summary['show_publish_strip']);
    $this->assertSame('Published and ready', $summary['strip_title']);
  }

  public function testPublishedPromotionIssueShowsHomepageCard(): void {
    $this->assertTrue(
      $this->presentation->shouldShowHomepageReadinessCard(FALSE, TRUE),
    );
  }

  public function testPublishedPromotionReadyHidesHomepageCard(): void {
    $this->assertFalse(
      $this->presentation->shouldShowHomepageReadinessCard(TRUE, TRUE),
    );
  }

  public function testPublishedBoostActiveEventHealthIncludesBoostDetail(): void {
    $node = $this->node(TRUE, 105);
    $bundle = $this->bundle(ready: TRUE, promotion_ready: TRUE);
    $health = $this->presentation->buildEventHealth($bundle, $node, [
      'active' => TRUE,
      'days_remaining' => 6,
      'expires' => '16 Jun 2026',
    ]);

    $this->assertSame('Event health', $health['heading']);
    $this->assertCount(3, $health['items']);
    $this->assertSame('Published', $health['items'][0]['value']);
    $this->assertSame('Active', $health['items'][2]['value']);
    $this->assertSame('6 days remaining', $health['items'][2]['detail']);
  }

  public function testPublishAjaxPayloadIncludesStripFlags(): void {
    $node = $this->node(FALSE, 106);
    $bundle = $this->bundle(ready: TRUE, promotion_ready: FALSE, warnings: ['Review capacity']);
    $payload = $this->presentation->buildAjaxReadinessPayloadFromBundle($bundle, $node);

    $this->assertTrue($payload['show_publish_strip']);
    $this->assertArrayHasKey('strip_title', $payload);
    $this->assertSame(['Add banner image'], $payload['recommendations']);
    $this->assertArrayHasKey('show_homepage_readiness', $payload);
  }

  public function testWorkspaceTemplateIncludesEventHealthBeforePublishStrip(): void {
    $workspace = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio-workspace.html.twig');
    $publish = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/EventStudioPublishController.php');
    $this->assertIsString($workspace);
    $this->assertIsString($publish);
    $this->assertStringContainsString('mel-event-studio-event-health', $workspace);
    $healthPos = strpos($workspace, 'mel-event-studio-event-health');
    $stripPos = strpos($workspace, 'mel-event-studio-readiness-strip');
    $this->assertNotFalse($healthPos);
    $this->assertNotFalse($stripPos);
    $this->assertLessThan($stripPos, $healthPos);
    $this->assertStringContainsString("'event_health'", $publish);
    $this->assertStringContainsString('buildAjaxReadinessPayloadFromBundle', $publish);
  }

  public function testShellJsUpdatesEventHealthAfterPublish(): void {
    $js = file_get_contents(dirname(__DIR__, 3) . '/js/mel-event-studio-shell.js');
    $this->assertIsString($js);
    $this->assertStringContainsString('function updateEventHealth', $js);
    $this->assertStringContainsString('result.event_health', $js);
    $this->assertStringContainsString('ensureReadinessStrip', $js);
    $this->assertStringContainsString('updateHomepageReadiness', $js);
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
