<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_help_assistant\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Verifies Help Assistant retriever behaviour.
 *
 * @group myeventlane_help_assistant
 */
final class HelpRetrieverKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'node',
    'options',
    'taxonomy',
    'path_alias',
    'myeventlane_help_assistant',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['myeventlane_help_assistant']);

    $this->createBundles();
    $this->createFields();
    $this->seedHelpContent();
  }

  /**
   * Tests retrieval safely returns empty set without Search API index.
   */
  public function testRetrieveReturnsEmptyWithoutSearchIndex(): void {
    $retriever = $this->container->get('myeventlane_help_assistant.retriever');
    $this->assertNotNull($retriever);

    $results = $retriever->retrieve('How do I request a refund?', 5);
    $this->assertSame([], $results);
  }

  /**
   * Creates node bundles and body fields.
   */
  private function createBundles(): void {
    NodeType::create([
      'type' => 'help_article',
      'name' => 'Help Article',
    ])->save();
    NodeType::create([
      'type' => 'faq',
      'name' => 'FAQ',
    ])->save();

    node_add_body_field('help_article');
    node_add_body_field('faq');

    Vocabulary::create([
      'vid' => 'help_categories',
      'name' => 'Help Categories',
    ])->save();
  }

  /**
   * Creates field_help_category and canonical field_audience for test bundles.
   */
  private function createFields(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_help_category',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => [
        'target_type' => 'taxonomy_term',
      ],
      'cardinality' => -1,
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_audience',
      'entity_type' => 'node',
      'type' => 'list_string',
      'settings' => [
        'allowed_values' => [
          'public' => 'Public',
          'vendor' => 'Vendor',
          'staff' => 'Staff',
        ],
      ],
      'cardinality' => -1,
    ])->save();

    foreach (['help_article', 'faq'] as $bundle) {
      FieldConfig::create([
        'field_name' => 'field_help_category',
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => 'Help category',
        'settings' => [
          'handler' => 'default:taxonomy_term',
          'handler_settings' => ['target_bundles' => ['help_categories' => 'help_categories']],
        ],
      ])->save();

      FieldConfig::create([
        'field_name' => 'field_audience',
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => 'Audience',
        'required' => FALSE,
      ])->save();
    }
  }

  /**
   * Seeds minimal help content records for relevance assertions.
   */
  private function seedHelpContent(): void {
    $refundCategory = Term::create([
      'vid' => 'help_categories',
      'name' => 'Refunds',
    ]);
    $refundCategory->save();

    Node::create([
      'type' => 'faq',
      'title' => 'How refund requests work',
      'status' => 1,
      'body' => [
        'value' => 'If your event is cancelled, submit your refund request from your booking page.',
        'format' => 'plain_text',
      ],
      'field_help_category' => [$refundCategory->id()],
      'field_audience' => [
        ['value' => 'public'],
      ],
    ])->save();

    Node::create([
      'type' => 'help_article',
      'title' => 'Updating profile preferences',
      'status' => 1,
      'body' => [
        'value' => 'You can update your profile settings from your account dashboard.',
        'format' => 'plain_text',
      ],
      'field_help_category' => [$refundCategory->id()],
      'field_audience' => [
        ['value' => 'public'],
      ],
    ])->save();
  }

}
