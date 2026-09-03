<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_boost\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_boost\BoostManager;
use Drupal\myeventlane_boost\Service\BoostDecisionSupportService;
use Drupal\myeventlane_boost\Service\BoostExtensionRecommendationService;
use Drupal\myeventlane_boost\Service\BoostEntitlementManager;
use Drupal\myeventlane_boost\Service\BoostMetricsService;
use Drupal\myeventlane_boost\Service\BoostPerformanceLevelService;
use Drupal\myeventlane_boost\Service\BoostTrendIntelligenceService;
use Drupal\myeventlane_core\Service\EventDateTimeResolver;
use Drupal\myeventlane_event_state\Service\EventStateResolver;
use Drupal\myeventlane_vendor\Service\BoostStatusService;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * Unit tests for Boost extension recommendations (Phase 11C).
 *
 * @group myeventlane_boost
 */
final class BoostExtensionRecommendationServiceTest extends UnitTestCase {

  private const NOW = 1_700_000_000;

  private BoostExtensionRecommendationService $service;

  private TimeInterface $time;

  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $translation = $this->createMock(TranslationInterface::class);
    $translation
      ->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString());

    $this->time = $this->createMock(TimeInterface::class);
    $this->time->method('getRequestTime')->willReturn(self::NOW);

    $logger = $this->createMock(LoggerInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $dateFormatter = $this->createMock(DateFormatterInterface::class);
    $dateFormatter->method('format')->willReturn('1 Jan');

    $boostManager = new BoostManager($this->entityTypeManager, $this->time, $logger);
    $boostStatusService = new BoostStatusService(
      $boostManager,
      $this->entityTypeManager,
      $dateFormatter,
      $this->time,
    );

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $systemDate = $this->createMock(ImmutableConfig::class);
    $systemDate->method('get')->with('timezone.default')->willReturn('Australia/Sydney');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('system.date')->willReturn($systemDate);
    $eventStateResolver = new EventStateResolver(
      $this->time,
      $this->entityTypeManager,
      $cache,
      new EventDateTimeResolver($configFactory),
      NULL,
    );

    $decisionSupport = new BoostDecisionSupportService($translation);
    $trendIntelligence = new BoostTrendIntelligenceService($translation);
    $performanceLevel = new BoostPerformanceLevelService($translation);
    $entitlementManager = new BoostEntitlementManager(
      $this->entityTypeManager,
      $this->time,
      $logger,
    );
    $boostMetricsService = new BoostMetricsService(
      $this->createMock(Connection::class),
      $this->entityTypeManager,
      $boostManager,
      $entitlementManager,
      $this->time,
      $translation,
    );

    $this->service = new BoostExtensionRecommendationService(
      $boostStatusService,
      $boostManager,
      $performanceLevel,
      $decisionSupport,
      $trendIntelligence,
      $boostMetricsService,
      $eventStateResolver,
      $this->time,
      $translation,
    );
  }

  /**
   * Rule 1 — Boost expiring soon.
   */
  public function testExpiringSoonRecommendation(): void {
    $event = $this->createEventNode(
      published: TRUE,
      eventStart: self::NOW + (2 * 86400),
      eventEnd: self::NOW + (5 * 86400),
      promoted: TRUE,
      promoExpires: gmdate('Y-m-d\TH:i:s', self::NOW + 86400),
    );
    $this->entityTypeManager->method('getStorage')->willReturn($this->nodeStorageReturning($event));

    $result = $this->service->getRecommendation($event);

    $this->assertNotNull($result);
    $this->assertSame('extend', $result['type']);
    $this->assertSame(100, $result['priority']);
    $this->assertSame('Your Boost ends soon', $result['title']);
    $this->assertSame('Extend Boost', $result['cta_label']);
    $this->assertIsString($result['cta_url']);
  }

  /**
   * Rule 2 — Strong Boost performance.
   */
  public function testStrongPerformanceRecommendation(): void {
    $event = $this->createEventNode(
      published: TRUE,
      eventStart: self::NOW - 86400,
      eventEnd: self::NOW + (10 * 86400),
      eventType: 'paid',
      hasProduct: TRUE,
      promoted: TRUE,
      promoExpires: gmdate('Y-m-d\TH:i:s', self::NOW + (10 * 86400)),
    );
    $this->entityTypeManager->method('getStorage')->willReturn($this->nodeStorageReturning($event));
    $this->replaceMetricsStub([
      'impressions' => 500,
      'clicks' => 80,
      'ctr' => 0.05,
      'spend' => '$45.00',
      'revenue_during_boost' => 300.0,
      'orders_during_boost' => 5,
    ]);

    $result = $this->service->getRecommendation($event);

    $this->assertNotNull($result);
    $this->assertSame('extend', $result['type']);
    $this->assertSame(90, $result['priority']);
    $this->assertSame('Your Boost is performing well', $result['title']);
  }

  /**
   * Rule 3 — Recently expired Boost.
   */
  public function testRecentlyExpiredRecommendation(): void {
    $event = $this->createEventNode(
      published: TRUE,
      eventStart: self::NOW + (3 * 86400),
      eventEnd: self::NOW + (7 * 86400),
      promoted: TRUE,
      promoExpires: gmdate('Y-m-d\TH:i:s', self::NOW - (2 * 86400)),
    );
    $this->entityTypeManager->method('getStorage')->willReturn($this->nodeStorageReturning($event));

    $result = $this->service->getRecommendation($event);

    $this->assertNotNull($result);
    $this->assertSame('reboost', $result['type']);
    $this->assertSame(80, $result['priority']);
    $this->assertSame('Your Boost has ended', $result['title']);
    $this->assertSame('Re-Boost Event', $result['cta_label']);
  }

  /**
   * Rule 4 — Upcoming event without Boost.
   */
  public function testUpcomingEventRecommendation(): void {
    $event = $this->createEventNode(
      published: TRUE,
      eventStart: self::NOW + (10 * 86400),
      eventEnd: self::NOW + (11 * 86400),
      promoted: FALSE,
      promoExpires: NULL,
    );
    $this->entityTypeManager->method('getStorage')->willReturn($this->nodeStorageReturning($event));

    $result = $this->service->getRecommendation($event);

    $this->assertNotNull($result);
    $this->assertSame('boost', $result['type']);
    $this->assertSame(70, $result['priority']);
    $this->assertSame('Increase attendance before your event', $result['title']);
    $this->assertSame('Boost Event', $result['cta_label']);
  }

  /**
   * Ended events never receive recommendations.
   */
  public function testEndedEventReturnsNull(): void {
    $event = $this->createEventNode(
      published: TRUE,
      eventStart: self::NOW - (10 * 86400),
      eventEnd: self::NOW - 86400,
      promoted: FALSE,
      promoExpires: NULL,
    );
    $this->entityTypeManager->method('getStorage')->willReturn($this->nodeStorageReturning($event));

    $this->assertNull($this->service->getRecommendation($event));
  }

  /**
   * Archived events never receive recommendations.
   */
  public function testArchivedEventReturnsNull(): void {
    $event = $this->createEventNode(
      published: TRUE,
      eventStart: self::NOW + 86400,
      eventEnd: self::NOW + (2 * 86400),
      stateOverride: 'archived',
      promoted: FALSE,
      promoExpires: NULL,
    );
    $this->entityTypeManager->method('getStorage')->willReturn($this->nodeStorageReturning($event));

    $this->assertNull($this->service->getRecommendation($event));
  }

  /**
   * Highest priority rule wins when multiple match.
   */
  public function testPriorityOrdering(): void {
    $event = $this->createEventNode(
      published: TRUE,
      eventStart: self::NOW + (2 * 86400),
      eventEnd: self::NOW + (8 * 86400),
      eventType: 'paid',
      hasProduct: TRUE,
      promoted: TRUE,
      promoExpires: gmdate('Y-m-d\TH:i:s', self::NOW + 86400),
    );
    $this->entityTypeManager->method('getStorage')->willReturn($this->nodeStorageReturning($event));
    $this->replaceMetricsStub([
      'impressions' => 500,
      'clicks' => 80,
      'ctr' => 0.05,
      'spend' => '$45.00',
      'revenue_during_boost' => 300.0,
      'orders_during_boost' => 5,
    ]);

    $result = $this->service->getRecommendation($event);

    $this->assertNotNull($result);
    $this->assertSame(100, $result['priority']);
    $this->assertSame('Your Boost ends soon', $result['title']);
  }

  /**
   * Builds a mock event node with optional field values.
   */
  private function createEventNode(
    bool $published = TRUE,
    ?int $eventStart = NULL,
    ?int $eventEnd = NULL,
    string $eventType = '',
    bool $hasProduct = FALSE,
    string $stateOverride = '',
    bool $promoted = FALSE,
    ?string $promoExpires = NULL,
  ): NodeInterface {
    $event = $this->createMock(NodeInterface::class);
    $event->method('id')->willReturn(42);
    $event->method('bundle')->willReturn('event');
    $event->method('isPublished')->willReturn($published);

    $event->method('hasField')->willReturnCallback(static function (string $field): bool {
      return in_array($field, [
        'field_event_start',
        'field_event_end',
        'field_event_type',
        'field_product_target',
        'field_event_state_override',
        'field_promoted',
        'field_promo_expires',
      ], TRUE);
    });

    $event->method('get')->willReturnCallback(function (string $field) use (
      $eventType,
      $hasProduct,
      $eventStart,
      $eventEnd,
      $stateOverride,
      $promoted,
      $promoExpires,
    ) {
      return match ($field) {
        'field_event_type' => $this->fieldList($eventType),
        'field_product_target' => $this->fieldList($hasProduct ? '1' : ''),
        'field_event_start' => $this->dateFieldList($eventStart),
        'field_event_end' => $this->dateFieldList($eventEnd),
        'field_event_state_override' => $this->fieldList($stateOverride),
        'field_promoted' => $this->fieldList($promoted ? '1' : '0'),
        'field_promo_expires' => $this->fieldList($promoExpires ?? ''),
        default => $this->fieldList(''),
      };
    });

    return $event;
  }

  /**
   * @param array<string, mixed> $metrics
   */
  private function replaceMetricsStub(array $metrics): void {
    $stub = new class($metrics) {
      public function __construct(private readonly array $metrics) {}

      public function getEventBoostMetrics(NodeInterface $event): array {
        return $this->metrics;
      }
    };

    $property = new ReflectionProperty(BoostExtensionRecommendationService::class, 'boostMetricsService');
    $property->setAccessible(TRUE);
    $property->setValue($this->service, $stub);
  }

  private function nodeStorageReturning(NodeInterface $event): object {
    return new class($event) {
      public function __construct(private readonly NodeInterface $event) {}

      public function load($id): NodeInterface {
        return $this->event;
      }
    };
  }

  private function fieldList(?string $value): object {
    $value = $value ?? '';
    return new class($value) {
      public function __construct(public mixed $value) {}

      public function isEmpty(): bool {
        return $this->value === '' || $this->value === NULL;
      }
    };
  }

  private function dateFieldList(?int $timestamp): object {
    if ($timestamp === NULL) {
      return new class {
        public function isEmpty(): bool {
          return TRUE;
        }
      };
    }

    $date = new class($timestamp) {
      public function __construct(private readonly int $timestamp) {}

      public function getTimestamp(): int {
        return $this->timestamp;
      }
    };

    $value = (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone(new \DateTimeZone('Australia/Sydney'))
      ->format('Y-m-d\TH:i:s');

    return new class($date, $value) {
      public function __construct(public object $date, public string $value) {}

      public function isEmpty(): bool {
        return FALSE;
      }

      public function getValue(): array {
        return [['value' => $this->value]];
      }
    };
  }

}
