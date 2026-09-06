<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_help_centre\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilderInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_help_centre\Service\HelpArticleBrowsePolicy;
use Drupal\myeventlane_help_centre\Service\RelatedHelpBuilder;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('myeventlane_help_centre')]
final class RelatedHelpBuilderTest extends UnitTestCase {

  public function testRelatedArticlesRespectAccessAudienceAndLimit(): void {
    $container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
    $cacheContexts = $this->createMock(\Drupal\Core\Cache\Context\CacheContextsManager::class);
    $cacheContexts->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cacheContexts);
    \Drupal::setContainer($container);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $query = $this->createMock(QueryInterface::class);
    $query->expects(self::once())->method('accessCheck')->with(TRUE)->willReturnSelf();
    $conditions = [];
    $query->method('condition')->willReturnCallback(function (...$args) use (&$conditions, $query) {
      $conditions[] = array_slice($args, 0, 3);
      return $query;
    });
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn([2, 3, 4, 5, 6, 7]);
    $storage->method('getQuery')->willReturn($query);
    $nodes = [];
    foreach (range(2, 7) as $id) {
      $nodes[$id] = $this->node($id, $id !== 2);
    }
    $storage->method('loadMultiple')->willReturn($nodes);
    $manager->method('getStorage')->willReturn($storage);
    $view = $this->createMock(EntityViewBuilderInterface::class);
    $rendered = [];
    $view->method('view')->willReturnCallback(function ($node) use (&$rendered) {
      $rendered[] = $node->id();
      return ['#markup' => 'Article'];
    });
    $manager->method('getViewBuilder')->willReturn($view);
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);
    $aliases = $this->createMock(AliasManagerInterface::class);
    $aliases->method('getAliasByPath')->willReturnCallback(fn ($path) => $path === '/node/3' ? '/help/organisers/setup' : '/help/attendees/tickets');
    $builder = new RelatedHelpBuilder($manager, $account, new HelpArticleBrowsePolicy(), $aliases);
    $build = $builder->build($this->node(1));
    self::assertSame([4, 5, 6], $rendered);
    self::assertContains(['field_audience', ['public'], 'IN'], $conditions);
    self::assertContains(['field_help_status', ['published', 'approved'], 'IN'], $conditions);
    self::assertContains(['field_help_topic', [10], 'IN'], $conditions);
    self::assertContains('user.permissions', $build['#cache']['contexts']);
    self::assertContains('node:2', $build['#cache']['tags']);
  }

  private function node(int $id, bool $allowed = TRUE): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($id);
    $node->method('getCacheTags')->willReturn(['node:' . $id]);
    $node->method('getCacheContexts')->willReturn([]);
    $node->method('getCacheMaxAge')->willReturn(-1);
    $node->method('hasField')->willReturn(TRUE);
    $node->method('access')->willReturn($allowed ? AccessResult::allowed() : AccessResult::forbidden()->cachePerPermissions());
    $node->method('get')->willReturnCallback(function ($field) {
      $items = $this->createMock(FieldItemListInterface::class);
      $values = match ($field) {
        'field_audience' => [['value' => 'public']],
        'field_help_topic' => [['target_id' => 10]],
        default => [],
      };
      $items->method('getValue')->willReturn($values);
      $items->method('isEmpty')->willReturn($values === []);
      return $items;
    });
    return $node;
  }

}
