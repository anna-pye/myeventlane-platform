<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Organiser context block for site header.
 *
 * A) If user has organiser role: show organiser tools menu.
 * B) Else if viewing event/organiser page: show "Hosted by {Name}".
 *
 * @Block(
 *   id = "myeventlane_organiser_context",
 *   admin_label = @Translation("Organiser context"),
 *   category = @Translation("MyEventLane")
 * )
 */
final class OrganiserContextBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the block.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected AccountProxyInterface $currentUser,
    protected RouteMatchInterface $routeMatch,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('current_route_match'),
      $container->get('entity_type.manager'),
      $container->get('file_url_generator'),
      $container->get('module_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    if (!$this->moduleHandler->moduleExists('myeventlane_vendor')) {
      return [];
    }

    $cache_metadata = new CacheableMetadata();
    $cache_metadata->addCacheContexts(['user.roles', 'route']);
    $cache_tags = [];

    // A) User has organiser role (access vendor console).
    if ($this->currentUser->hasPermission('access vendor console')) {
      $vendor = $this->getVendorForUser((int) $this->currentUser->id());
      if ($vendor) {
        $cache_tags[] = 'myeventlane_vendor:' . $vendor->id();
        $build = $this->buildOrganiserToolsMenu($vendor);
      }
      else {
        $build = $this->buildOrganiserToolsMenu(NULL);
      }
    }
    else {
      // B) Viewing event or organiser page with organiser reference.
      [$vendor, $event_node] = $this->getVendorFromRoute();
      if ($vendor) {
        $cache_tags[] = 'myeventlane_vendor:' . $vendor->id();
        if ($event_node) {
          $cache_tags[] = 'node:' . $event_node->id();
        }
        $build = $this->buildHostedBy($vendor);
      }
      else {
        return [];
      }
    }

    $build['#cache']['contexts'] = ['user.roles', 'route'];
    $build['#cache']['tags'] = array_unique($cache_tags);

    return $build;
  }

  /**
   * Gets vendor for current user (owner or in field_vendor_users).
   */
  private function getVendorForUser(int $uid): ?object {
    $storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute();
    if (!empty($ids)) {
      return $storage->load(reset($ids));
    }
    // Check field_vendor_users.
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_vendor_users', $uid)
      ->range(0, 1)
      ->execute();
    if (!empty($ids)) {
      return $storage->load(reset($ids));
    }
    return NULL;
  }

  /**
   * Gets vendor from current route (event or organiser page).
   *
   * @return array{0: object|null, 1: NodeInterface|null}
   *   [vendor, event_node]. event_node is set when vendor comes from event.
   */
  private function getVendorFromRoute(): array {
    $route_name = $this->routeMatch->getRouteName();
    if ($route_name === 'entity.myeventlane_vendor.canonical') {
      $vendor = $this->routeMatch->getParameter('myeventlane_vendor');
      return [$vendor instanceof EntityInterface ? $vendor : NULL, NULL];
    }
    if ($route_name === 'entity.node.canonical') {
      $node = $this->routeMatch->getParameter('node');
      if ($node && $node->bundle() === 'event' && $node->hasField('field_event_vendor') && !$node->get('field_event_vendor')->isEmpty()) {
        $vendor = $node->get('field_event_vendor')->entity;
        return [$vendor, $node];
      }
    }
    return [NULL, NULL];
  }

  /**
   * Builds organiser tools menu (Dashboard, My events, Create event, Settings).
   */
  private function buildOrganiserToolsMenu(?object $vendor): array {
    $links = [
      [
        'title' => $this->t('Dashboard'),
        'url' => Url::fromRoute('myeventlane_vendor.console.dashboard'),
      ],
      [
        'title' => $this->t('My events'),
        'url' => Url::fromRoute('myeventlane_vendor.console.events'),
      ],
      [
        'title' => $this->t('Create event'),
        'url' => Url::fromRoute('myeventlane_vendor.console.create_event'),
      ],
      [
        'title' => $this->t('Settings'),
        'url' => Url::fromRoute('myeventlane_vendor.console.settings'),
      ],
    ];

    $items = [];
    foreach ($links as $link) {
      try {
        if ($link['url']->access()) {
          $items[] = [
            '#type' => 'link',
            '#title' => $link['title'],
            '#url' => $link['url'],
            '#attributes' => ['class' => ['site-header__organiser-link']],
          ];
        }
      }
      catch (\Exception $e) {
        // Route may not exist.
      }
    }

    if (empty($items)) {
      return [];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['site-header__organiser-tools']],
      'links' => [
        '#theme' => 'item_list',
        '#items' => $items,
        '#attributes' => ['class' => ['site-header__organiser-list']],
      ],
    ];
  }

  /**
   * Builds "Hosted by {Organiser Name}" with logo or initials.
   */
  private function buildHostedBy(object $vendor): array {
    $name = $vendor->label();
    $truncated = mb_strlen($name) > 24 ? mb_substr($name, 0, 21) . '…' : $name;

    $initials = '';
    $words = preg_split('/\s+/', trim($name), 2);
    if (count($words) >= 2) {
      $initials = mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1);
    }
    else {
      $initials = mb_substr($name, 0, 2);
    }
    $initials = mb_strtoupper($initials);

    $logo_url = NULL;
    if ($vendor->hasField('field_vendor_logo') && !$vendor->get('field_vendor_logo')->isEmpty()) {
      $file = $vendor->get('field_vendor_logo')->entity;
      if ($file) {
        $logo_url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      }
    }

    try {
      $url = $vendor->toUrl();
    }
    catch (\Exception $e) {
      $url = Url::fromRoute('<front>');
    }

    $prefix = $logo_url
      ? [
        '#theme' => 'image',
        '#uri' => $logo_url,
        '#alt' => $name,
        '#attributes' => ['class' => ['site-header__organiser-logo'], 'width' => 24, 'height' => 24],
      ]
      : [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => ['class' => ['site-header__organiser-initials']],
        '#value' => $initials,
      ];

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['site-header__organiser-hosted']],
      'wrapper' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['site-header__organiser-hosted-inner']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['class' => ['site-header__organiser-label']],
          '#value' => $this->t('Hosted by') . ' ',
        ],
        'link' => [
          '#type' => 'link',
          '#title' => ['prefix' => $prefix, 'name' => ['#markup' => $truncated]],
          '#url' => $url,
          '#attributes' => [
            'class' => ['site-header__organiser-name'],
            'title' => $name,
          ],
        ],
      ],
    ];
  }

}
