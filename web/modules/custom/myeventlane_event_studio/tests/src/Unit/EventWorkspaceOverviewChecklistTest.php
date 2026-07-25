<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_event_studio\DTO\EventReadinessResult;
use Drupal\myeventlane_event_studio\Service\EventWorkspaceOverviewBuilder;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use ReflectionClass;

/**
 * Overview human checklist tone separation.
 *
 * @group myeventlane_event_studio
 */
final class EventWorkspaceOverviewChecklistTest extends UnitTestCase {

  public function testPublishedReadyStripeAttentionDoesNotSayAlmostReady(): void {
    $card = $this->buildEventReadyCard(
      EventReadinessResult::create([], [], ['Event title added.']),
      TRUE,
      'Live',
      'live',
      [
        'tone' => 'attention',
        'detail' => 'Connect Stripe to accept payments.',
        'url' => '/vendor/payouts',
      ],
      ['event_type' => 'paid'],
    );

    $this->assertSame('attention', $card['tone']);
    $this->assertSame('Live', $card['status_label']);
    $this->assertSame('live', $card['status_key']);
    $this->assertSame('Live — payments need attention', $card['headline']);
    $this->assertStringContainsString('Connect Stripe', $card['detail']);
    $this->assertStringNotContainsString('Almost ready', $card['headline']);
  }

  public function testDraftReadyStripeAttentionKeepsAlmostReady(): void {
    $card = $this->buildEventReadyCard(
      EventReadinessResult::create([], [], ['Event title added.']),
      FALSE,
      'Draft',
      'draft',
      [
        'tone' => 'attention',
        'detail' => 'Connect Stripe to accept payments.',
        'url' => '/vendor/payouts',
      ],
      ['event_type' => 'paid'],
    );

    $this->assertSame('attention', $card['tone']);
    $this->assertSame('Almost ready', $card['headline']);
    $this->assertSame('Draft', $card['status_label']);
  }

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
    $mission = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio-mission-control.html.twig');
    $module = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_studio.module');
    $this->assertIsString($builder);
    $this->assertIsString($services);
    $this->assertIsString($twig);
    $this->assertIsString($mission);
    $this->assertIsString($module);

    $this->assertStringContainsString('EventReadinessFacade $readinessFacade', $builder);
    $this->assertStringContainsString('$this->readinessFacade->evaluate', $builder);
    $this->assertStringContainsString('$recommended', $builder);
    $this->assertStringContainsString("'@myeventlane_event_studio.readiness_facade'", $services);
    $this->assertStringContainsString('mel-event-workspace-home', $twig);
    $this->assertStringContainsString('mission_control', $module);
    $this->assertStringContainsString('mel-event-studio-mission-control.html.twig', $twig);
    $this->assertStringContainsString('Show details', $mission);
    $this->assertStringContainsString('data-mel-mc-details', $mission);
    $this->assertStringContainsString('data-mel-mc-checklist', $mission);
  }

  public function testActivityOrderIdSortIsTableQualified(): void {
    $builder = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventWorkspaceOverviewBuilder.php');
    $this->assertIsString($builder);
    $this->assertStringContainsString("orderBy('o.order_id', 'DESC')", $builder);
    $this->assertStringNotContainsString("orderBy('order_id', 'DESC')", $builder);
  }

  public function testSalesAndAnalyticsDoNotShareBookingsLabelForDifferentMetrics(): void {
    $builder = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventWorkspaceOverviewBuilder.php');
    $this->assertIsString($builder);
    $salesPos = strpos($builder, 'function buildSalesCard');
    $analyticsPos = strpos($builder, 'function buildAnalyticsCard');
    $activityPos = strpos($builder, 'function buildActivityFeed');
    $this->assertNotFalse($salesPos);
    $this->assertNotFalse($analyticsPos);
    $this->assertNotFalse($activityPos);
    $salesBlock = substr($builder, $salesPos, $analyticsPos - $salesPos);
    $analyticsBlock = substr($builder, $analyticsPos, $activityPos - $analyticsPos);
    // Sales: orders_count → "orders". Analytics: tickets_sold → "sold".
    $this->assertStringContainsString("\$this->t('orders')", $salesBlock);
    $this->assertStringNotContainsString("\$this->t('bookings')", $salesBlock);
    $this->assertStringContainsString("\$this->t('sold')", $analyticsBlock);
    $this->assertStringNotContainsString("\$this->t('bookings')", $analyticsBlock);
  }

  /**
   * @param array<string, mixed> $stripe
   * @param array<string, mixed> $eventMeta
   *
   * @return array<string, mixed>
   */
  private function buildEventReadyCard(
    EventReadinessResult $readiness,
    bool $published,
    string $statusLabel,
    string $statusKey,
    array $stripe,
    array $eventMeta,
  ): array {
    $translator = $this->createMock(TranslationInterface::class);
    $translator->method('translateString')->willReturnCallback(
      static fn (\Drupal\Core\StringTranslation\TranslatableMarkup $markup): string => $markup->getUntranslatedString(),
    );

    $dateFormatter = $this->createMock(DateFormatterInterface::class);
    $dateFormatter->method('formatTimeDiffSince')->willReturn('2 hours');

    $event = $this->createMock(NodeInterface::class);
    $event->method('getChangedTime')->willReturn(1700000000);

    $reflection = new ReflectionClass(EventWorkspaceOverviewBuilder::class);
    $builder = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('stringTranslation')->setValue($builder, $translator);
    $reflection->getProperty('dateFormatter')->setValue($builder, $dateFormatter);

    $method = $reflection->getMethod('buildEventReady');

    /** @var array<string, mixed> $card */
    $card = $method->invoke(
      $builder,
      $readiness,
      $published,
      $statusLabel,
      $statusKey,
      5,
      5,
      0,
      $event,
      $stripe,
      $eventMeta,
    );
    return $card;
  }

}
