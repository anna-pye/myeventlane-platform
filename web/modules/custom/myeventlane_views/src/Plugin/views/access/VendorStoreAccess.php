<?php

namespace Drupal\myeventlane_views\Plugin\views\access;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\views\Plugin\views\access\AccessPluginBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Route;

/**
 * Views access plugin for vendor store owner or admin.
 *
 * @ViewsAccess(
 *   id = "vendor_store_access",
 *   title = @Translation("Vendor or Admin Access")
 * )
 */
class VendorStoreAccess extends AccessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger for access_debug channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $accessDebugLogger;

  /**
   * Constructs VendorStoreAccess.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $accessDebugLogger
   *   The access_debug logger channel.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerInterface $accessDebugLogger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entityTypeManager;
    $this->accessDebugLogger = $accessDebugLogger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('logger.factory')->get('access_debug'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account) {
    $this->accessDebugLogger->notice('👤 Checking access for UID @uid with roles: @roles', [
      '@uid' => $account->id(),
      '@roles' => implode(', ', $account->getRoles()),
    ]);

    if ((int) $account->id() === 1) {
      $this->accessDebugLogger->notice('✅ Access granted: UID 1');
      return TRUE;
    }

    if ($account->hasPermission('administer nodes') || $account->hasPermission('access vendor attendee data')) {
      $this->accessDebugLogger->notice('✅ Access granted via permission.');
      return TRUE;
    }

    $store_ids = $this->entityTypeManager
      ->getStorage('commerce_store')
      ->getQuery()
      ->condition('uid', $account->id())
      ->accessCheck(TRUE)
      ->execute();

    $this->accessDebugLogger->notice('Found @count store(s) for UID @uid', [
      '@count' => count($store_ids),
      '@uid' => $account->id(),
    ]);

    return !empty($store_ids);
  }

  /**
   * {@inheritdoc}
   */
  public function alterRouteDefinition(Route $route) {
    // Required for Drupal 11.
  }

  /**
   * {@inheritdoc}
   */
  public function summaryTitle() {
    return $this->t('Vendor store owner or admin');
  }

  /**
   * {@inheritdoc}
   */
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- Views API method name.
  public function get_access_callback() {
    return [$this, 'access'];
  }

}
