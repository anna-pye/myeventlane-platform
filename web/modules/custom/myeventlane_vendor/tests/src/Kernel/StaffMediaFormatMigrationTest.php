<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Kernel;

use Drupal\editor\Entity\Editor;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies the bounded CK-1 current-body migration.
 */
#[Group('myeventlane_vendor')]
#[RunTestsInSeparateProcesses]
final class StaffMediaFormatMigrationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'editor',
    'ckeditor5',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node']);

    foreach (['page', 'article', 'event'] as $bundle) {
      NodeType::create(['type' => $bundle, 'name' => ucfirst($bundle)])->save();
    }
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'body',
      'type' => 'text_with_summary',
    ])->save();
    foreach (['page', 'article', 'event'] as $bundle) {
      FieldConfig::create([
        'entity_type' => 'node',
        'bundle' => $bundle,
        'field_name' => 'body',
        'label' => 'Body',
        'settings' => [
          'allowed_formats' => $bundle === 'event'
            ? ['basic_html']
            : ['staff_media_html', 'basic_html', 'full_html'],
        ],
      ])->save();
    }

    foreach (['basic_html', 'staff_media_html'] as $id) {
      FilterFormat::create([
        'format' => $id,
        'name' => $id,
        'filters' => [
          'filter_html' => [
            'status' => TRUE,
            'settings' => ['allowed_html' => '<p> <strong> <em>'],
          ],
        ],
      ])->save();
    }
    Editor::create([
      'format' => 'staff_media_html',
      'editor' => 'ckeditor5',
      'settings' => ['toolbar' => ['items' => []]],
      'image_upload' => ['status' => FALSE],
    ])->save();
    $role = Role::create(['id' => 'content_editor', 'label' => 'Content editor']);
    $role->grantPermission('use text format staff_media_html');
    $role->save();

    require_once dirname(__DIR__, 3) . '/myeventlane_vendor.install';
  }

  /**
   * Preserves values and old revisions while changing current format IDs.
   */
  public function testMigrationIsEquivalentBoundedAndIdempotent(): void {
    $page = Node::create([
      'type' => 'page',
      'title' => 'Page',
      'body' => ['value' => '<p><strong>Existing</strong> page</p>', 'format' => 'basic_html'],
    ]);
    $page->save();
    $oldRevision = (int) $page->getRevisionId();
    $page->setNewRevision(TRUE);
    $page->set('title', 'Page updated');
    $page->save();

    $article = Node::create([
      'type' => 'article',
      'title' => 'Article',
      'body' => ['value' => '<p>Article</p>', 'format' => 'basic_html'],
    ]);
    $article->save();
    $event = Node::create([
      'type' => 'event',
      'title' => 'Event',
      'body' => ['value' => '<p>Event</p>', 'format' => 'basic_html'],
    ]);
    $event->save();

    $pageValue = $page->body->value;
    self::assertStringContainsString('Migrated 2 current', myeventlane_vendor_update_10024());

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $currentPage = $storage->load($page->id());
    self::assertSame($pageValue, $currentPage->body->value);
    self::assertSame('staff_media_html', $currentPage->body->format);
    self::assertSame('staff_media_html', $storage->load($article->id())->body->format);
    self::assertSame('basic_html', $storage->load($event->id())->body->format);
    self::assertSame('basic_html', $storage->loadRevision($oldRevision)->body->format);

    $ledger = $this->container->get('state')
      ->get('myeventlane_vendor.ck1_staff_media_html_ledger');
    self::assertCount(2, $ledger);
    self::assertSame(hash('sha256', (string) $pageValue), $ledger[0]['value_hash']);
    self::assertStringContainsString('No current', myeventlane_vendor_update_10024());
  }

}
