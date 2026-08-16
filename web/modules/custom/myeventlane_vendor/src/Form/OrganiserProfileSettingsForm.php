<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_surface\MelWorkflowManager;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\EventSubscriber\VendorStoreSubscriber;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQuery;
use Drupal\myeventlane_vendor\Service\VendorBrandMediaManager;
use Drupal\myeventlane_vendor\Service\VendorImageFieldPolicy;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Organiser profile form for Workspace Settings · Profile.
 *
 * Data is stored on the organiser account entity (synced where needed).
 */
class OrganiserProfileSettingsForm extends FormBase {

  private const FORM_DEBUG_SECTION_KEYS = [
    'profile',
    'visual_assets',
    'contact',
    'public_page',
    'store',
    'team',
    'preferences',
  ];

  private const FORM_DEBUG_MAX_ARRAY_ITEMS = 12;

  private const FORM_DEBUG_MAX_DEPTH = 4;

  private const FORM_DEBUG_MAX_STRING_LENGTH = 160;

  private const FORM_DEBUG_MAX_TOP_LEVEL_KEYS = 40;

  protected EntityTypeManagerInterface $entityTypeManager;

  protected AccountProxyInterface $currentUser;

  protected ?OnboardingManager $onboardingManager;

  protected CurrentVendorResolverInterface $vendorResolver;

  protected VendorStoreSubscriber $vendorStoreSubscriber;

  protected LoggerInterface $logger;

  protected CacheTagsInvalidatorInterface $cacheTagsInvalidator;

  protected UserVendorMembershipQuery $userVendorMembershipQuery;

  protected AccessManagerInterface $accessManager;

  protected RouteProviderInterface $routeProvider;

  /**
   * Captures retained direct files as reusable organiser Media.
   */
  protected VendorBrandMediaManager $brandMediaManager;

