<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_boost\Kernel;

use Drupal\Core\Database\Connection;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_boost\Service\BoostBenchmarkService;
use Drupal\myeventlane_boost\Service\BoostPlacementResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

/**
 * Kernel tests for platform Boost benchmark aggregation.
 *
 * @group myeventlane_boost
 */
final class BoostBenchmarkServiceKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
  ];

  /**
   * The service under test.
   */
  private BoostBenchmarkService $benchmarkService;

  /**
   * The test database connection.
   */
  private Connection $database;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once dirname(__DIR__, 3) . '/myeventlane_boost.install';
    $schema = myeventlane_boost_schema();
    $this->database = $this->container->get('database');
    $this->database->schema()->createTable(
      'myeventlane_boost_stats',
      $schema['myeventlane_boost_stats'],
    );
    $this->installEventTypeFieldTable();

    $placementResolver = new BoostPlacementResolver(
      $this->createMock(RouteMatchInterface::class),
      NULL,
      $this->createMock(RouterInterface::class),
      new RequestStack(),
    );

    $this->benchmarkService = new BoostBenchmarkService(
      $this->database,
      $this->container->get('cache.default'),
      $placementResolver,
    );
  }

  /**
   * Benchmarks are not ready when platform totals are below thresholds.
   */
  public function testReadinessFalseWhenInsufficientData(): void {
    $this->insertStatsRow(1, 101, 'homepage_discover', 100, 10);
    $this->insertStatsRow(2, 102, 'homepage_discover', 50, 5);

    $result = $this->benchmarkService->getGlobalBenchmark();

    $this->assertFalse($result['ready']);
    $this->assertSame('insufficient_data', $result['reason']);
    $this->assertSame(150, $result['impressions']);
    $this->assertSame(15, $result['clicks']);
    $this->assertSame(2, $result['distinct_boosted_events']);
  }

  /**
   * Benchmarks become ready once platform thresholds are met.
   */
  public function testReadinessTrueWhenThresholdsMet(): void {
    $now = time();
    for ($i = 1; $i <= 25; $i++) {
      $this->database->insert('myeventlane_boost_stats')
        ->fields([
          'boost_order_item_id' => $i,
          'event_id' => 1000 + $i,
          'placement' => 'homepage_discover',
          'impressions' => 20,
          'clicks' => 2,
          'created' => $now,
          'changed' => $now,
        ])
        ->execute();
    }

    $result = $this->benchmarkService->getGlobalBenchmark();

    $this->assertTrue($result['ready']);
    $this->assertArrayNotHasKey('reason', $result);
    $this->assertSame(500, $result['impressions']);
    $this->assertSame(50, $result['clicks']);
    $this->assertSame(25, $result['distinct_boosted_events']);
  }

  /**
   * CTR is computed as clicks divided by impressions.
   */
  public function testCtrCalculation(): void {
    $this->insertStatsRow(1, 201, 'homepage_discover', 200, 25);

    $result = $this->benchmarkService->getGlobalBenchmark();

    $this->assertSame(0.125, $result['ctr']);
  }

  /**
   * Placement benchmarks filter stats rows by placement key.
   */
  public function testPlacementBenchmark(): void {
    $this->insertStatsRow(1, 301, 'homepage_discover', 120, 12);
    $this->insertStatsRow(2, 302, 'search_results', 80, 8);

    $result = $this->benchmarkService->getPlacementBenchmark('search_results');

    $this->assertFalse($result['ready']);
    $this->assertSame('insufficient_data', $result['reason']);
    $this->assertSame(80, $result['impressions']);
    $this->assertSame(8, $result['clicks']);
    $this->assertSame(0.1, $result['ctr']);
    $this->assertSame(1, $result['distinct_boosted_events']);
  }

  /**
   * Event type benchmarks join boost stats to field_event_type.
   */
  public function testEventTypeBenchmark(): void {
    $this->insertStatsRow(1, 401, 'homepage_discover', 150, 15);
    $this->insertStatsRow(2, 402, 'homepage_discover', 50, 5);
    $this->insertEventType(401, 'paid');
    $this->insertEventType(402, 'rsvp');

    $result = $this->benchmarkService->getEventTypeBenchmark('paid');

    $this->assertFalse($result['ready']);
    $this->assertSame('insufficient_data', $result['reason']);
    $this->assertSame(150, $result['impressions']);
    $this->assertSame(15, $result['clicks']);
    $this->assertSame(0.1, $result['ctr']);
    $this->assertSame(1, $result['distinct_boosted_events']);
  }

  /**
   * Benchmark responses are cached for twelve hours.
   */
  public function testBenchmarkUsesCache(): void {
    $cache = $this->container->get('cache.default');
    assert($cache instanceof CacheBackendInterface);

    $this->insertStatsRow(1, 501, 'homepage_discover', 10, 1);
    $this->benchmarkService->getGlobalBenchmark();

    $this->database->delete('myeventlane_boost_stats')->execute();
    $cached = $this->benchmarkService->getGlobalBenchmark();

    $this->assertSame(10, $cached['impressions']);
    $this->assertSame(1, $cached['clicks']);
    $this->assertNotFalse($cache->get('mel:boost_benchmark:global'));
  }

  /**
   * Creates the minimal node field table used for event type joins.
   */
  private function installEventTypeFieldTable(): void {
    $schema = $this->database->schema();
    if ($schema->tableExists('node__field_event_type')) {
      return;
    }

    $schema->createTable('node__field_event_type', [
      'description' => 'Test event type field table.',
      'fields' => [
        'bundle' => [
          'type' => 'varchar',
          'length' => 128,
          'not null' => TRUE,
          'default' => '',
        ],
        'deleted' => [
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
          'default' => 0,
        ],
        'entity_id' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'revision_id' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'langcode' => [
          'type' => 'varchar',
          'length' => 32,
          'not null' => TRUE,
          'default' => '',
        ],
        'delta' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'field_event_type_value' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => FALSE,
        ],
      ],
      'primary key' => ['entity_id', 'deleted', 'delta', 'langcode'],
    ]);
  }

  /**
   * Inserts a boost stats row.
   */
  private function insertStatsRow(
    int $orderItemId,
    int $eventId,
    string $placement,
    int $impressions,
    int $clicks,
  ): void {
    $now = time();
    $this->database->insert('myeventlane_boost_stats')
      ->fields([
        'boost_order_item_id' => $orderItemId,
        'event_id' => $eventId,
        'placement' => $placement,
        'impressions' => $impressions,
        'clicks' => $clicks,
        'created' => $now,
        'changed' => $now,
      ])
      ->execute();
  }

  /**
   * Inserts an event type field value for benchmark joins.
   */
  private function insertEventType(int $eventId, string $eventType): void {
    $this->database->insert('node__field_event_type')
      ->fields([
        'bundle' => 'event',
        'deleted' => 0,
        'entity_id' => $eventId,
        'revision_id' => $eventId,
        'langcode' => 'en',
        'delta' => 0,
        'field_event_type_value' => $eventType,
      ])
      ->execute();
  }

}
