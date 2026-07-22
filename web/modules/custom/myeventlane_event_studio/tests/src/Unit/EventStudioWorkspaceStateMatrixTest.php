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
    $this->assertArrayHasKey('strip_explanation', $payload);
    $this->assertArrayHasKey('checklist', $payload);
    $warningRows = array_values(array_filter(
      $payload['checklist'],
      static fn(array $item): bool => ($item['tone'] ?? '') === 'warning',
    ));
    $this->assertNotEmpty($warningRows);
    $this->assertSame('Review capacity', $warningRows[0]['label']);
    $this->assertSame(['Add banner image'], $payload['recommendations']);
    $ideaRows = array_values(array_filter(
      $payload['checklist'],
      static fn(array $item): bool => ($item['tone'] ?? '') === 'idea',
    ));
    $this->assertCount(1, $ideaRows);
    $this->assertSame('Add banner image', $ideaRows[0]['label']);
    $this->assertArrayHasKey('show_homepage_readiness', $payload);
  }

  public function testStripChecklistIncludesRecommendationIdeas(): void {
    $node = $this->node(FALSE, 108);
    $bundle = [
      'publish' => EventReadinessResult::create(
        ['Add an event title.'],
        [],
        ['Payment onboarding complete.'],
        ['Add a short event summary so attendees understand the experience quickly.'],
      ),
      'promotion' => [
        'ready' => FALSE,
        'status_label' => 'Needs attention before homepage promotion',
        'short_status_label' => 'Needs attention',
      ],
      'recommended' => [
        'Add a short event summary so attendees understand the experience quickly.',
        'Add banner image',
      ],
      'promotion_ready' => FALSE,
    ];
    $summary = $this->presentation->buildReadinessSummary($bundle, $node);
    $ideaRows = array_values(array_filter(
      $summary['checklist'],
      static fn(array $item): bool => ($item['tone'] ?? '') === 'idea',
    ));
    $this->assertCount(2, $ideaRows);
    $this->assertSame(
      [
        'Add a short event summary so attendees understand the experience quickly',
        'Add banner image',
      ],
      array_column($ideaRows, 'label'),
    );
  }

  public function testInformationFormStaysOnScheduleOrVenueAfterSave(): void {
    $form = file_get_contents(dirname(__DIR__, 3) . '/src/Form/EventInformationForm.php');
    $this->assertIsString($form);
    $this->assertStringContainsString('resolveStayRouteName', $form);
    $this->assertStringContainsString('workspace_schedule', $form);
    $this->assertStringContainsString('workspace_venue', $form);
    $this->assertStringContainsString('setRedirect($this->resolveStayRouteName()', $form);
  }

  public function testStripChecklistNeverOmitsBlockingErrorsPastSoftCap(): void {
    $node = $this->node(FALSE, 107);
    $errors = [
      'Add an event title.',
      'Add event dates.',
      'Select a booking mode.',
      'Configure ticketing.',
    ];
    $warnings = [
      'Cover image could be sharper.',
      'Add a short summary.',
    ];
    $completed = [
      'Payment onboarding complete.',
      'Vendor publish requirements complete.',
      'Branding image added.',
      'Capacity settings valid.',
      'External booking URL added.',
    ];
    $bundle = [
      'publish' => EventReadinessResult::create($errors, $warnings, $completed),
      'promotion' => [
        'ready' => FALSE,
        'status_label' => 'Needs attention before homepage promotion',
        'short_status_label' => 'Needs attention',
      ],
      'recommended' => [],
      'promotion_ready' => FALSE,
    ];
    $summary = $this->presentation->buildReadinessSummary($bundle, $node);
    $incomplete = array_values(array_filter(
      $summary['checklist'],
      static fn(array $item): bool => empty($item['complete']),
    ));
    $errorRows = array_values(array_filter(
      $incomplete,
      static fn(array $item): bool => ($item['tone'] ?? '') === 'attention',
    ));
    $warningRows = array_values(array_filter(
      $incomplete,
      static fn(array $item): bool => ($item['tone'] ?? '') === 'warning',
    ));

    $this->assertCount(4, $errorRows);
    $this->assertCount(2, $warningRows);
    $this->assertLessThanOrEqual(8, count($summary['checklist']));
    foreach ($errors as $error) {
      $this->assertContains(rtrim($error, '.'), array_column($errorRows, 'label'));
    }
    foreach ($warnings as $warning) {
      $this->assertContains(rtrim($warning, '.'), array_column($warningRows, 'label'));
    }
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
    $this->assertStringContainsString('updateReadinessChecklist', $js);
    $this->assertStringContainsString('strip_explanation', $js);
    $this->assertStringContainsString('data-mel-readiness-explain', $js);
    // Count-mode copy must match mel-event-studio-workspace.html.twig.
    $this->assertStringContainsString("@count to finish", $js);
    $this->assertStringContainsString("@count to review", $js);
    $this->assertStringNotContainsString('blocker(s)', $js);
    $this->assertStringContainsString("tone === 'idea'", $js);
    $this->assertStringContainsString("Drupal.t('Idea')", $js);
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
