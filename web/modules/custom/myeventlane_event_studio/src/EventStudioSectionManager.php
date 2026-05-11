<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event_studio\Attribute\EventStudioSection;
use Drupal\myeventlane_event_studio\Plugin\EventStudioSection\EventStudioSectionInterface;
use Drupal\node\NodeInterface;

/**
 * Discovers and orders governed Event Studio section plugins.
 */
final class EventStudioSectionManager extends DefaultPluginManager {

  /**
   * Constructs the section plugin manager.
   */
  public function __construct(\Traversable $namespaces, ModuleHandlerInterface $module_handler, CacheBackendInterface $cache_backend) {
    parent::__construct(
      'Plugin/EventStudioSection',
      $namespaces,
      $module_handler,
      EventStudioSectionInterface::class,
      EventStudioSection::class,
    );
    $this->alterInfo('myeventlane_event_studio_section_info');
    $this->setCacheBackend($cache_backend, 'myeventlane_event_studio_sections');
  }

  /**
   * Returns a section plugin instance, or NULL when it is unknown.
   */
  public function section(string $section_id): ?EventStudioSectionInterface {
    try {
      $instance = $this->createInstance($section_id);
    }
    catch (PluginException) {
      return NULL;
    }
    return $instance instanceof EventStudioSectionInterface ? $instance : NULL;
  }

  /**
   * Returns TRUE when a section exists.
   */
  public function hasSection(string $section_id): bool {
    return isset($this->getDefinitions()[$section_id]);
  }

  /**
   * Returns active sections keyed by section id.
   *
   * @return array<string, \Drupal\myeventlane_event_studio\Plugin\EventStudioSection\EventStudioSectionInterface>
   */
  public function activeSections(NodeInterface $event, AccountInterface $account): array {
    $sections = [];
    foreach (array_keys($this->getDefinitions()) as $section_id) {
      $section = $this->section((string) $section_id);
      if (!$section instanceof EventStudioSectionInterface) {
        continue;
      }
      if (!$this->sectionAccess((string) $section_id, $event, $account)->isAllowed()) {
        continue;
      }
      $sections[(string) $section_id] = $section;
    }
    uasort($sections, static function (EventStudioSectionInterface $a, EventStudioSectionInterface $b): int {
      return [$a->groupWeight(), $a->weight(), $a->title()] <=> [$b->groupWeight(), $b->weight(), $b->title()];
    });
    return $sections;
  }

  /**
   * Evaluates direct access to a section plugin.
   */
  public function sectionAccess(string $section_id, NodeInterface $event, AccountInterface $account): AccessResultInterface {
    $section = $this->section($section_id);
    if (!$section instanceof EventStudioSectionInterface) {
      return AccessResult::forbidden()
        ->addCacheableDependency($event)
        ->addCacheContexts(['route']);
    }

    return $section->access($event, $account);
  }

  /**
   * Builds grouped sidebar links for active sections.
   *
   * @return array<string, array<int, array<string, mixed>>>
   */
  public function buildNavigation(NodeInterface $event, AccountInterface $account, string $current_section): array {
    $groups = [];
    foreach ($this->activeSections($event, $account) as $section_id => $section) {
      $group = $section->group();
      $groups[$group] ??= [];
      $groups[$group][] = [
        'id' => $section_id,
        'label' => $section->title(),
        'icon' => $section->icon(),
        'route_fragment' => $section->routeFragment(),
        'operational_area' => $section->operationalArea(),
        'deferred' => $section->isDeferred(),
        'url' => Url::fromRoute($section->routeName(), ['node' => $event->id()])->toString(),
        'active' => $section_id === $current_section,
      ];
    }
    return $groups;
  }

  /**
   * Returns a section title, or a generic label when unavailable.
   */
  public function sectionTitle(string $section_id): string {
    return $this->section($section_id)?->title() ?? 'Section';
  }

  /**
   * Returns a section route, falling back to the overview route.
   */
  public function sectionRouteName(string $section_id): string {
    return $this->section($section_id)?->routeName() ?? 'myeventlane_event_studio.workspace';
  }

}
