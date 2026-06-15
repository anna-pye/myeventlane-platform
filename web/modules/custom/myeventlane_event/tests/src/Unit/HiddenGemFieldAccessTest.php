<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;

// Load the procedural module file so the hook function is declared. The file
// contains only function declarations (no top-level side effects), so this is
// safe in a unit test.
require_once __DIR__ . '/../../../myeventlane_event.module';

/**
 * Unit coverage for myeventlane_event_entity_field_access().
 *
 * The hook depends only on its arguments (no container), so it is tested as a
 * pure function. A Kernel test is impractical here because the myeventlane_event
 * service container requires the full module dependency chain (myeventlane_donations
 * et al.) to compile.
 *
 * @group myeventlane_event
 *
 * @covers ::myeventlane_event_entity_field_access
 */
final class HiddenGemFieldAccessTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // AccessResult::cachePerPermissions() validates cache contexts against the
    // container, so provide a minimal one.
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);
  }

  /**
   * Invokes the hook with mocked dependencies.
   */
  private function fieldAccess(string $operation, string $field_name, bool $has_permission): \Drupal\Core\Access\AccessResultInterface {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getName')->willReturn($field_name);

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      static fn (string $perm): bool => $perm === 'administer hidden gem flag' ? $has_permission : FALSE
    );

    return myeventlane_event_entity_field_access($operation, $field_definition, $account, NULL);
  }

  /**
   * Vendors (no permission) are forbidden from editing the Hidden Gem flag.
   */
  public function testEditForbiddenWithoutPermission(): void {
    $this->assertTrue(
      $this->fieldAccess('edit', 'field_hidden_gem', FALSE)->isForbidden(),
      'Editing field_hidden_gem without the permission must be forbidden.'
    );
  }

  /**
   * Staff with the permission are not forbidden (field stays editable).
   */
  public function testEditAllowedWithPermission(): void {
    $result = $this->fieldAccess('edit', 'field_hidden_gem', TRUE);
    $this->assertFalse(
      $result->isForbidden(),
      'Editing field_hidden_gem with the permission must not be forbidden.'
    );
  }

  /**
   * Other fields are never affected by this hook (neutral).
   */
  public function testOtherFieldUnaffected(): void {
    $this->assertFalse(
      $this->fieldAccess('edit', 'field_event_start', FALSE)->isForbidden(),
      'The hook must only constrain field_hidden_gem.'
    );
  }

  /**
   * View access to the flag is not restricted (badge is public).
   */
  public function testViewOperationUnaffected(): void {
    $this->assertFalse(
      $this->fieldAccess('view', 'field_hidden_gem', FALSE)->isForbidden(),
      'View access to field_hidden_gem must not be forbidden.'
    );
  }

}
