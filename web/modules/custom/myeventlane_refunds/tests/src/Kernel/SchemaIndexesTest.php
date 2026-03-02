<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_refunds\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Verifies refund schema composite indexes from update 9004.
 *
 * @group myeventlane_refunds
 */
final class SchemaIndexesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once dirname(__DIR__, 3) . '/myeventlane_refunds.install';

    $schema = $this->container->get('database')->schema();
    $tables = myeventlane_refunds_schema();

    foreach (['myeventlane_refund_log', 'myeventlane_refund_request'] as $tableName) {
      if ($schema->tableExists($tableName)) {
        $schema->dropTable($tableName);
      }
      $schema->createTable($tableName, $tables[$tableName]);
    }
  }

  /**
   * Tests all expected composite indexes exist after update hook runs.
   */
  public function testCompositeIndexesExist(): void {
    myeventlane_refunds_update_9004();
    $schema = $this->container->get('database')->schema();

    $this->assertTrue($schema->indexExists('myeventlane_refund_log', 'order_event'));
    $this->assertTrue($schema->indexExists('myeventlane_refund_log', 'status_created'));
    $this->assertTrue($schema->indexExists('myeventlane_refund_log', 'refund_request_id'));

    $this->assertTrue($schema->indexExists('myeventlane_refund_request', 'event_status_created'));
    $this->assertTrue($schema->indexExists('myeventlane_refund_request', 'vendor_status_created'));
    $this->assertTrue($schema->indexExists('myeventlane_refund_request', 'order_event'));
  }

}

