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
   * Anchors for the three routes that share EventInformationForm.
   */
  private const SHARED_INFORMATION_ANCHORS = [
    'information' => 'mel-es-details',
    'schedule' => 'mel-es-schedule',
    'venue' => 'mel-es-venue-location',
  ];

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
   * Returns accessible sections keyed by section id.
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
   * Builds operational metadata for a section.
   *
   * @return array<string, mixed>
   */
  public function sectionMetadata(EventStudioSectionInterface $section): array {
    return [
      'id' => (string) $section->getPluginId(),
      'label' => $section->title(),
      'group' => $section->group(),
      'icon' => $section->icon(),
      'route_fragment' => $section->routeFragment(),
      'section_state' => $section->sectionState(),
      'writable' => $section->isWritable(),
      'supports_autosave' => $section->supportsAutosave(),
      'supports_publish' => $section->supportsPublish(),
      'supports_readiness' => $section->supportsReadiness(),
      'supports_mobile_priority' => $section->supportsMobilePriority(),
      'readiness_participant' => $section->participatesInReadiness(),
      'operational_area' => $section->operationalArea(),
      'empty_state_type' => $section->emptyStateType(),
      'mobile_priority' => $section->mobilePriority(),
      'navigation_visible' => $section->isVisibleInNavigation(),
      'deferred' => $section->isDeferred(),
      'readonly' => $section->sectionState() === EventStudioSectionInterface::STATE_READONLY,
    ];
  }

  /**
   * Builds grouped sidebar links for active sections.
   *
   * @return array<string, array<int, array<string, mixed>>>
   */
  public function buildNavigation(NodeInterface $event, AccountInterface $account, string $current_section): array {
    $groups = [];
    foreach ($this->activeSections($event, $account) as $section_id => $section) {
      if (!$section->isVisibleInNavigation()) {
        continue;
      }
      $group = $section->group();
      $groups[$group] ??= [];
      $metadata = $this->sectionMetadata($section);
      $url_options = [];
      if (isset(self::SHARED_INFORMATION_ANCHORS[$section_id])) {
        $url_options['fragment'] = self::SHARED_INFORMATION_ANCHORS[$section_id];
      }
      $groups[$group][] = $metadata + [
        'url' => Url::fromRoute($section->routeName(), ['node' => $event->id()], $url_options)->toString(),
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
