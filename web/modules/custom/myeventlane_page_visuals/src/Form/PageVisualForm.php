<?php

declare(strict_types=1);

namespace Drupal\myeventlane_page_visuals\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\myeventlane_page_visuals\Service\PageVisualMediaManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Form for adding/editing Page Visual config entities.
 */
final class PageVisualForm extends EntityForm {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The route provider.
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  protected RouteProviderInterface $routeProvider;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected EntityRepositoryInterface $entityRepository;

  /**
   * Page visual media and file helper.
   *
   * @var \Drupal\myeventlane_page_visuals\Service\PageVisualMediaManager
   */
  protected PageVisualMediaManager $pageVisualMediaManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->routeProvider = $container->get('router.route_provider');
    $instance->entityRepository = $container->get('entity.repository');
    $instance->pageVisualMediaManager = $container->get('myeventlane.page_visual_media_manager');
    return $instance;
  }

  /**
   * Canonical routes that accept page visuals (curated list).
   */
  private const CURATED_ROUTES = [
    'system.front_page' => 'Homepage (/)',
    'mel_search.view' => 'Search (/search)',
    'view.upcoming_events.page_events' => 'Events listing (/events)',
    'view.upcoming_events.page_category' => 'Events by category (/events/category/*)',
    'view.upcoming_events.page_today' => 'Events today (/events/today)',
    'view.upcoming_events.page_this_weekend' => 'Events this weekend (/events/this-weekend)',
    'view.upcoming_events.page_free' => 'Free events (/events/free)',
    'view.upcoming_events.page_popular' => 'Community Favourites (/events/popular)',
    'view.upcoming_events.page_hidden_gems' => 'Hidden Gems (/events/hidden-gems)',
    'view.events_calendar.page_calendar' => 'Calendar (/calendar)',
    'myeventlane_vendor.organisers' => 'Organisers (/organisers)',
    'myeventlane_vendor.public_list' => 'Vendors (/vendors)',
    'myeventlane_help_centre.home' => 'Help Centre (/help)',
    'myeventlane_escalations_portal.customer_support_tickets' => 'Support tickets (/support/tickets)',
    'entity.taxonomy_term.canonical' => 'Taxonomy term (generic)',
    'default' => 'Global default (fallback)',
  ];

  /**
   * Canonical paths for curated routes (fallback when route not registered).
   */
  private const CURATED_ROUTE_PATHS = [
    'system.front_page' => '/',
    'mel_search.view' => '/search',
    'view.upcoming_events.page_events' => '/events',
    'view.upcoming_events.page_category' => '/events/category/%',
    'view.upcoming_events.page_today' => '/events/today',
    'view.upcoming_events.page_this_weekend' => '/events/this-weekend',
    'view.upcoming_events.page_free' => '/events/free',
    'view.upcoming_events.page_popular' => '/events/popular',
    'view.upcoming_events.page_hidden_gems' => '/events/hidden-gems',
    'view.events_calendar.page_calendar' => '/calendar',
    'myeventlane_vendor.organisers' => '/organisers',
    'myeventlane_vendor.public_list' => '/vendors',
    'myeventlane_help_centre.home' => '/help',
    'myeventlane_help_centre.public_index' => '/help/index',
    'myeventlane_escalations_portal.customer_support_tickets' => '/support/tickets',
    'entity.taxonomy_term.canonical' => '/taxonomy/term/%',
    'default' => '(fallback)',
  ];

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    // Create public://page-visuals before managed_file AJAX upload (validate runs too late).
    $this->pageVisualMediaManager->ensureUploadDirectoryReady();

    $entity = $this->entity;
    $storage = $this->entityTypeManager->getStorage('myeventlane_page_visual');

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#description' => $this->t('Human-readable label (e.g. "Homepage hero", "Search page hero").'),
      '#default_value' => $entity->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#machine_name' => [
        'exists' => [$storage, 'load'],
        'source' => ['label'],
      ],
      '#default_value' => $entity->id(),
      '#required' => TRUE,
      '#disabled' => !$entity->isNew(),
    ];

    $form['route_name'] = [
      '#type' => 'select',
      '#title' => $this->t('Route name'),
      '#description' => $this->t('Drupal route this visual applies to. Choose from known routes or "Custom" to enter a route name manually.'),
      '#default_value' => $this->getRouteDefaultValue($entity->getRouteName()),
      '#required' => TRUE,
      '#options' => ['' => '- Select -'] + self::CURATED_ROUTES + [
        '__custom__' => 'Custom (enter below)',
      ],
    ];

    $form['route_name_custom'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom route name'),
      '#description' => $this->t('Enter a Drupal route name (e.g. view.my_view.page_1).'),
      '#default_value' => in_array($entity->getRouteName(), array_keys(self::CURATED_ROUTES), TRUE)
        ? '' : $entity->getRouteName(),
      '#states' => [
        'visible' => [
          ':input[name="route_name"]' => ['value' => '__custom__'],
        ],
        'required' => [
          ':input[name="route_name"]' => ['value' => '__custom__'],
        ],
      ],
      '#prefix' => '<div id="route-custom-wrapper">',
      '#suffix' => '</div>',
    ];

    $form['route_path_helper'] = [
      '#type' => 'item',
      '#title' => $this->t('Route path'),
      '#markup' => $this->getRoutePathHelper($entity->getRouteName()),
      '#weight' => 5,
      '#access' => $entity->getRouteName() !== '',
    ];

    $form['image_upload_desktop'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Desktop image'),
      '#description' => $this->t('Upload an image for desktop viewports (PNG, JPG, JPEG, GIF, WebP). Required when enabled.'),
      '#upload_location' => PageVisualMediaManager::UPLOAD_DIRECTORY . '/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'png jpg jpeg gif webp'],
      ],
      '#default_value' => $this->getFileDefaultValue($entity->getMediaUuidDesktop()),
      '#required' => TRUE,
    ];

    $form['image_upload_mobile'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Mobile image'),
      '#description' => $this->t('Optional: upload a different image for mobile viewports. If empty, desktop image is used.'),
      '#upload_location' => PageVisualMediaManager::UPLOAD_DIRECTORY . '/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'png jpg jpeg gif webp'],
      ],
      '#default_value' => $this->getFileDefaultValue($entity->getMediaUuidMobile()),
    ];

    $form['hide_on_mobile'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide visual on mobile'),
      '#description' => $this->t('When checked, the hero/visual will not be shown on mobile viewports.'),
      '#default_value' => $entity->isHideOnMobile(),
    ];

    $form['alt_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Alt text'),
      '#description' => $this->t('Descriptive alt text for accessibility. Required.'),
      '#default_value' => $entity->getAltText(),
      '#required' => TRUE,
      '#maxlength' => 512,
    ];

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#description' => $this->t('When enabled, this visual will be used for the specified route.'),
      '#default_value' => $entity->isEnabled(),
    ];

    return $form;
  }

  /**
   * Gets the default value for the route select.
   */
  private function getRouteDefaultValue(string $route_name): string {
    if ($route_name === '') {
      return '';
    }
    return array_key_exists($route_name, self::CURATED_ROUTES) ? $route_name : '__custom__';
  }

  /**
   * Gets helper text showing the route path when route exists.
   *
   * For curated routes, shows canonical path from CURATED_ROUTE_PATHS when
   * the route is not registered (e.g. module disabled).
   */
  private function getRoutePathHelper(string $route_name): string {
    if ($route_name === '') {
      return (string) $this->t('—');
    }
    try {
      $route = $this->routeProvider->getRouteByName($route_name);
      $path = $route->getPath();
      return $path ?: (string) $this->t('(no path)');
    }
    catch (RouteNotFoundException $e) {
      if (isset(self::CURATED_ROUTE_PATHS[$route_name])) {
        return self::CURATED_ROUTE_PATHS[$route_name] . ' '
          . (string) $this->t('(module may be disabled)');
      }
      return (string) $this->t('Route not found.');
    }
  }

  /**
   * Gets the file fid for managed_file default value (from existing Media).
   */
  private function getFileDefaultValue(?string $media_uuid): array {
    if ($media_uuid === NULL || $media_uuid === '') {
      return [];
    }
    $media = $this->entityRepository->loadEntityByUuid('media', $media_uuid);
    if (!$media instanceof MediaInterface || !$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
      return [];
    }
    $file = $media->get('field_media_image')->entity;
    return $file instanceof FileInterface ? [$file->id()] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    if (!$this->pageVisualMediaManager->ensureUploadDirectoryReady()) {
      $form_state->setErrorByName(
        'image_upload_desktop',
        $this->t('Hero image uploads are unavailable because @dir is not writable. Ask an administrator to fix file permissions on the shared files directory.', [
          '@dir' => PageVisualMediaManager::UPLOAD_DIRECTORY,
        ])
      );
    }

    $route_select = $form_state->getValue('route_name');
    $route_name = $route_select === '__custom__'
      ? trim((string) $form_state->getValue('route_name_custom'))
      : $route_select;

    if ($route_name === '') {
      $form_state->setErrorByName('route_name', $this->t('Route name is required.'));
      return;
    }

    // Only validate custom route names; curated routes are known to exist.
    if ($route_select === '__custom__') {
      try {
        $this->routeProvider->getRouteByName($route_name);
      }
      catch (RouteNotFoundException $e) {
        $form_state->setErrorByName(
          'route_name_custom',
          $this->t('Route "%route" does not exist.', ['%route' => $route_name])
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $entity = $this->entity;
    $old_desktop_uuid = $entity->getMediaUuidDesktop();
    $old_mobile_uuid = $entity->getMediaUuidMobile();

    $route_select = $form_state->getValue('route_name');
    $route_name = $route_select === '__custom__'
      ? trim((string) $form_state->getValue('route_name_custom'))
      : $route_select;

    $alt_text = trim((string) $form_state->getValue('alt_text'));

    $media_uuid_desktop = NULL;
    $desktop_fid = 0;
    $fids_desktop = $form_state->getValue('image_upload_desktop');
    if (!empty($fids_desktop) && is_array($fids_desktop)) {
      $desktop_fid = (int) reset($fids_desktop);
      if ($desktop_fid > 0) {
        $media_uuid_desktop = $this->pageVisualMediaManager->createOrGetMediaFromFile($desktop_fid, $alt_text);
        if ($media_uuid_desktop === NULL) {
          $form_state->setErrorByName(
            'image_upload_desktop',
            $this->t('Could not save the desktop image. Check file permissions or try uploading again.')
          );
          return 0;
        }
      }
    }

    $media_uuid_mobile = NULL;
    $mobile_fid = 0;
    $fids_mobile = $form_state->getValue('image_upload_mobile');
    if (!empty($fids_mobile) && is_array($fids_mobile)) {
      $mobile_fid = (int) reset($fids_mobile);
      if ($mobile_fid > 0) {
        $media_uuid_mobile = $this->pageVisualMediaManager->createOrGetMediaFromFile($mobile_fid, $alt_text);
        if ($media_uuid_mobile === NULL) {
          $form_state->setErrorByName(
            'image_upload_mobile',
            $this->t('Could not save the mobile image. Check file permissions or try uploading again.')
          );
          return 0;
        }
      }
    }

    $entity->set('route_name', $route_name);
    $entity->set('media_uuid_desktop', $media_uuid_desktop);
    $entity->set('media_uuid_mobile', $media_uuid_mobile);
    $entity->set('hide_on_mobile', (bool) $form_state->getValue('hide_on_mobile'));
    $entity->set('alt_text', $alt_text);
    $entity->set('enabled', (bool) $form_state->getValue('enabled'));

    $result = parent::save($form, $form_state);

    if ($result !== SAVED_NEW && $result !== SAVED_UPDATED) {
      return $result;
    }

    $visual_id = (string) $entity->id();
    $this->pageVisualMediaManager->syncFileUsageForVisual(
      $visual_id,
      $old_desktop_uuid,
      $old_mobile_uuid,
      $desktop_fid,
      $mobile_fid,
    );

    $this->messenger()->addStatus($this->t('Page visual %label has been saved.', [
      '%label' => $entity->label(),
    ]));

    $form_state->setRedirectUrl(Url::fromRoute('entity.myeventlane_page_visual.collection'));

    return $result;
  }

}