  /**
   * Dedupes onboarding panel when MELWorkflowSystem already renders primary region.
   */
  protected ?MelWorkflowManager $melWorkflowManager = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    /** @var static $instance */
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUser = $container->get('current_user');
    $instance->onboardingManager = $container->has('myeventlane_onboarding.manager')
      ? $container->get('myeventlane_onboarding.manager')
      : NULL;
    $instance->vendorResolver = $container->get('myeventlane_vendor.current_vendor_resolver');
    $instance->vendorStoreSubscriber = $container->get('myeventlane_vendor.vendor_store_subscriber');
    $instance->logger = $container->get('logger.channel.myeventlane_vendor');
    $instance->cacheTagsInvalidator = $container->get('cache_tags.invalidator');
    $instance->userVendorMembershipQuery = $container->get('myeventlane_vendor.user_vendor_membership_query');
    $instance->accessManager = $container->get('access_manager');
    $instance->routeProvider = $container->get('router.route_provider');
    $instance->brandMediaManager = $container->get('myeventlane_vendor.brand_media_manager');
    $instance->melWorkflowManager = $container->has('myeventlane_surface.workflow_manager')
      ? $container->get('myeventlane_surface.workflow_manager')
      : NULL;
    // Satisfies FormBase::$routeMatch so getRouteMatch() does not call \Drupal::routeMatch().
    $instance->routeMatch = $container->get('current_route_match');
    $instance->setRequestStack($container->get('request_stack'));
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mel_organiser_settings_form';
  }

  /**
   * Gets the current vendor using CurrentVendorResolver.
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor|null
   *   The vendor entity, or NULL if not found.
   */
  protected function getCurrentVendor(): ?Vendor {
    $resolver = $this->vendorResolver;
    $vendor = $resolver->resolveFromCurrentUser();
    if ($vendor instanceof Vendor) {
      return $vendor;
    }

    // Fallback to legacy resolution if resolver returns nothing.
    $uid = (int) $this->currentUser->id();
    if ($uid === 0) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $owner_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute();

    if (!empty($owner_ids)) {
      $vendor = $storage->load(reset($owner_ids));
      if ($vendor instanceof Vendor) {
        return $vendor;
      }
    }

    return NULL;
  }

  /**
   * Gets a field value from vendor safely.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The vendor entity.
   * @param string $field_name
   *   The field name.
   * @param mixed $default
   *   Default value if field is empty.
   *
   * @return mixed
   *   The field value or default.
   */
  protected function getFieldValue(Vendor $vendor, string $field_name, mixed $default = NULL): mixed {
    if (!$vendor->hasField($field_name)) {
      return $default;
    }
    $field = $vendor->get($field_name);
    if ($field->isEmpty()) {
      return $default;
    }
    return $field->value ?? $default;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?Vendor $vendor = NULL): array {
    $form['#tree'] = TRUE;
    $form['#cache'] = ['max-age' => 0];
    $form_state->setCached(FALSE);
    $form_state->setAlwaysProcess(TRUE);

    $request = $this->getRequest();

    $this->logBoundedFormDebugSummary('RAW INPUT', $form_state->getUserInput());
    $this->logBoundedFormDebugSummary('REQUEST POST', $request->request->all());

    $this->logger->debug('FORM CHECK: form_id=@fid build_id=@bid token=@tok', [
      '@fid' => (string) ($request->request->get('form_id') ?? ''),
      '@bid' => (string) ($request->request->get('form_build_id') ?? ''),
      '@tok' => (string) ($request->request->get('form_token') ?? ''),
    ]);

    $this->logger->debug('FORM STATE: isSubmitted=@submitted', [
      '@submitted' => $form_state->isSubmitted() ? 'TRUE' : 'FALSE',
    ]);

    $this->logger->debug('VendorSettingsForm BUILD uid=@uid route=@route', [
      '@uid' => (string) $this->currentUser->id(),
      '@route' => $this->getRouteMatch()->getRouteName() ?? '',
    ]);

    // Try to get vendor from form state first (for rebuilds).
    if (!$vendor) {
      $vendor = $form_state->get('vendor');
      // If vendor is in form state, reload it fresh to avoid stale data.
      if ($vendor && $vendor->id()) {
        $this->entityTypeManager->getStorage('myeventlane_vendor')->resetCache([$vendor->id()]);
        $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load($vendor->id());
      }
    }

    // If still no vendor, try to load by ID from form state or form values.
    if (!$vendor) {
      $vendor_id = $form_state->get('vendor_id');
      if (!$vendor_id && $form_state->hasValue('vendor_id')) {
        $vendor_id = $form_state->getValue('vendor_id');
      }
      if ($vendor_id) {
        $this->entityTypeManager->getStorage('myeventlane_vendor')->resetCache([$vendor_id]);
        $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load($vendor_id);
      }
    }

    // If still no vendor, try to get from current user via resolver.
    if (!$vendor) {
      $vendor = $this->getCurrentVendor();
      if ($vendor && $vendor->id()) {
        $this->entityTypeManager->getStorage('myeventlane_vendor')->resetCache([$vendor->id()]);
        $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load($vendor->id());
      }
    }

    if (!$vendor) {
      $form['error'] = [
        '#markup' => '<p>' . $this->t('Organiser account not found. Please contact support.') . '</p>',
      ];
      $form['#cache']['max-age'] = 0;
      return $form;
    }

    // Store vendor in form state for use in submit.
    $form_state->set('vendor', $vendor);
    $form_state->set('vendor_id', $vendor->id());

    // Use hidden (not value): Value elements are not sent in POST; hidden ensures vendor_id
    // round-trips even if form cache/storage loses internal state on submit.
    $form['vendor_id'] = [
      '#type' => 'hidden',
      '#value' => $vendor->id(),
      '#weight' => -1000,
    ];

    $quick_links = [];
    if ($this->routeExists('myeventlane_vendor.console.messages')
      && $this->accessManager->checkNamedRoute('myeventlane_vendor.console.messages', [], $this->currentUser, TRUE)->isAllowed()) {
      $quick_links[] = [
        'title' => $this->t('Messages'),
        'url' => Url::fromRoute('myeventlane_vendor.console.messages'),
      ];
    }
    elseif ($this->routeExists('myeventlane_vendor.console.messaging_brand')
      && $this->accessManager->checkNamedRoute('myeventlane_vendor.console.messaging_brand', [], $this->currentUser, TRUE)->isAllowed()) {
      $quick_links[] = [
        'title' => $this->t('Messages brand'),
        'url' => Url::fromRoute('myeventlane_vendor.console.messaging_brand'),
      ];
    }
    if ($this->routeExists('myeventlane_pro.branding')
      && $this->accessManager->checkNamedRoute('myeventlane_pro.branding', [], $this->currentUser, TRUE)->isAllowed()) {
      $quick_links[] = [
        'title' => $this->t('Pro branding'),
        'url' => Url::fromRoute('myeventlane_pro.branding'),
      ];
    }

    $form['page_header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-vendor-settings-v2__header', 'mel-settings-header']],
      '#weight' => -1002,
    ];
    $form['page_header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('Profile'),
      '#attributes' => ['class' => ['mel-vendor-settings-v2__header-title', 'mel-settings-header__title']],
    ];
    $form['page_header']['lede'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Manage what visitors see and keep your organiser details up to date.'),
      '#attributes' => ['class' => ['mel-vendor-settings-v2__header-lede', 'mel-settings-header__lede']],
    ];
    $form['page_header']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('← Account settings'),
      '#url' => Url::fromRoute('myeventlane_vendor.console.settings'),
      '#attributes' => ['class' => ['mel-vendor-settings-v2__header-link']],
      '#weight' => -10,
    ];
    if ($quick_links !== []) {
      $form['page_header']['quick'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-vendor-settings-v2__actions']],
      ];
      foreach ($quick_links as $i => $item) {
        $form['page_header']['quick'][$i] = [
          '#type' => 'link',
          '#title' => $item['title'],
          '#url' => $item['url'],
          '#attributes' => ['class' => ['mel-vendor-settings-v2__header-link']],
        ];
      }
    }

    // Preview link to public profile.
    // Build the public profile URL - uses the public domain (not vendor subdomain).
    $public_url = Url::fromRoute('entity.myeventlane_vendor.canonical', [
      'myeventlane_vendor' => $vendor->id(),
    ], ['absolute' => TRUE]);

    // Ensure the URL uses the public domain (not vendor subdomain).
    $public_url_string = $public_url->toString();
    // Replace vendor subdomain with main domain if present.
    $public_url_string = preg_replace('#^https?://vendor\.#', 'https://', $public_url_string);

    $profile_is_published = $vendor->hasField('field_public_profile_published')
      && (bool) $this->getFieldValue($vendor, 'field_public_profile_published', FALSE);
    $status_label = $profile_is_published ? $this->t('Published') : $this->t('Private');
    $status_class = $profile_is_published
      ? 'mel-vendor-settings-v2__pill--success'
      : 'mel-vendor-settings-v2__pill--warning';
    $status_message = $profile_is_published
      ? $this->t('Your organiser profile can appear in the public directory. Review the visibility choices below to control which optional details visitors can see.')
      : $this->t('Only you and authorised MyEventLane staff can view these organiser details. Publish the profile below when you are ready.');

    $profile_checks = [
      trim((string) $vendor->getName()) !== '',
      trim((string) $this->getFieldValue($vendor, 'field_summary', '')) !== '',
      trim((string) $this->getFieldValue($vendor, 'field_description', '')) !== '',
      trim((string) $this->getFieldValue($vendor, 'field_website', '')) !== '',
      !$vendor->hasField('field_email')
      || trim((string) $this->getFieldValue($vendor, 'field_email', '')) !== '',
    ];
    $profile_complete = count(array_filter($profile_checks));
    $profile_total = count($profile_checks);
    $profile_percentage = (int) round(($profile_complete / $profile_total) * 100);

    $form['preview_link'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['vendor-preview-link-wrapper', 'mel-vendor-settings-v2__status-card']],
      '#weight' => -998,
    ];
    $overview_markup = sprintf(
      '<div class="mel-vendor-settings-v2__status-copy">'
      . '<span class="mel-vendor-settings-v2__eyebrow">%s</span>'
      . '<h2>%s</h2><div class="mel-vendor-settings-v2__status-row">'
      . '<span class="mel-vendor-settings-v2__pill %s">%s</span>'
      . '<span class="mel-vendor-settings-v2__completeness">%s</span></div>'
      . '<div class="mel-vendor-settings-v2__progress" role="progressbar" '
      . 'aria-label="%s" aria-valuemin="0" aria-valuemax="100" aria-valuenow="%d">'
      . '<span style="width:%d%%"></span></div><p>%s</p></div>',
      Html::escape((string) $this->t('Organiser profile')),
      Html::escape($vendor->getName()),
      $status_class,
      Html::escape((string) $status_label),
      Html::escape((string) $this->t('@percentage% complete', ['@percentage' => $profile_percentage])),
      Html::escape((string) $this->t('Profile completeness')),
      $profile_percentage,
      $profile_percentage,
      Html::escape((string) $status_message),
    );
    $form['preview_link']['status'] = [
      '#markup' => $overview_markup,
      '#weight' => -10,
    ];

    $form['preview_link']['link'] = [
      '#type' => 'link',
      '#title' => $this->t('Preview profile'),
      '#url' => Url::fromUri($public_url_string),
      '#attributes' => [
        'class' => ['button', 'button--secondary', 'vendor-preview-link', 'mel-btn', 'mel-btn--secondary', 'mel-vendor-settings-v2__preview-link'],
        'target' => '_blank',
        'rel' => 'noopener noreferrer',
      ],
      '#prefix' => '<div class="vendor-preview-banner mel-vendor-settings-v2__preview">',
      '#suffix' => '</div>',
    ];

    // Onboarding panel at top when not invite-ready (non-blocking).
    $form['onboarding_panel'] = [
      '#weight' => -999,
    ];
    if ($vendor->id()) {
      try {
        $user = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
        $onboardingManager = $this->onboardingManager;
        if ($user instanceof \Drupal\user\UserInterface && $onboardingManager) {
          $state = $onboardingManager->loadOrCreateVendor($user, $vendor);
          $onboardingManager->refreshFlags($state);
          $show_panel = !$onboardingManager->isCompleted($state)
            && !$onboardingManager->isInviteReady($state);
          $governed_primary = $this->melWorkflowManager instanceof MelWorkflowManager
            && $this->melWorkflowManager->willRenderPrimaryWorkflowRegion();
          if ($show_panel && !$governed_primary) {
            $stage = $state->getStage();
            $stage_labels = [
              'probe' => $this->t('Get started'),
              'present' => $this->t('Profile'),
              'listen' => $this->t('Payments'),
              'ask' => $this->t('First event'),
              'invite' => $this->t('Boost'),
              'complete' => $this->t('Complete'),
            ];
            $next = $onboardingManager->getNextActionForAuthenticatedVendor($state);
            $form['onboarding_panel'] = [
              '#weight' => -999,
              '#theme' => 'myeventlane_vendor_onboarding_panel',
              '#stage_label' => $stage_labels[$stage] ?? $stage,
              '#flags' => $state->getFlags(),
              '#next_action' => $next,
              '#vendor' => $vendor,
            ];
          }
        }
      }
      catch (\Throwable $e) {
        $this->logger->warning('Onboarding panel failed on settings form: @m', ['@m' => $e->getMessage()]);
      }
    }

    // Fallback when action still resolves to /vendor/form_action_* (pre_render strips /vendor/).
    $form['#pre_render'][] = [FormActionUrlFixer::class, 'fixFormActionUrl'];

    $form['#attributes']['class'][] = 'mel-form';
    $form['#attributes']['class'][] = 'mel-form--vendor-settings';
    $form['#attributes']['class'][] = 'vendor-settings-form';
    $form['#attributes']['class'][] = 'mel-settings-form';
    $form['#attributes']['class'][] = 'mel-protected-form';
    $form['#attributes']['class'][] = 'mel-vendor-settings-v2';
    $form['#attributes']['id'] = 'vendor-settings-form';
    $form['#attributes']['novalidate'] = 'novalidate';
    $form['#method'] = 'post';
    $form['#action'] = $this->getRequest()->getRequestUri();

    $form['#attached']['library'][] = 'myeventlane_vendor_theme/global-styling';
    $form['#attached']['library'][] = 'myeventlane_vendor/organiser_profile_settings';

    $this->buildPublicSettingsSection($form, $form_state, $vendor);
    $this->buildProfileSection($form, $form_state, $vendor);
    $this->buildVisualAssetsSection($form, $form_state, $vendor);
    $this->buildContactSection($form, $form_state, $vendor);
    $this->buildVenuesSection($form, $form_state, $vendor);
    $this->buildCommerceSection($form, $form_state, $vendor);
    $this->buildTeamSection($form, $form_state, $vendor);
    $this->buildPreferencesSection($form, $form_state, $vendor);

    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
      '#attributes' => ['class' => ['mel-form__actions', 'mel-organiser-profile-hub__save']],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save changes'),
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
    ];

    // Post-build #attached log (identifies AJAX library injectors; empty at buildForm() entry).
    $this->logger->debug('MEL FORM ATTACHED: <pre>@data</pre>', [
      '@data' => print_r($form['#attached'] ?? [], TRUE),
    ]);

    // Remove AJAX-related libraries ONLY.
    if (!empty($form['#attached']['library'])) {
      $form['#attached']['library'] = array_values(array_filter(
        $form['#attached']['library'],
        function ($lib) {
          return !in_array($lib, [
            'core/drupal.ajax',
            'core/drupal.dialog.ajax',
            'core/drupal.progress',
          ], TRUE);
        }
      ));
    }

    if (isset($form['#attached']['drupalSettings']['ajax'])) {
      unset($form['#attached']['drupalSettings']['ajax']);
    }

    $form['#attributes']['data-drupal-ajax'] = 'false';
    $form['#attributes']['class'][] = 'mel-no-ajax';

    return $form;
  }

  /**
   * Builds the organiser profile fields.
   */
  private function buildProfileSection(array &$form, FormStateInterface $form_state, Vendor $vendor): void {
    $form['profile'] = [
      '#type' => 'details',
      '#title' => $this->t('Profile content'),
      '#open' => FALSE,
      '#attributes' => [
        'class' => ['mel-card', 'mel-vendor-settings__card', 'mel-vendor-settings-v2__section'],
        'data-mel-organiser-card' => 'profile',
      ],
    ];
    $form['profile']['_intro'] = [
      '#markup' => '<p class="mel-vendor-settings-v2__section-lede">' . $this->t('Add the name, story and links used on your organiser page. Visibility is controlled separately above.') . '</p>',
      '#weight' => -10,
    ];
    $form['profile']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Display name'),
      '#default_value' => $vendor->getName(),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#description' => $this->t('The public name of your organisation or business.'),
      '#attributes' => ['class' => ['mel-vendor-settings-v2__field']],
    ];

    if ($vendor->hasField('field_summary')) {
      $form['profile']['summary'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Short Summary'),
        '#default_value' => $this->getFieldValue($vendor, 'field_summary', ''),
        '#rows' => 3,
        '#description' => $this->t('A brief one-line summary that appears in listings and search results.'),
        '#attributes' => ['class' => ['mel-vendor-settings-v2__field']],
      ];
    }

    if ($vendor->hasField('field_tagline')) {
      $form['profile']['tagline'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Tagline'),
        '#default_value' => $this->getFieldValue($vendor, 'field_tagline', ''),
        '#maxlength' => 255,
        '#description' => $this->t('Optional short tagline alongside your name.'),
        '#attributes' => ['class' => ['mel-vendor-settings-v2__field']],
      ];
    }

    if ($vendor->hasField('field_description')) {
      $desc_value = '';
      $desc_format = 'basic_html';
      if (!$vendor->get('field_description')->isEmpty()) {
        $desc_value = $vendor->get('field_description')->value ?? '';
        $desc_format = $vendor->get('field_description')->format ?? 'basic_html';
      }
      $form['profile']['description'] = [
        '#type' => 'text_format',
        '#title' => $this->t('Description'),
        '#default_value' => $desc_value,
        '#format' => $desc_format,
        '#rows' => 10,
        '#description' => $this->t('Full description of your organisation. This appears on your public organiser page.'),
      ];
    }

    if ($vendor->hasField('field_vendor_bio')) {
      $bio_value = '';
      $bio_format = 'basic_html';
      if (!$vendor->get('field_vendor_bio')->isEmpty()) {
        $bio_value = $vendor->get('field_vendor_bio')->value ?? '';
        $bio_format = $vendor->get('field_vendor_bio')->format ?? 'basic_html';
      }
      $form['profile']['bio'] = [
        '#type' => 'text_format',
        '#title' => $this->t('Bio / About'),
        '#default_value' => $bio_value,
        '#format' => $bio_format,
        '#rows' => 8,
        '#description' => $this->t('Extended biography or about section for your public organiser page.'),
        '#attributes' => ['class' => ['mel-vendor-settings-v2__field']],
      ];
    }

    if ($vendor->hasField('field_website')) {
      $website_uri = '';
      if (!$vendor->get('field_website')->isEmpty()) {
        $website_uri = $vendor->get('field_website')->uri ?? '';
      }
      $form['profile']['website'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Website'),
        '#default_value' => $website_uri,
        '#maxlength' => 2048,
        '#description' => $this->t('Enter the full URL, including https://'),
        '#attributes' => [
          'class' => ['mel-vendor-settings-v2__field'],
          'placeholder' => 'https://example.com',
          'inputmode' => 'url',
          'autocomplete' => 'url',
        ],
      ];
    }

    if ($vendor->hasField('field_social_links')) {
      $form['profile']['social_links'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Social media links'),
        '#attributes' => ['class' => ['mel-vendor-settings-v2__grid']],
      ];

      $social_values = $form_state->get('social_links_values');
      if ($social_values === NULL) {
        $social_values = [];
        if (!$vendor->get('field_social_links')->isEmpty()) {
          foreach ($vendor->get('field_social_links') as $item) {
            $social_values[] = [
              'uri' => $item->uri ?? '',
              'title' => $item->title ?? '',
            ];
          }
        }
        if ($social_values === []) {
          $social_values[] = ['uri' => '', 'title' => ''];
        }
        $form_state->set('social_links_values', $social_values);
      }

      $form['profile']['social_links']['links'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Platform'),
          $this->t('URL'),
          $this->t('Operations'),
        ],
      ];

      foreach ($social_values as $delta => $value) {
        $form['profile']['social_links']['links'][$delta]['platform'] = [
          '#type' => 'textfield',
          '#default_value' => $value['title'] ?? '',
          '#placeholder' => $this->t('e.g., Facebook, Instagram'),
          '#size' => 20,
        ];
        $form['profile']['social_links']['links'][$delta]['uri'] = [
          '#type' => 'textfield',
          '#default_value' => $value['uri'] ?? '',
          '#maxlength' => 2048,
          '#size' => 40,
          '#attributes' => [
            'placeholder' => $this->t('https://…'),
            'inputmode' => 'url',
          ],
        ];
        $form['profile']['social_links']['links'][$delta]['remove'] = [
          '#type' => 'submit',
          '#value' => $this->t('Remove'),
          '#name' => 'remove_social_' . $delta,
          '#submit' => ['::removeSocialLink'],
          '#limit_validation_errors' => [
            ['profile', 'social_links'],
          ],
        ];
      }

      $form['profile']['social_links']['add'] = [
        '#type' => 'submit',
        '#value' => $this->t('Add social link'),
        '#name' => 'add_social_link',
        '#submit' => ['::addSocialLink'],
        '#limit_validation_errors' => [
          ['profile', 'social_links'],
        ],
      ];
    }

  }

  /**
   * Builds image and visual branding fields.
   */
  private function buildVisualAssetsSection(array &$form, FormStateInterface $form_state, Vendor $vendor): void {
    $form['visual_assets'] = [
      '#type' => 'details',
      '#title' => $this->t('Logo, banner & brand colour'),
      '#open' => FALSE,
      '#attributes' => [
        'class' => ['mel-card', 'mel-vendor-settings__card', 'mel-vendor-settings-v2__section'],
        'id' => 'visual-assets',
        'data-mel-organiser-card' => 'visual',
      ],
    ];
    $form['visual_assets']['_intro'] = [
      '#markup' => '<p class="mel-vendor-settings-v2__section-lede">' . $this->t('Logo, banner, and accent colour are shared with emails and messaging previews.') . '</p>',
      '#weight' => -10,
    ];

    $logo_field = VendorImageFieldPolicy::canonicalPublicLogoField($vendor);

    if ($logo_field !== '') {
      $logo_file = $this->brandMediaManager
        ->fileForAsset($vendor, VendorBrandMediaManager::ASSET_PUBLIC_LOGO);
      $logo_default = $logo_file !== NULL ? [(int) $logo_file->id()] : [];
      $form['visual_assets']['logo'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Logo'),
        '#default_value' => $logo_default,
        '#upload_location' => 'public://vendor-assets/',
        '#upload_validators' => [
          'FileExtension' => ['extensions' => 'png jpg jpeg gif webp'],
          'FileSizeLimit' => ['fileLimit' => 5 * 1024 * 1024],
        ],
        '#description' => $this->t('Your organisation logo. Recommended size: 400×400px. Square format works best.'),
      ];
      $form['visual_assets']['logo_field_name'] = [
        '#type' => 'value',
        '#value' => $logo_field,
      ];
    }

    if ($vendor->hasField('field_banner_image')) {
      $banner_file = $this->brandMediaManager
        ->fileForAsset($vendor, VendorBrandMediaManager::ASSET_BANNER);
      $banner_default = $banner_file !== NULL ? [(int) $banner_file->id()] : [];
      $form['visual_assets']['banner'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Banner image'),
        '#default_value' => $banner_default,
        '#upload_location' => 'public://vendor-assets/',
        '#upload_validators' => [
          'FileExtension' => ['extensions' => 'png jpg jpeg gif webp'],
          'FileSizeLimit' => ['fileLimit' => 10 * 1024 * 1024],
        ],
        '#description' => $this->t('Banner image for your organiser page. Recommended size: 1920×400px.'),
      ];
    }

    if ($vendor->hasField('field_accent_colour') || $vendor->hasField('field_msg_accent_color')) {
      $accent_default = $this->getVendorAccentColorDefault($vendor);
      $form['visual_assets']['accent_colour'] = [
        '#type' => 'color',
        '#title' => $this->t('Accent colour'),
        '#default_value' => $accent_default,
        '#description' => $this->t('Used for highlights in customer messaging and branded templates.'),
        '#attributes' => ['class' => ['mel-vendor-settings-v2__colour']],
      ];
    }

  }

  /**
   * Builds contact details and messaging sender identity fields.
   */
  private function buildContactSection(array &$form, FormStateInterface $form_state, Vendor $vendor): void {
    $form['contact'] = [
      '#type' => 'details',
      '#title' => $this->t('Contact & email identity'),
      '#open' => FALSE,
      '#attributes' => [
        'class' => ['mel-card', 'mel-vendor-settings__card', 'mel-vendor-settings-v2__section'],
        'data-mel-organiser-card' => 'contact',
      ],
    ];
    $form['contact']['_intro'] = [
      '#markup' => '<p class="mel-vendor-settings-v2__section-lede">' . $this->t('How attendees reach you and how outgoing emails are addressed.') . '</p>',
      '#weight' => -10,
    ];

    if ($vendor->hasField('field_email')) {
      $form['contact']['email'] = [
        '#type' => 'email',
        '#title' => $this->t('Contact email'),
        '#default_value' => $this->getFieldValue($vendor, 'field_email', ''),
        '#description' => $this->t('Kept private unless you choose “Show email on public page” under Profile privacy & visibility.'),
        '#attributes' => ['class' => ['mel-vendor-settings-v2__field']],
      ];
    }

    if ($vendor->hasField('field_phone')) {
      $form['contact']['phone'] = [
        '#type' => 'tel',
        '#title' => $this->t('Phone number'),
        '#default_value' => $this->getFieldValue($vendor, 'field_phone', ''),
        '#description' => $this->t('Kept private unless you choose to show it publicly.'),
        '#attributes' => ['class' => ['mel-vendor-settings-v2__field']],
      ];
    }

    if ($vendor->hasField('field_address')) {
      $form['contact']['address'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Address'),
        '#default_value' => $this->getFieldValue($vendor, 'field_address', ''),
        '#description' => $this->t('Kept private unless you choose to show your address or location publicly.'),
        '#attributes' => ['class' => ['mel-vendor-settings-v2__field']],
      ];
    }

    $messaging_identity = $vendor->hasField('field_msg_from_name')
      || $vendor->hasField('field_msg_from_email')
      || $vendor->hasField('field_msg_reply_to')
      || $vendor->hasField('field_msg_footer');
    if ($messaging_identity) {
      $form['contact']['messaging_identity'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Email sender identity'),
        '#description' => $this->t('Shown on transactional emails where your organiser brand is applied.'),
        '#attributes' => ['class' => ['mel-vendor-settings-v2__grid']],
      ];
    }

    if ($vendor->hasField('field_msg_from_name')) {
      $form['contact']['messaging_identity']['msg_from_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('From name'),
        '#default_value' => $this->getFieldValue($vendor, 'field_msg_from_name', ''),
        '#maxlength' => 255,
        '#description' => $this->t('Display name shown as the email sender.'),
      ];
    }

    if ($vendor->hasField('field_msg_from_email')) {
      $form['contact']['messaging_identity']['msg_from_email'] = [
        '#type' => 'email',
        '#title' => $this->t('From email'),
        '#default_value' => $this->getFieldValue($vendor, 'field_msg_from_email', ''),
        '#description' => $this->t('Must be an address you can receive mail at.'),
      ];
    }

    if ($vendor->hasField('field_msg_reply_to')) {
      $form['contact']['messaging_identity']['msg_reply_to'] = [
        '#type' => 'email',
        '#title' => $this->t('Reply-to email'),
        '#default_value' => $this->getFieldValue($vendor, 'field_msg_reply_to', ''),
        '#description' => $this->t('Optional. Replies go here when different from the from address.'),
      ];
    }

    if ($vendor->hasField('field_msg_footer')) {
      $footer_val = '';
      $footer_fmt = 'basic_html';
      if (!$vendor->get('field_msg_footer')->isEmpty()) {
        $footer_val = $vendor->get('field_msg_footer')->value ?? '';
        $footer_fmt = $vendor->get('field_msg_footer')->format ?? 'basic_html';
      }
      $form['contact']['messaging_identity']['msg_footer'] = [
        '#type' => 'text_format',
        '#title' => $this->t('Email footer text'),
        '#default_value' => $footer_val,
        '#format' => $footer_fmt,
        '#rows' => 4,
        '#description' => $this->t('Optional footer appended to outgoing emails.'),
      ];
    }

  }

  /**
   * Builds public profile visibility controls.
   */
  private function buildPublicSettingsSection(array &$form, FormStateInterface $form_state, Vendor $vendor): void {
    $form['public_page'] = [
      '#type' => 'details',
      '#title' => $this->t('Profile privacy & visibility'),
      '#open' => FALSE,
      '#attributes' => [
        'class' => [
          'mel-card',
          'mel-vendor-settings__card',
          'mel-vendor-settings-v2__section',
          'mel-vendor-settings-v2__section--privacy',
        ],
        'data-mel-organiser-card' => 'public',
      ],
    ];
    $form['public_page']['_intro'] = [
      '#markup' => '<p class="mel-vendor-settings-v2__section-lede">' . $this->t('Your profile stays private until you publish it. You remain in control and can change these choices at any time.') . '</p>',
      '#weight' => -9,
    ];

    if ($vendor->hasField('field_public_profile_published')) {
      $form['public_page']['published'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Publish my organiser profile'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_profile_published', FALSE),
        '#description' => $this->t('When enabled, your organiser name and profile can appear in the public organiser directory. Turn this off at any time to remove it from public view.'),
        '#attributes' => ['class' => ['mel-vendor-settings-v2__publish-toggle']],
      ];
    }

    $form['public_page']['optional_details'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Optional public details'),
      '#attributes' => ['class' => ['mel-vendor-settings-v2__visibility-options']],
    ];
    $form['public_page']['optional_details']['_help'] = [
      '#markup' => '<p class="mel-vendor-settings-v2__section-lede">' . $this->t('These choices only take effect while your organiser profile is published. Leave sensitive details off unless visitors need them.') . '</p>',
      '#weight' => -10,
    ];

    if ($vendor->hasField('field_public_show_email')) {
      $form['public_page']['optional_details']['show_email'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show email on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_email', FALSE),
      ];
    }

    if ($vendor->hasField('field_public_show_phone')) {
      $form['public_page']['optional_details']['show_phone'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show phone on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_phone', FALSE),
      ];
    }

    if ($vendor->hasField('field_public_show_location')) {
      $form['public_page']['optional_details']['show_location'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show address/location on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_location', FALSE),
      ];
    }

    if ($vendor->hasField('field_website') && $vendor->hasField('field_public_show_website')) {
      $form['public_page']['optional_details']['show_website'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show website on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_website', FALSE),
        '#description' => $this->t('Display your website URL on your public organiser profile.'),
      ];
    }

    if ($vendor->hasField('field_social_links') && $vendor->hasField('field_public_show_social_links')) {
      $form['public_page']['optional_details']['show_social_links'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show social media links on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_social_links', FALSE),
        '#description' => $this->t('Display your social media links on your public organiser profile.'),
      ];
    }

    if ($vendor->hasField('field_summary') && $vendor->hasField('field_public_show_summary')) {
      $form['public_page']['optional_details']['show_summary'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show summary on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_summary', FALSE),
        '#description' => $this->t('Display your short summary on your public organiser profile.'),
      ];
    }

    if ($vendor->hasField('field_description') && $vendor->hasField('field_public_show_description')) {
      $form['public_page']['optional_details']['show_description'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show description on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_description', FALSE),
        '#description' => $this->t('Display your full description on your public organiser profile.'),
      ];
    }

    if ($vendor->hasField('field_banner_image') && $vendor->hasField('field_public_show_banner')) {
      $form['public_page']['optional_details']['show_banner'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show banner image on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_banner', FALSE),
        '#description' => $this->t('Display your banner image on your public organiser page.'),
      ];
    }

  }

  /**
   * Links to the saved venues library (no duplicate placeholder UI).
   */
  private function buildVenuesSection(array &$form, FormStateInterface $form_state, Vendor $vendor): void {
    $form['venues'] = [
      '#type' => 'details',
      '#title' => $this->t('Venues'),
      '#attributes' => [
        'class' => ['mel-card', 'mel-vendor-settings__card', 'mel-vendor-settings-v2__section'],
        'id' => 'venues',
        'data-mel-organiser-card' => 'venues',
      ],
    ];
    $form['venues']['_intro'] = [
      '#markup' => '<p class="mel-vendor-settings__card-description">' . $this->t('Save venues once and reuse them when you create events.') . '</p>',
      '#weight' => -9,
    ];
    if ($this->routeExists('myeventlane_venue.vendor_venues')) {
      $form['venues']['manage'] = [
        '#type' => 'link',
        '#title' => $this->t('Manage venues'),
        '#url' => Url::fromRoute('myeventlane_venue.vendor_venues'),
        '#attributes' => [
          'class' => ['button', 'button--secondary', 'mel-btn', 'mel-btn--secondary'],
        ],
      ];
    }
    else {
      $form['venues']['venue_list'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Venue management is not available on this site yet.') . '</p>',
      ];
    }
  }

  /**
   * Business details with a deep link to Payments (no parallel Stripe desk).
   */
  private function buildCommerceSection(array &$form, FormStateInterface $form_state, Vendor $vendor): void {
    $form['store'] = [
      '#type' => 'details',
      '#title' => $this->t('Business details'),
      '#open' => FALSE,
      '#attributes' => [
        'class' => ['mel-card', 'mel-vendor-settings__card', 'mel-vendor-settings-v2__section'],
        'id' => 'business',
        'data-mel-organiser-card' => 'business',
      ],
    ];
    $form['store']['_intro'] = [
      '#markup' => '<p class="mel-vendor-settings-v2__section-lede">' . $this->t('Legal details for invoices. For Stripe connection, payouts, and refunds, open Payments.') . '</p>',
      '#weight' => -10,
    ];
    $form['store']['business'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Legal entity'),
      '#description' => $this->t('Used on invoices and tax documents.'),
      '#attributes' => ['class' => ['mel-vendor-settings-v2__grid']],
    ];

    if ($vendor->hasField('field_business_name')) {
      $form['store']['business']['business_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Legal business name'),
        '#default_value' => $this->getFieldValue($vendor, 'field_business_name', ''),
        '#description' => $this->t('Your registered business name (if different from display name).'),
        '#maxlength' => 255,
      ];
    }

    if ($vendor->hasField('field_abn')) {
      $form['store']['business']['abn'] = [
        '#type' => 'textfield',
        '#title' => $this->t('ABN'),
        '#default_value' => $this->getFieldValue($vendor, 'field_abn', ''),
        '#description' => $this->t('Australian Business Number (e.g., 12 345 678 901).'),
        '#maxlength' => 14,
        '#pattern' => '[0-9 ]{11,14}',
      ];
    }

    $form['store']['payments_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Open Payments'),
      '#url' => Url::fromRoute('myeventlane_vendor.console.payments'),
      '#attributes' => [
        'class' => ['button', 'button--primary', 'mel-btn', 'mel-btn--primary', 'mel-vendor-settings-v2__actions-link'],
      ],
      '#prefix' => '<p class="mel-vendor-settings-v2__status-note">' . $this->t('Stripe connection and payout status live in Payments.') . '</p>',
    ];
  }

  /**
   * Builds team membership controls.
   */
  private function buildTeamSection(array &$form, FormStateInterface $form_state, Vendor $vendor): void {
    $form['team'] = [
      '#type' => 'details',
      '#title' => $this->t('Team members'),
      '#open' => FALSE,
      '#attributes' => [
        'class' => ['mel-card', 'mel-vendor-settings__card', 'mel-vendor-settings-v2__section'],
        'id' => 'mel-vendor-settings-team-ajax',
        'data-mel-organiser-card' => 'team',
      ],
    ];
    $form['team']['_intro'] = [
      '#markup' => '<p class="mel-vendor-settings__card-description">' . $this->t('Manage people who can help run this organiser account.') . '</p>',
      '#weight' => -9,
    ];

    if ($vendor->hasField('field_vendor_users')) {
      $team_members = [];
      if (!$vendor->get('field_vendor_users')->isEmpty()) {
        foreach ($vendor->get('field_vendor_users') as $item) {
          if ($item->target_id) {
            $user = $this->entityTypeManager->getStorage('user')->load($item->target_id);
            if ($user) {
              $team_members[] = $user->getAccountName() . ' (' . $user->getEmail() . ')';
            }
          }
        }
      }

      $form['team']['current_members'] = [
        '#type' => 'markup',
        '#markup' => !empty($team_members)
          ? '<ul><li>' . implode('</li><li>', $team_members) . '</li></ul>'
          : '<p>' . $this->t('No team members added yet.') . '</p>',
      ];

      $form['team']['add_member'] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('Add Team Member'),
        '#target_type' => 'user',
        '#selection_handler' => 'default:user',
        '#selection_settings' => [
          'include_anonymous' => FALSE,
        ],
        '#description' => $this->t('Search for a user by name or email to add them as a team member.'),
      ];

      // TEMP (cache / submission isolation): AJAX disabled — re-add #ajax in STEP 8.
      $form['team']['add_member_submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Add Member'),
        '#submit' => ['::addTeamMember'],
      ];
    }

  }

  /**
   * Builds notification and organiser preference controls.
   */
  private function buildPreferencesSection(array &$form, FormStateInterface $form_state, Vendor $vendor): void {
    $form['preferences'] = [
      '#type' => 'details',
      '#title' => $this->t('Notifications'),
      '#open' => FALSE,
      '#attributes' => [
        'class' => ['mel-card', 'mel-vendor-settings__card', 'mel-vendor-settings-v2__section'],
        'id' => 'notifications',
        'data-mel-organiser-card' => 'notifications',
      ],
    ];
    $form['preferences']['notifications'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Email notifications'),
      '#description' => $this->t('Choose when MyEventLane emails you about bookings. Message delivery to guests lives in Messages.'),
    ];

    $email_on_order_default = TRUE;
    $email_on_rsvp_default = TRUE;
    $email_digest_default = 'daily';

    if ($vendor->hasField('field_pref_email_on_order')) {
      $email_on_order_default = (bool) $this->getFieldValue($vendor, 'field_pref_email_on_order', TRUE);
    }
    if ($vendor->hasField('field_pref_email_on_rsvp')) {
      $email_on_rsvp_default = (bool) $this->getFieldValue($vendor, 'field_pref_email_on_rsvp', TRUE);
    }
    if ($vendor->hasField('field_pref_email_digest')) {
      $email_digest_default = $this->getFieldValue($vendor, 'field_pref_email_digest', 'daily');
    }

    $form['preferences']['notifications']['email_on_new_order'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Email me when someone books tickets'),
      '#default_value' => $email_on_order_default,
    ];

    $form['preferences']['notifications']['email_on_rsvp'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Email me when someone RSVPs'),
      '#default_value' => $email_on_rsvp_default,
    ];

    $form['preferences']['notifications']['email_digest'] = [
      '#type' => 'select',
      '#title' => $this->t('Email digest'),
      '#options' => [
        'never' => $this->t('Never'),
        'daily' => $this->t('Daily'),
        'weekly' => $this->t('Weekly'),
      ],
      '#default_value' => $email_digest_default,
    ];

    if ($this->routeExists('myeventlane_notifications.preferences')) {
      $form['preferences']['inbox_link'] = [
        '#type' => 'link',
        '#title' => $this->t('Inbox & alert preferences'),
        '#url' => Url::fromRoute('myeventlane_notifications.preferences'),
        '#attributes' => ['class' => ['mel-vendor-settings-v2__header-link']],
      ];
    }
  }

  /**
   * AJAX callback to refresh the team section only (avoids nested <form> markup).
   */
  public function ajaxRefreshTeam(array &$form, FormStateInterface $form_state): array {
    return $form['team'];
  }

  /**
   * AJAX callback to refresh social links section only.
   */
  public function ajaxRefreshSocialLinks(array &$form, FormStateInterface $form_state): array {
    return $form['profile']['social_links'];
  }

  /**
   * Submit handler to add a social link.
   */
  public function addSocialLink(array &$form, FormStateInterface $form_state): void {
    // Capture current form values for existing links.
    $links = $form_state->getValue(['profile', 'social_links', 'links']) ?? [];
    $updated_values = [];
    foreach ($links as $delta => $link) {
      $updated_values[] = [
        'uri' => $link['uri'] ?? '',
        'title' => $link['platform'] ?? '',
      ];
    }

    // Add new empty row.
    $updated_values[] = ['uri' => '', 'title' => ''];

    // Store updated values in form state.
    $form_state->set('social_links_values', $updated_values);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Submit handler to remove a social link.
   */
  public function removeSocialLink(array &$form, FormStateInterface $form_state): void {
    // Determine which button was clicked.
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'] ?? '';

    // Extract delta from button name (e.g., 'remove_social_0' → 0).
    if (preg_match('/^remove_social_(\d+)$/', $button_name, $matches)) {
      $delta_to_remove = (int) $matches[1];

      // Capture current form values.
      $links = $form_state->getValue(['profile', 'social_links', 'links']) ?? [];
      $updated_values = [];
      foreach ($links as $delta => $link) {
        if ((int) $delta !== $delta_to_remove) {
          $updated_values[] = [
            'uri' => $link['uri'] ?? '',
            'title' => $link['platform'] ?? '',
          ];
        }
      }

      // Ensure at least one empty row remains.
      if (empty($updated_values)) {
        $updated_values[] = ['uri' => '', 'title' => ''];
      }

      // Store updated values in form state.
      $form_state->set('social_links_values', $updated_values);
      $form_state->setRebuild(TRUE);
    }
  }

  /**
   * Submit handler to add a team member.
   */
  public function addTeamMember(array &$form, FormStateInterface $form_state): void {
    $vendor = $this->loadVendorFromFormState($form_state);
    $user_id = $form_state->getValue(['team', 'add_member']);

    if (!$vendor) {
      $this->messenger()->addError($this->t('Organiser account not found.'));
      return;
    }

    if ($user_id && $vendor->hasField('field_vendor_users')) {
      $current_users = [];
      if (!$vendor->get('field_vendor_users')->isEmpty()) {
        foreach ($vendor->get('field_vendor_users') as $item) {
          if ($item->target_id) {
            $current_users[] = $item->target_id;
          }
        }
      }

      if (!in_array($user_id, $current_users, TRUE)) {
        $vendor->get('field_vendor_users')->appendItem(['target_id' => $user_id]);
        $vendor->save();
        $this->messenger()->addStatus($this->t('Team member added.'));
      }
      else {
        $this->messenger()->addWarning($this->t('User is already a team member.'));
      }
    }
  }

  /**
   * Loads vendor from form state with fallbacks.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor|null
   *   The vendor entity or NULL.
   */
  protected function loadVendorFromFormState(FormStateInterface $form_state): ?Vendor {
    // Prefer POST/rebuilt values (hidden vendor_id) over cached entity objects.
    $vendor_id = $form_state->getValue('vendor_id');
    if ($vendor_id === NULL || $vendor_id === '') {
      $vendor_id = $form_state->get('vendor_id');
    }
    if ($vendor_id !== NULL && $vendor_id !== '') {
      $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load((int) $vendor_id);
      if ($vendor instanceof Vendor) {
        $form_state->set('vendor', $vendor);
        $form_state->set('vendor_id', $vendor->id());
        return $vendor;
      }
    }

    $vendor = $form_state->get('vendor');
    if ($vendor instanceof Vendor) {
      return $vendor;
    }

    return $this->getCurrentVendor();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $this->logger->debug('VALIDATE ENTRY');
    parent::validateForm($form, $form_state);

    $this->logger->debug('VendorSettingsForm VALIDATE uid=@uid vendor_id=@vid', [
      '@uid' => (string) $this->currentUser->id(),
      '@vid' => $form_state->getValue('vendor_id') !== NULL && $form_state->getValue('vendor_id') !== ''
        ? (string) $form_state->getValue('vendor_id')
        : 'missing',
    ]);

    // Hidden vendor_id is not trusted — enforce membership server-side.
    if (!$this->currentUser->hasPermission('administer myeventlane vendor')) {
      $submitted_vid = (int) ($form_state->getValue('vendor_id') ?? 0);
      $allowed_vids = $this->userVendorMembershipQuery->getVendorIdsForUser((int) $this->currentUser->id());
      if (!$submitted_vid || !in_array($submitted_vid, $allowed_vids, TRUE)) {
        $this->logger->warning('Vendor settings: rejected vendor_id uid=@uid vid=@vid allowed=@allowed', [
          '@uid' => (string) $this->currentUser->id(),
          '@vid' => (string) $submitted_vid,
          '@allowed' => $allowed_vids === [] ? '(none)' : implode(', ', $allowed_vids),
        ]);
        $this->setVendorContextFormError($form, $form_state);
        return;
      }
    }

    $name = $form_state->getValue(['profile', 'name']);
    if (!is_string($name) || trim($name) === '') {
      $this->logger->warning('Vendor settings: missing required organiser name uid=@uid vendor_id=@vid', [
        '@uid' => (string) $this->currentUser->id(),
        '@vid' => $form_state->getValue('vendor_id') !== NULL && $form_state->getValue('vendor_id') !== ''
          ? (string) $form_state->getValue('vendor_id')
          : 'missing',
      ]);
      if (isset($form['profile']['name'])) {
        $form_state->setError($form['profile']['name'], $this->t('Display name is required.'));
      }
      else {
        $form_state->setErrorByName('name', $this->t('Display name is required.'));
      }
    }

    $website = $form_state->getValue(['profile', 'website']);
    if (!empty($website) && is_string($website)) {
      $website = trim($website);
      if ($website !== '' && !$this->isFullHttpUrl($website)) {
        if (isset($form['profile']['website'])) {
          $form_state->setError($form['profile']['website'], $this->t('Please enter the full URL, including https://'));
        }
      }
    }

    $rows = $form_state->getValue(['profile', 'social_links', 'links']);
    if (is_array($rows)) {
      foreach ($rows as $delta => $row) {
        if (!is_array($row) || empty($row['uri'])) {
          continue;
        }
        $uri = trim((string) $row['uri']);
        if ($uri !== '' && !$this->isFullHttpUrl($uri)) {
          if (isset($form['profile']['social_links']['links'][$delta]['uri'])) {
            $form_state->setError(
              $form['profile']['social_links']['links'][$delta]['uri'],
              $this->t('Please enter the full URL, including https://')
            );
          }
        }
      }
    }

    $accent = $form_state->getValue(['visual_assets', 'accent_colour']);
    if ($accent !== NULL && $accent !== '') {
      $accent = is_scalar($accent) ? trim((string) $accent) : '';
      if ($accent !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
        if (isset($form['visual_assets']['accent_colour'])) {
          $form_state->setError($form['visual_assets']['accent_colour'], $this->t('Enter a valid hex colour (e.g. #f26d5b).'));
        }
      }
    }
  }

  /**
   * Surfaces organiser-context failures on a visible field (not hidden vendor_id).
   */
  private function setVendorContextFormError(array $form, FormStateInterface $form_state): void {
    $message = $this->t('We could not verify your organiser account for this save. Please reload the page and try again.');
    if (isset($form['profile']['name'])) {
      $form_state->setError($form['profile']['name'], $message);
      return;
    }
    if (isset($form['actions']['submit'])) {
      $form_state->setError($form['actions']['submit'], $message);
      return;
    }
    $form_state->setErrorByName('vendor_id', $message);
  }

  /**
   * Logs a bounded form values summary without dumping the full Form API tree.
   *
   * @param string $label
   *   The source label to include in the log message.
   * @param mixed $values
   *   Submitted values, user input, or request POST data.
   */
  private function logBoundedFormDebugSummary(string $label, mixed $values): void {
    $summary = $this->summarizeFormDebugSections($values);
    $json = json_encode(
      $summary,
      JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === FALSE) {
      $json = '[summary encoding failed: ' . json_last_error_msg() . ']';
    }

    $this->logger->debug('@label SUMMARY: <pre>@data</pre>', [
      '@label' => $label,
      '@data' => $json,
    ]);
  }

  /**
   * Builds a bounded summary of the form sections needed for submit debugging.
   *
   * @param mixed $values
   *   Submitted values, user input, or request POST data.
   *
   * @return array<string, mixed>
   *   A safe summary containing top-level keys and expected section summaries.
   */
  private function summarizeFormDebugSections(mixed $values): array {
    if (!is_array($values)) {
      return [
        '_type' => get_debug_type($values),
        '_value' => $this->summarizeFormDebugValue($values),
      ];
    }

    $top_level_keys = array_keys($values);
    $summary = [
      '_top_level_count' => count($top_level_keys),
      '_top_level_keys' => array_slice($top_level_keys, 0, self::FORM_DEBUG_MAX_TOP_LEVEL_KEYS),
      '_section_presence' => [],
      'sections' => [],
    ];

    if (count($top_level_keys) > self::FORM_DEBUG_MAX_TOP_LEVEL_KEYS) {
      $summary['_top_level_keys_truncated'] = count($top_level_keys) - self::FORM_DEBUG_MAX_TOP_LEVEL_KEYS;
    }

    foreach (self::FORM_DEBUG_SECTION_KEYS as $section_key) {
      $is_present = array_key_exists($section_key, $values);
      $summary['_section_presence'][$section_key] = $is_present ? 'present' : 'missing';
      if ($is_present) {
        $summary['sections'][$section_key] = $this->summarizeFormDebugValue($values[$section_key]);
      }
    }

    return $summary;
  }

  /**
   * Safely summarizes a value for debug logs.
   *
   * @param mixed $value
   *   The value to summarize.
   * @param int $depth
   *   Current recursion depth.
   *
   * @return mixed
   *   A scalar value or bounded array summary.
   */
  private function summarizeFormDebugValue(mixed $value, int $depth = 0): mixed {
    if ($value === NULL || is_bool($value) || is_int($value) || is_float($value)) {
      return $value;
    }

    if (is_string($value)) {
      $length = strlen($value);
      if ($length <= self::FORM_DEBUG_MAX_STRING_LENGTH) {
        return $value;
      }
      return substr($value, 0, self::FORM_DEBUG_MAX_STRING_LENGTH) . '... [truncated, length=' . $length . ']';
    }

    if (is_object($value)) {
      return [
        '_type' => 'object',
        '_class' => get_class($value),
      ];
    }

    if (is_resource($value)) {
      return [
        '_type' => 'resource',
        '_resource_type' => get_resource_type($value),
      ];
    }

    if (!is_array($value)) {
      return [
        '_type' => get_debug_type($value),
      ];
    }

    $count = count($value);
    $summary = [
      '_type' => 'array',
      '_count' => $count,
    ];

    if ($depth >= self::FORM_DEBUG_MAX_DEPTH) {
      $summary['_truncated'] = 'max_depth';
      return $summary;
    }

    $shown = 0;
    foreach ($value as $key => $child_value) {
      if ($shown >= self::FORM_DEBUG_MAX_ARRAY_ITEMS) {
        break;
      }
      $summary[$key] = $this->summarizeFormDebugValue($child_value, $depth + 1);
      $shown++;
    }

    if ($count > $shown) {
      $summary['_truncated_items'] = $count - $shown;
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->logger->debug('SUBMIT ENTRY');

    $this->logBoundedFormDebugSummary('VALUES', $form_state->getValues());

    $this->logger->notice('VendorSettingsForm SUBMIT START uid=@uid vendor_id=@vid', [
      '@uid' => (string) $this->currentUser->id(),
      '@vid' => $form_state->getValue('vendor_id') !== NULL && $form_state->getValue('vendor_id') !== ''
        ? (string) $form_state->getValue('vendor_id')
        : 'missing',
    ]);

    $vendor = $this->loadVendorFromFormState($form_state);
    if (!$vendor) {
      $vid = $form_state->getValue('vendor_id');
      if ($vid) {
        $vendor = $this->vendorResolver->resolveFromContext(['vendor_id' => (int) $vid]);
      }
    }

    if (!$vendor) {
      $this->messenger()->addError($this->t('Organiser account not found. Unable to save settings. Please refresh the page and try again.'));
      return;
    }

    $had_vendor_store_link = $vendor->hasField('field_vendor_store')
      && !$vendor->get('field_vendor_store')->isEmpty();

    $business = $form_state->getValue(['store', 'business']) ?? [];
    $abn = trim((string) ($business['abn'] ?? ''));
    $abn = $abn !== '' ? (string) preg_replace('/\s+/', '', $abn) : '';
    $business_name = trim((string) ($business['business_name'] ?? ''));

    // Save profile information.
    $name = $form_state->getValue(['profile', 'name']);
    if (!is_string($name) || trim($name) === '') {
      $this->logger->error('Vendor settings save blocked: missing required organiser name uid=@uid vendor_id=@vid', [
        '@uid' => (string) $this->currentUser->id(),
        '@vid' => (string) $vendor->id(),
      ]);
      $this->messenger()->addError($this->t('Display name is required.'));
      return;
    }
    $vendor->setName(trim($name));

    if ($vendor->hasField('field_summary')) {
      $vendor->set('field_summary', $form_state->getValue(['profile', 'summary']));
    }

    if ($vendor->hasField('field_tagline')) {
      $tagline = $form_state->getValue(['profile', 'tagline']);
      $tagline = is_string($tagline) ? trim($tagline) : '';
      $vendor->set('field_tagline', $tagline !== '' ? $tagline : NULL);
    }

    if ($vendor->hasField('field_description')) {
      $description = $form_state->getValue(['profile', 'description']);
      if (!empty($description) && is_array($description)) {
        $vendor->set('field_description', [
          'value' => $description['value'] ?? '',
          'format' => $description['format'] ?? 'basic_html',
        ]);
      }
      else {
        $vendor->set('field_description', NULL);
      }
    }

    if ($vendor->hasField('field_vendor_bio')) {
      $bio = $form_state->getValue(['profile', 'bio']);
      if (!empty($bio) && is_array($bio)) {
        $vendor->set('field_vendor_bio', [
          'value' => $bio['value'] ?? '',
          'format' => $bio['format'] ?? 'basic_html',
        ]);
      }
      else {
        $vendor->set('field_vendor_bio', NULL);
      }
    }

    // Save visual assets.
    if (isset($form['visual_assets']['logo'])) {
      $logo_field = $form_state->getValue(['visual_assets', 'logo_field_name']);
      if ($logo_field && $vendor->hasField($logo_field)) {
        $logo_fids = $form_state->getValue(['visual_assets', 'logo']);
        if (!empty($logo_fids) && is_array($logo_fids)) {
          $file = $this->entityTypeManager->getStorage('file')->load($logo_fids[0]);
          if ($file) {
            $file->setPermanent();
            $file->save();
            $vendor->set($logo_field, ['target_id' => $file->id()]);
          }
        }
        else {
          $vendor->set($logo_field, NULL);
        }
      }
    }

    if (isset($form['visual_assets']['banner']) && $vendor->hasField('field_banner_image')) {
      $banner_fids = $form_state->getValue(['visual_assets', 'banner']);
      if (!empty($banner_fids) && is_array($banner_fids)) {
        $file = $this->entityTypeManager->getStorage('file')->load($banner_fids[0]);
        if ($file) {
          $file->setPermanent();
          $file->save();
          $vendor->set('field_banner_image', ['target_id' => $file->id()]);
        }
      }
      else {
        $vendor->set('field_banner_image', NULL);
      }
    }

    $accent_val = $form_state->getValue(['visual_assets', 'accent_colour']);
    if ($accent_val !== NULL && $accent_val !== '') {
      $this->saveVendorAccentColour($vendor, $accent_val);
    }

    // Save contact information.
    if ($vendor->hasField('field_email')) {
      $vendor->set('field_email', $form_state->getValue(['contact', 'email']) ?: NULL);
    }

    if ($vendor->hasField('field_phone')) {
      $vendor->set('field_phone', $form_state->getValue(['contact', 'phone']) ?: NULL);
    }

    if ($vendor->hasField('field_website')) {
      $website = $form_state->getValue(['profile', 'website']);
      $website = is_string($website) ? trim($website) : '';
      if ($website !== '') {
        $vendor->set('field_website', ['uri' => $website]);
      }
      else {
        $vendor->set('field_website', NULL);
      }
    }

    if ($vendor->hasField('field_address')) {
      $vendor->set('field_address', $form_state->getValue(['contact', 'address']) ?: NULL);
    }

    if ($vendor->hasField('field_msg_from_name')) {
      $v = $form_state->getValue(['contact', 'messaging_identity', 'msg_from_name']);
      $v = is_string($v) ? trim($v) : '';
      $vendor->set('field_msg_from_name', $v !== '' ? $v : NULL);
    }
    if ($vendor->hasField('field_msg_from_email')) {
      $v = $form_state->getValue(['contact', 'messaging_identity', 'msg_from_email']);
      $v = is_string($v) ? trim($v) : '';
      $vendor->set('field_msg_from_email', $v !== '' ? $v : NULL);
    }
    if ($vendor->hasField('field_msg_reply_to')) {
      $v = $form_state->getValue(['contact', 'messaging_identity', 'msg_reply_to']);
      $v = is_string($v) ? trim($v) : '';
      $vendor->set('field_msg_reply_to', $v !== '' ? $v : NULL);
    }
    if ($vendor->hasField('field_msg_footer')) {
      $footer = $form_state->getValue(['contact', 'messaging_identity', 'msg_footer']);
      if (!empty($footer) && is_array($footer)) {
        $vendor->set('field_msg_footer', [
          'value' => $footer['value'] ?? '',
          'format' => $footer['format'] ?? 'basic_html',
        ]);
      }
      else {
        $vendor->set('field_msg_footer', NULL);
      }
    }

    // Save social links from form state (captures AJAX changes).
    if ($vendor->hasField('field_social_links')) {
      $social_links = [];
      $links = $form_state->getValue(['profile', 'social_links', 'links']) ?? [];
      foreach ($links as $link) {
        if (!empty($link['uri'])) {
          $social_links[] = [
            'uri' => $link['uri'],
            'title' => $link['platform'] ?? '',
          ];
        }
      }
      $vendor->set('field_social_links', $social_links);
    }

    // Save public page settings.
    $public_fields = [
      'field_public_profile_published' => ['public_page', 'published'],
      'field_public_show_email' => ['public_page', 'optional_details', 'show_email'],
      'field_public_show_phone' => ['public_page', 'optional_details', 'show_phone'],
      'field_public_show_location' => ['public_page', 'optional_details', 'show_location'],
      'field_public_show_website' => ['public_page', 'optional_details', 'show_website'],
      'field_public_show_social_links' => ['public_page', 'optional_details', 'show_social_links'],
      'field_public_show_summary' => ['public_page', 'optional_details', 'show_summary'],
      'field_public_show_description' => ['public_page', 'optional_details', 'show_description'],
      'field_public_show_banner' => ['public_page', 'optional_details', 'show_banner'],
    ];

    foreach ($public_fields as $field_name => $form_path) {
      if ($vendor->hasField($field_name)) {
        $vendor->set($field_name, (int) ($form_state->getValue($form_path) ?? FALSE));
      }
    }

    // Save preferences to vendor entity fields.
    if ($vendor->hasField('field_pref_email_on_order')) {
      $vendor->set('field_pref_email_on_order', (int) ($form_state->getValue(['preferences', 'notifications', 'email_on_new_order']) ?? TRUE));
    }
    if ($vendor->hasField('field_pref_email_on_rsvp')) {
      $vendor->set('field_pref_email_on_rsvp', (int) ($form_state->getValue(['preferences', 'notifications', 'email_on_rsvp']) ?? TRUE));
    }
    if ($vendor->hasField('field_pref_email_digest')) {
      $vendor->set('field_pref_email_digest', $form_state->getValue(['preferences', 'notifications', 'email_digest']) ?? 'daily');
    }

    // Save business information fields (canonical values from store.business above).
    if ($vendor->hasField('field_business_name')) {
      $vendor->set('field_business_name', $business_name !== '' ? $business_name : NULL);
    }
    if ($vendor->hasField('field_abn')) {
      $vendor->set('field_abn', $abn !== '' ? $abn : NULL);
    }

    // Validate entity before persisting and store sync.
    $violations = $vendor->validate();
    $real_violations = [];

    if ($violations->count() > 0) {
      foreach ($violations as $violation) {
        $property_path = $violation->getPropertyPath();
        $message = (string) $violation->getMessage();

        // Skip access check violations for field_vendor_users.
        if (str_contains($property_path, 'field_vendor_users') && str_contains($message, 'cannot be referenced')) {
          $field = $vendor->get('field_vendor_users');
          $all_valid = TRUE;
          foreach ($field as $item) {
            if ($item->target_id) {
              $user = $this->entityTypeManager->getStorage('user')->load($item->target_id);
              if (!$user || !$user->isActive()) {
                $all_valid = FALSE;
                break;
              }
            }
          }
          if ($all_valid) {
            continue;
          }
        }

        $real_violations[] = $violation;
        $this->messenger()->addError($this->t('Validation error: @message', [
          '@message' => $message,
        ]));
      }

      if (!empty($real_violations)) {
        return;
      }
    }

    $vendor_storage = $this->entityTypeManager->getStorage('myeventlane_vendor');

    $store = NULL;
    if ($vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $candidate = $vendor->get('field_vendor_store')->entity;
      if ($candidate instanceof StoreInterface) {
        $store = $candidate;
      }
    }

    if (!$store) {
      $store = $this->vendorStoreSubscriber->ensureStoreForVendor($vendor);
    }

    if (!$store && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $candidate = $vendor->get('field_vendor_store')->entity;
      if ($candidate instanceof StoreInterface) {
        $store = $candidate;
      }
    }

    // After ensureStoreForVendor links a new store, refresh only field_vendor_store from
    // storage if needed. Do not replace $vendor with a reloaded entity: that would drop
    // in-memory field changes applied above that are not yet saved at line below.
    if (!$had_vendor_store_link && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $vendor_storage->resetCache([(int) $vendor->id()]);
      $reloaded_vendor = $vendor_storage->load($vendor->id());
      if ($reloaded_vendor instanceof Vendor
        && !$reloaded_vendor->get('field_vendor_store')->isEmpty()) {
        $vendor->set('field_vendor_store', $reloaded_vendor->get('field_vendor_store')->getValue());
      }
      $candidate = $vendor->get('field_vendor_store')->entity ?? NULL;
      if ($candidate instanceof StoreInterface) {
        $store = $candidate;
      }
    }

    if (!$store) {
      $this->logger->error(
        'Vendor settings save failed: no linked store after ensureStoreForVendor for vendor @id',
        ['@id' => (string) $vendor->id()]
      );
      $this->messenger()->addWarning($this->t('Your profile changes could not be linked to your account for payments yet. Payment and tax settings may be incomplete; contact support if this persists.'));
    }
    else {
      if ($store->hasField('field_abn')) {
        $store->set('field_abn', $abn !== '' ? $abn : NULL);
      }
      if ($business_name !== '') {
        $store->setName($business_name);
      }
      try {
        $store->save();
      }
      catch (\Throwable $e) {
        $this->logger->error(
          'Vendor settings: could not sync Commerce store: @message',
          ['@message' => $e->getMessage()]
        );
      }
    }

    try {
      $this->brandMediaManager->synchroniseFromLegacy($vendor, [
        VendorBrandMediaManager::ASSET_PUBLIC_LOGO,
        VendorBrandMediaManager::ASSET_BANNER,
      ]);
      $vendor->save();

      $this->logger->notice('VendorSettingsForm SUBMIT SUCCESS vendor_id=@vid', [
        '@vid' => (string) $vendor->id(),
      ]);

      // Clear entity cache.
      $vendor_storage->resetCache([(int) $vendor->id()]);

      // Invalidate cache tags.
      $cache_tags = [
        'myeventlane_vendor:' . $vendor->id(),
        'myeventlane_vendor_list',
      ];
      $this->cacheTagsInvalidator->invalidateTags($cache_tags);

      $this->messenger()->addStatus($this->t('Your settings have been saved.'));
      $form_state->setRedirect('myeventlane_vendor.console.settings');
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to save vendor settings: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('An error occurred while saving: @message', [
        '@message' => $e->getMessage(),
      ]));
    }
  }

  /**
   * Checks whether a named route is registered.
   */
  private function routeExists(string $route_name): bool {
    try {
      $this->routeProvider->getRouteByName($route_name);
      return TRUE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Validates absolute http(s) URLs as entered by the user (no silent rewriting).
   */
  private function isFullHttpUrl(string $value): bool {
    $value = trim($value);
    return $value !== '' && preg_match('#^https?://\S+$#i', $value) === 1;
  }

  /**
   * Default accent colour for the colour element.
   */
  private function getVendorAccentColorDefault(Vendor $vendor): string {
    $default = '#f26d5b';
    foreach (['field_accent_colour', 'field_msg_accent_color'] as $field_name) {
      if (!$vendor->hasField($field_name) || $vendor->get($field_name)->isEmpty()) {
        continue;
      }
      $raw = $vendor->get($field_name)->value;
      $candidate = is_scalar($raw) ? strtolower(trim((string) $raw)) : '';
      if (preg_match('/^#[0-9a-f]{6}$/', $candidate) === 1) {
        return $candidate;
      }
    }
    return $default;
  }

  /**
   * Persists accent colour to canonical vendor fields (aligned with messaging branding).
   */
  private function saveVendorAccentColour(Vendor $vendor, mixed $value): void {
    $color = is_scalar($value) ? strtolower(trim((string) $value)) : '#f26d5b';
    if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
      $color = '#f26d5b';
    }
    if ($vendor->hasField('field_accent_colour')) {
      $vendor->set('field_accent_colour', $color);
    }
    if ($vendor->hasField('field_msg_accent_color')) {
      $allowed = $this->getMessagingAccentColorAllowedValues($vendor);
      if ($allowed === [] || in_array($color, $allowed, TRUE)) {
        $vendor->set('field_msg_accent_color', $color);
      }
    }
  }

  /**
   * Allowed hex values for the restricted messaging accent list field, if any.
   *
   * @return array<string>
   */
  private function getMessagingAccentColorAllowedValues(Vendor $vendor): array {
    if (!$vendor->hasField('field_msg_accent_color')) {
      return [];
    }
    $allowed_values = $vendor->get('field_msg_accent_color')
      ->getFieldDefinition()
      ->getFieldStorageDefinition()
      ->getSetting('allowed_values');
    if (!is_array($allowed_values)) {
      return [];
    }
    $values = [];
    foreach ($allowed_values as $key => $definition) {
      if (is_array($definition) && isset($definition['value'])) {
        $values[] = strtolower((string) $definition['value']);
      }
      elseif (is_string($key)) {
        $values[] = strtolower($key);
      }
    }
    return $values;
  }

}
