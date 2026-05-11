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
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
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
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $checker = $container->get('myeventlane_vendor.event_access_checker');
    if (!$checker instanceof EventVendorAccessChecker) {
      throw new InvalidPluginDefinitionException((string) $plugin_id, 'Event Studio section plugins require the EventVendorAccessChecker service.');
    }

    return new static(
      $configuration,
      (string) $plugin_id,
      $plugin_definition,
      $checker,
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
  public function participatesInReadiness(): bool {
    return (bool) ($this->pluginDefinition['readinessParticipant'] ?? FALSE);
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
  public function isDeferred(): bool {
    return (bool) ($this->pluginDefinition['deferred'] ?? FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function access(NodeInterface $event, AccountInterface $account): AccessResultInterface {
    if ($event->bundle() !== 'event') {
      return AccessResult::forbidden()->addCacheContexts(['route']);
    }

    if ($account->isAnonymous()) {
      return AccessResult::forbidden()
        ->addCacheableDependency($event)
        ->addCacheContexts(['user.roles']);
    }

    if ($account->hasPermission('administer nodes')) {
      return AccessResult::allowed()
        ->addCacheableDependency($event)
        ->addCacheContexts(['user.permissions']);
    }

    if ($this->accessPolicy() !== 'event_update') {
      return AccessResult::forbidden()
        ->addCacheableDependency($event)
        ->addCacheContexts(['user', 'user.permissions']);
    }

    if (!$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $account)) {
      return AccessResult::forbidden()
        ->addCacheableDependency($event)
        ->addCacheContexts(['user', 'user.permissions']);
    }

    $entity_access = $event->access('update', $account, TRUE);
    if (!$entity_access->isAllowed()) {
      return $entity_access
        ->andIf(AccessResult::forbidden())
        ->addCacheableDependency($event)
        ->addCacheContexts(['user', 'user.permissions']);
    }

    return AccessResult::allowed()
      ->andIf($entity_access)
      ->addCacheableDependency($event)
      ->addCacheContexts(['user', 'user.permissions']);
  }

}
