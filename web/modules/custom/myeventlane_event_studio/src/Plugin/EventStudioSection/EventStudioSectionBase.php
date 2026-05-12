<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\myeventlane_event_studio\Service\EventStudioSectionRenderer;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base implementation for Event Studio section metadata plugins.
 */
abstract class EventStudioSectionBase extends PluginBase implements EventStudioSectionInterface, ContainerFactoryPluginInterface {

  /**
   * Constructs the Event Studio section plugin base.
   *
   * @param array<string, mixed> $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly EventStudioSectionRenderer $sectionRenderer,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $state = (string) ($this->pluginDefinition['section_state'] ?? '');
    if (!in_array($state, EventStudioSectionInterface::STATES, TRUE)) {
      throw new InvalidPluginDefinitionException($plugin_id, sprintf(
        'Event Studio section "%s" must declare a valid section_state.',
        $plugin_id,
      ));
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $renderer = $container->get('myeventlane_event_studio.section_renderer');
    if (!$renderer instanceof EventStudioSectionRenderer) {
      throw new InvalidPluginDefinitionException((string) $plugin_id, 'Event Studio section plugins require the EventStudioSectionRenderer service.');
    }

    return new static(
      $configuration,
      (string) $plugin_id,
      $plugin_definition,
      $renderer,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function title(): string {
    return (string) new TranslatableMarkup((string) $this->pluginDefinition['title']);
  }

  /**
   * {@inheritdoc}
   */
  public function group(): string {
    return (string) new TranslatableMarkup((string) $this->pluginDefinition['group']);
  }

  /**
   * {@inheritdoc}
   */
  public function routeName(): string {
    return (string) $this->pluginDefinition['routeName'];
  }

  /**
   * {@inheritdoc}
   */
  public function routeFragment(): string {
    $fragment = (string) ($this->pluginDefinition['routeFragment'] ?? '');
    return $fragment !== '' ? $fragment : (string) $this->getPluginId();
  }

  /**
   * {@inheritdoc}
   */
  public function weight(): int {
    return (int) ($this->pluginDefinition['weight'] ?? 0);
  }

  /**
   * {@inheritdoc}
   */
  public function groupWeight(): int {
    return match ((string) $this->pluginDefinition['group']) {
      'Manage Event' => 0,
      'Commerce' => 100,
      'Operations' => 200,
      default => 500,
    };
  }

  /**
   * {@inheritdoc}
   */
  public function icon(): string {
    return (string) ($this->pluginDefinition['icon'] ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function accessPolicy(): string {
    return (string) ($this->pluginDefinition['accessPolicy'] ?? 'event_update');
  }

  /**
   * {@inheritdoc}
   */
  public function renderTarget(): string {
    return (string) ($this->pluginDefinition['renderTarget'] ?? 'controller');
  }

  /**
   * {@inheritdoc}
   */
  public function sectionState(): string {
    return (string) $this->pluginDefinition['section_state'];
  }

  /**
   * {@inheritdoc}
   */
  public function isWritable(): bool {
    return (bool) ($this->pluginDefinition['writable'] ?? TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function supportsAutosave(): bool {
    if (array_key_exists('supports_autosave', $this->pluginDefinition) && $this->pluginDefinition['supports_autosave'] !== NULL) {
      return (bool) $this->pluginDefinition['supports_autosave'];
    }
    return $this->isWritable() && $this->sectionState() === EventStudioSectionInterface::STATE_ACTIVE;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsPublish(): bool {
    if (array_key_exists('supports_publish', $this->pluginDefinition) && $this->pluginDefinition['supports_publish'] !== NULL) {
      return (bool) $this->pluginDefinition['supports_publish'];
    }
    return $this->isWritable() && $this->sectionState() === EventStudioSectionInterface::STATE_ACTIVE;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsReadiness(): bool {
    if (array_key_exists('supports_readiness', $this->pluginDefinition) && $this->pluginDefinition['supports_readiness'] !== NULL) {
      return (bool) $this->pluginDefinition['supports_readiness'];
    }
    return $this->participatesInReadiness();
  }

  /**
   * {@inheritdoc}
   */
  public function supportsMobilePriority(): bool {
    if (array_key_exists('supports_mobile_priority', $this->pluginDefinition) && $this->pluginDefinition['supports_mobile_priority'] !== NULL) {
      return (bool) $this->pluginDefinition['supports_mobile_priority'];
    }
    return $this->mobilePriority() < 100;
  }

  /**
   * {@inheritdoc}
   */
  public function participatesInReadiness(): bool {
    return (bool) ($this->pluginDefinition['readiness_participant'] ?? FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function operationalArea(): string {
    return (string) ($this->pluginDefinition['operationalArea'] ?? 'event');
  }

  /**
   * {@inheritdoc}
   */
  public function emptyStateType(): string {
    return (string) ($this->pluginDefinition['empty_state_type'] ?? 'none');
  }

  /**
   * {@inheritdoc}
   */
  public function mobilePriority(): int {
    return (int) ($this->pluginDefinition['mobile_priority'] ?? 100);
  }

  /**
   * {@inheritdoc}
   */
  public function isVisibleInNavigation(): bool {
    return (bool) ($this->pluginDefinition['navigationVisible'] ?? TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function isDeferred(): bool {
    return $this->sectionState() === EventStudioSectionInterface::STATE_DEFERRED;
  }

  /**
   * {@inheritdoc}
   */
  public function build(NodeInterface $event): array {
    return $this->sectionRenderer->build($this, $event);
  }

  /**
   * {@inheritdoc}
   */
  public function access(NodeInterface $event, AccountInterface $account): AccessResultInterface {
    if ($event->bundle() !== 'event') {
      return AccessResult::forbidden()->addCacheContexts(['route']);
    }

    if ($this->sectionState() === EventStudioSectionInterface::STATE_COMING_SOON) {
      return AccessResult::forbidden()
        ->addCacheableDependency($event)
        ->addCacheContexts(['route']);
    }

    return AccessResult::allowed()
      ->addCacheableDependency($event)
      ->addCacheContexts(['route']);
  }

}
