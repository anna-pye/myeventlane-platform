<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_settings\Form;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\EventSubscriber\VendorStoreSubscriber;
use Drupal\myeventlane_vendor\Form\FormActionUrlFixer;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Single source of truth for /vendor/settings — profile, contact, payments, team.
 *
 * Data is stored on the myeventlane_vendor entity (and Commerce store where synced).
 */
class VendorSettingsForm extends FormBase {

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

    $this->logger->notice('FORM CHECK: form_id=@fid build_id=@bid token=@tok', [
      '@fid' => (string) ($request->request->get('form_id') ?? ''),
      '@bid' => (string) ($request->request->get('form_build_id') ?? ''),
      '@tok' => (string) ($request->request->get('form_token') ?? ''),
    ]);

    $this->logger->notice('FORM STATE: isSubmitted=@submitted', [
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
        '#markup' => '<p>' . $this->t('Vendor not found. Please contact support.') . '</p>',
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

    $form['page_header'] = [
      '#markup' => '<header class="mel-settings-header"><h1 class="mel-settings-header__title">' . $this->t('Set up your organiser profile') . '</h1><p class="mel-settings-header__lede">' . $this->t('This is what people see on your events.') . '</p></header>',
      '#weight' => -1002,
    ];

    // Preview link to public profile.
    // Build the public profile URL - uses the public domain (not vendor subdomain).
    $public_url = Url::fromRoute('entity.myeventlane_vendor.canonical', [
      'myeventlane_vendor' => $vendor->id(),
    ], ['absolute' => TRUE]);

    // Ensure the URL uses the public domain (not vendor subdomain).
    $public_url_string = $public_url->toString();
    // Replace vendor subdomain with main domain if present.
    $public_url_string = preg_replace('#^https?://vendor\.#', 'https://', $public_url_string);

    $form['preview_link'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['vendor-preview-link-wrapper']],
      '#weight' => -998,
    ];

    $form['preview_link']['link'] = [
      '#type' => 'link',
      '#title' => $this->t('Preview Public Profile'),
      '#url' => Url::fromUri($public_url_string),
      '#attributes' => [
        'class' => ['button', 'button--secondary', 'vendor-preview-link'],
        'target' => '_blank',
        'rel' => 'noopener noreferrer',
      ],
      '#prefix' => '<div class="vendor-preview-banner"><span class="preview-icon">👁</span>',
      '#suffix' => '<span class="preview-hint">' . $this->t('See how your profile looks to visitors') . '</span></div>',
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
          if ($show_panel) {
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
    $form['#attributes']['id'] = 'vendor-settings-form';
    $form['#attributes']['novalidate'] = 'novalidate';
    $form['#method'] = 'post';
    $form['#action'] = $this->getRequest()->getRequestUri();

    $form['#attached']['library'][] = 'myeventlane_vendor_theme/global-styling';
    $form['#attached']['library'][] = 'myeventlane_vendor_settings/settings_form';

    $this->buildProfileSection($form, $form_state, $vendor);
    $this->buildVisualAssetsSection($form, $form_state, $vendor);
    $this->buildContactSection($form, $form_state, $vendor);
    $this->buildPublicSettingsSection($form, $form_state, $vendor);
    $this->buildVenuesSection($form, $form_state, $vendor);
    $this->buildCommerceSection($form, $form_state, $vendor);
    $this->buildTeamSection($form, $form_state, $vendor);
    $this->buildPreferencesSection($form, $form_state, $vendor);

    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
      '#attributes' => ['class' => ['mel-form__actions']],
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
      '#title' => $this->t('Profile Information'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['mel-card', 'mel-vendor-settings__card']],
    ];
    $form['profile']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Vendor Name'),
      '#default_value' => $vendor->getName(),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#description' => $this->t('The public name of your organization or business.'),
    ];

    if ($vendor->hasField('field_summary')) {
      $form['profile']['summary'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Short Summary'),
        '#default_value' => $this->getFieldValue($vendor, 'field_summary', ''),
        '#rows' => 3,
        '#description' => $this->t('A brief one-line summary that appears in listings and search results.'),
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
        '#description' => $this->t('Full description of your organization. This appears on your public vendor page.'),
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
        '#description' => $this->t('Extended biography or about section for your vendor profile.'),
      ];
    }

  }

  /**
   * Builds image and visual branding fields.
   */
  private function buildVisualAssetsSection(array &$form, FormStateInterface $form_state, Vendor $vendor): void {
    $form['visual_assets'] = [
      '#type' => 'details',
      '#title' => $this->t('Visual Assets'),
      '#attributes' => ['class' => ['mel-card', 'mel-vendor-settings__card']],
    ];
    if ($vendor->hasField('field_vendor_logo') || $vendor->hasField('field_logo_image')) {
      $logo_field = $vendor->hasField('field_vendor_logo') ? 'field_vendor_logo' : 'field_logo_image';
      $logo_default = [];
      if (!$vendor->get($logo_field)->isEmpty()) {
        $logo_default = [$vendor->get($logo_field)->target_id];
      }
      $form['visual_assets']['logo'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Logo'),
        '#default_value' => $logo_default,
        '#upload_location' => 'public://vendor-assets/',
        '#upload_validators' => [
          'FileExtension' => ['extensions' => 'png jpg jpeg gif svg webp'],
          'FileSizeLimit' => ['fileLimit' => 5 * 1024 * 1024],
        ],
        '#description' => $this->t('Your organization logo. Recommended size: 400x400px. Square format works best.'),
      ];
      $form['visual_assets']['logo_field_name'] = [
        '#type' => 'value',
        '#value' => $logo_field,
      ];
    }

    if ($vendor->hasField('field_banner_image')) {
      $banner_default = [];
      if (!$vendor->get('field_banner_image')->isEmpty()) {
        $banner_default = [$vendor->get('field_banner_image')->target_id];
      }
      $form['visual_assets']['banner'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Banner Image'),
        '#default_value' => $banner_default,
        '#upload_location' => 'public://vendor-assets/',
        '#upload_validators' => [
          'FileExtension' => ['extensions' => 'png jpg jpeg gif webp'],
          'FileSizeLimit' => ['fileLimit' => 10 * 1024 * 1024],
        ],
        '#description' => $this->t('Banner image for your vendor page. Recommended size: 1920x400px.'),
      ];
    }

  }

  /**
   * Builds public contact and social link fields.
   */
  private function buildContactSection(array &$form, FormStateInterface $form_state, Vendor $vendor): void {
    $form['contact'] = [
      '#type' => 'details',
      '#title' => $this->t('Contact Information'),
      '#attributes' => ['class' => ['mel-card', 'mel-vendor-settings__card']],
    ];
    if ($vendor->hasField('field_email')) {
      $form['contact']['email'] = [
        '#type' => 'email',
        '#title' => $this->t('Contact Email'),
        '#default_value' => $this->getFieldValue($vendor, 'field_email', ''),
        '#description' => $this->t('Public contact email address.'),
      ];
    }

    if ($vendor->hasField('field_phone')) {
      $form['contact']['phone'] = [
        '#type' => 'tel',
        '#title' => $this->t('Phone Number'),
        '#default_value' => $this->getFieldValue($vendor, 'field_phone', ''),
        '#description' => $this->t('Contact phone number.'),
      ];
    }

    if ($vendor->hasField('field_website')) {
      $website_uri = '';
      if (!$vendor->get('field_website')->isEmpty()) {
        $website_uri = $vendor->get('field_website')->uri ?? '';
      }
      // Use textfield, not #url: <input type="url"> applies HTML5 validation before POST and
      // blocks submit for domain-only values; validateForm() prepends https:// server-side.
      $form['contact']['website'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Website'),
        '#default_value' => $website_uri,
        '#maxlength' => 2048,
        '#description' => $this->t('Your organization website URL.'),
        '#attributes' => [
          'placeholder' => 'https://example.com',
          'inputmode' => 'url',
          'autocomplete' => 'url',
        ],
      ];
    }

    if ($vendor->hasField('field_address')) {
      $form['contact']['address'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Address'),
        '#default_value' => $this->getFieldValue($vendor, 'field_address', ''),
        '#description' => $this->t('Business address.'),
      ];
    }

    // Social Links section with proper AJAX handling.
    if ($vendor->hasField('field_social_links')) {
      $form['contact']['social_links'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Social Media Links'),
        '#prefix' => '<div id="social-links-wrapper">',
        '#suffix' => '</div>',
      ];

      // Get social links from form state (for AJAX rebuilds) or from vendor.
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
        // Ensure at least one empty row.
        if (empty($social_values)) {
          $social_values[] = ['uri' => '', 'title' => ''];
        }
        $form_state->set('social_links_values', $social_values);
      }

      $form['contact']['social_links']['links'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Platform'),
          $this->t('URL'),
          $this->t('Operations'),
        ],
      ];

      foreach ($social_values as $delta => $value) {
        $form['contact']['social_links']['links'][$delta]['platform'] = [
          '#type' => 'textfield',
          '#default_value' => $value['title'] ?? '',
          '#placeholder' => $this->t('e.g., Facebook, Twitter, Instagram'),
          '#size' => 20,
        ];
        $form['contact']['social_links']['links'][$delta]['uri'] = [
          '#type' => 'textfield',
          '#default_value' => $value['uri'] ?? '',
          '#maxlength' => 2048,
          '#size' => 40,
          '#attributes' => [
            'placeholder' => $this->t('https://…'),
            'inputmode' => 'url',
          ],
        ];
        // TEMP (cache / submission isolation): AJAX disabled — re-add #ajax in STEP 8.
        $form['contact']['social_links']['links'][$delta]['remove'] = [
          '#type' => 'submit',
          '#value' => $this->t('Remove'),
          '#name' => 'remove_social_' . $delta,
          '#submit' => ['::removeSocialLink'],
          '#limit_validation_errors' => [
            ['contact', 'social_links'],
          ],
        ];
      }

      // TEMP (cache / submission isolation): AJAX disabled — re-add #ajax in STEP 8.
      $form['contact']['social_links']['add'] = [
        '#type' => 'submit',
        '#value' => $this->t('Add Social Link'),
        '#name' => 'add_social_link',
        '#submit' => ['::addSocialLink'],
        '#limit_validation_errors' => [
          ['contact', 'social_links'],
        ],
      ];
    }

  }

  /**
   * Builds public profile visibility controls.
   */
  private function buildPublicSettingsSection(array &$form, FormStateInterface $form_state, Vendor $vendor): void {
    $form['public_page'] = [
      '#type' => 'details',
      '#title' => $this->t('Public Page Settings'),
      '#attributes' => ['class' => ['mel-card', 'mel-vendor-settings__card']],
    ];
    $form['public_page']['_intro'] = [
      '#markup' => '<p class="mel-vendor-settings__card-description">' . $this->t('Control what information is displayed on your public vendor page.') . '</p>',
      '#weight' => -9,
    ];

    if ($vendor->hasField('field_public_show_email')) {
      $form['public_page']['show_email'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show email on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_email', FALSE),
      ];
    }

    if ($vendor->hasField('field_public_show_phone')) {
      $form['public_page']['show_phone'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show phone on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_phone', FALSE),
      ];
    }

    if ($vendor->hasField('field_public_show_location')) {
      $form['public_page']['show_location'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show address/location on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_location', FALSE),
      ];
    }

    if ($vendor->hasField('field_website') && $vendor->hasField('field_public_show_website')) {
      $form['public_page']['show_website'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show website on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_website', FALSE),
        '#description' => $this->t('Display your website URL on your public vendor profile.'),
      ];
    }

    if ($vendor->hasField('field_social_links') && $vendor->hasField('field_public_show_social_links')) {
      $form['public_page']['show_social_links'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show social media links on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_social_links', FALSE),
        '#description' => $this->t('Display your social media links on your public vendor profile.'),
      ];
    }

    if ($vendor->hasField('field_summary') && $vendor->hasField('field_public_show_summary')) {
      $form['public_page']['show_summary'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show summary on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_summary', FALSE),
        '#description' => $this->t('Display your short summary on your public vendor profile.'),
      ];
    }

    if ($vendor->hasField('field_description') && $vendor->hasField('field_public_show_description')) {
      $form['public_page']['show_description'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show description on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_description', FALSE),
        '#description' => $this->t('Display your full description on your public vendor profile.'),
      ];
    }

    if ($vendor->hasField('field_banner_image') && $vendor->hasField('field_public_show_banner')) {
      $form['public_page']['show_banner'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show banner image on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_banner', FALSE),
        '#description' => $this->t('Display your banner image on your public vendor profile.'),
      ];
    }

  }

  /**
   * Builds the recurring venues placeholder section.
   */
  private function buildVenuesSection(array &$form, FormStateInterface $form_state, Vendor $vendor): void {
    $form['venues'] = [
      '#type' => 'details',
      '#title' => $this->t('Recurring Venues'),
      '#attributes' => ['class' => ['mel-card', 'mel-vendor-settings__card']],
    ];
    $form['venues']['_intro'] = [
      '#markup' => '<p class="mel-vendor-settings__card-description">' . $this->t('Save frequently used venues to quickly add them to events.') . '</p>',
      '#weight' => -9,
    ];

    $form['venues']['venue_list'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Venue management will be implemented here. For now, venues are managed per-event.') . '</p>',
    ];

  }

  /**
   * Builds Commerce store, business, and payout readiness details.
   */
  private function buildCommerceSection(array &$form, FormStateInterface $form_state, Vendor $vendor): void {
    $form['store'] = [
      '#type' => 'details',
      '#title' => $this->t('Payment & Store Settings'),
      '#attributes' => ['class' => ['mel-card', 'mel-vendor-settings__card']],
    ];
    // Business Information subsection.
    $form['store']['business'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Business Information'),
      '#description' => $this->t('Legal business details for invoices and tax documents.'),
    ];

    if ($vendor->hasField('field_business_name')) {
      $form['store']['business']['business_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Legal Business Name'),
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

    // Store & Stripe subsection.
    $form['store']['payment_processing'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Store & Payment Processing'),
    ];

    if ($vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $store = $vendor->get('field_vendor_store')->entity;
      if ($store) {
        // Store details table.
        $store_rows = [];
        $store_rows[] = [
          ['data' => $this->t('Store Name'), 'header' => TRUE],
          ['data' => $store->label()],
        ];

        if ($store->getEmail()) {
          $store_rows[] = [
            ['data' => $this->t('Store Email'), 'header' => TRUE],
            ['data' => $store->getEmail()],
          ];
        }

        if ($store->getDefaultCurrencyCode()) {
          $store_rows[] = [
            ['data' => $this->t('Currency'), 'header' => TRUE],
            ['data' => $store->getDefaultCurrencyCode()],
          ];
        }

        if ($store->getTimezone()) {
          $store_rows[] = [
            ['data' => $this->t('Timezone'), 'header' => TRUE],
            ['data' => $store->getTimezone()],
          ];
        }

        $form['store']['payment_processing']['details'] = [
          '#type' => 'table',
          '#rows' => $store_rows,
          '#attributes' => ['class' => ['store-details-table']],
        ];

        // Stripe connection status.
        $stripe_connected = FALSE;
        $charges_enabled = FALSE;
        $payouts_enabled = FALSE;

        if ($store->hasField('field_stripe_connected')) {
          $stripe_connected = !$store->get('field_stripe_connected')->isEmpty()
            && (bool) $store->get('field_stripe_connected')->value;
        }
        if ($store->hasField('field_stripe_charges_enabled')) {
          $charges_enabled = !$store->get('field_stripe_charges_enabled')->isEmpty()
            && (bool) $store->get('field_stripe_charges_enabled')->value;
        }
        if ($store->hasField('field_stripe_payouts_enabled')) {
          $payouts_enabled = !$store->get('field_stripe_payouts_enabled')->isEmpty()
            && (bool) $store->get('field_stripe_payouts_enabled')->value;
        }

        // Stripe status display.
        $form['store']['payment_processing']['stripe_section'] = [
          '#type' => 'fieldset',
          '#title' => $this->t('Stripe Connect'),
        ];

        if ($stripe_connected) {
          $status_markup = '<div class="stripe-status stripe-status--connected">';
          $status_markup .= '<span class="status-indicator status-indicator--success"></span>';
          $status_markup .= '<strong>' . $this->t('Connected') . '</strong>';
          $status_markup .= '</div>';

          $form['store']['payment_processing']['stripe_section']['status'] = [
            '#type' => 'markup',
            '#markup' => $status_markup,
          ];

          // Show capabilities.
          $capabilities = [];
          $capabilities[] = $this->t('Charges: @status', [
            '@status' => $charges_enabled ? $this->t('Enabled') : $this->t('Pending'),
          ]);
          $capabilities[] = $this->t('Payouts: @status', [
            '@status' => $payouts_enabled ? $this->t('Enabled') : $this->t('Pending'),
          ]);

          $form['store']['payment_processing']['stripe_section']['capabilities'] = [
            '#theme' => 'item_list',
            '#items' => $capabilities,
            '#attributes' => ['class' => ['stripe-capabilities']],
          ];

          // Manage Stripe button.
          $form['store']['payment_processing']['stripe_section']['manage'] = [
            '#type' => 'link',
            '#title' => $this->t('Manage Stripe Account'),
            '#url' => Url::fromRoute('myeventlane_vendor.stripe_manage'),
            '#attributes' => [
              'class' => ['button', 'button--secondary'],
              'target' => '_blank',
              'rel' => 'noopener noreferrer',
            ],
          ];
        }
        else {
          // Not connected.
          $status_markup = '<div class="stripe-status stripe-status--disconnected">';
          $status_markup .= '<span class="status-indicator status-indicator--warning"></span>';
          $status_markup .= '<strong>' . $this->t('Not Connected') . '</strong>';
          $status_markup .= '</div>';
          $status_markup .= '<p class="description">' . $this->t('Connect your Stripe account to accept payments for tickets and donations.') . '</p>';

          $form['store']['payment_processing']['stripe_section']['status'] = [
            '#type' => 'markup',
            '#markup' => $status_markup,
          ];

          // Connect Stripe button.
          $form['store']['payment_processing']['stripe_section']['connect'] = [
            '#type' => 'link',
            '#title' => $this->t('Connect Stripe Account'),
            '#url' => Url::fromRoute('myeventlane_vendor.stripe_connect', [], [
              'query' => [
                'destination' => $this->getRequest()->getPathInfo() !== '' && $this->getRequest()->getPathInfo() !== '/'
                  ? (string) $this->getRequest()->getPathInfo()
                  : '/vendor/settings',
              ],
            ]),
            '#attributes' => [
              'class' => ['button', 'button--primary', 'mel-button', 'mel-button--primary', 'mel-button--stripe'],
            ],
          ];
        }

        // Tax settings info (read-only for now).
        if ($store->hasField('prices_include_tax')) {
          $prices_include_tax = !$store->get('prices_include_tax')->isEmpty()
            && (bool) $store->get('prices_include_tax')->value;

          $form['store']['payment_processing']['tax_info'] = [
            '#type' => 'markup',
            '#markup' => '<p class="tax-info"><strong>' . $this->t('Tax Settings:') . '</strong> '
              . ($prices_include_tax ? $this->t('Prices include GST') : $this->t('Prices exclude GST'))
              . '</p>',
          ];
        }
      }
    }
    else {
      $form['store']['payment_processing']['no_store'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning"><p>'
          . $this->t('No store configured. Please complete your account setup to enable payment processing.')
          . '</p></div>',
      ];
    }

  }

  /**
   * Builds team membership controls.
   */
  private function buildTeamSection(array &$form, FormStateInterface $form_state, Vendor $vendor): void {
    $form['team'] = [
      '#type' => 'details',
      '#title' => $this->t('Team Members'),
      '#attributes' => [
        'class' => ['mel-card', 'mel-vendor-settings__card'],
        'id' => 'mel-vendor-settings-team-ajax',
      ],
    ];
    $form['team']['_intro'] = [
      '#markup' => '<p class="mel-vendor-settings__card-description">' . $this->t('Manage users who have access to manage this vendor account.') . '</p>',
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
      '#title' => $this->t('Preferences'),
      '#attributes' => ['class' => ['mel-card', 'mel-vendor-settings__card']],
    ];
    $form['preferences']['notifications'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Email Notifications'),
    ];

    // Load preference defaults from vendor entity fields if they exist.
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
      '#title' => $this->t('Email me when a new order is placed'),
      '#default_value' => $email_on_order_default,
    ];

    $form['preferences']['notifications']['email_on_rsvp'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Email me when someone RSVPs to an event'),
      '#default_value' => $email_on_rsvp_default,
    ];

    $form['preferences']['notifications']['email_digest'] = [
      '#type' => 'select',
      '#title' => $this->t('Email Digest Frequency'),
      '#options' => [
        'never' => $this->t('Never'),
        'daily' => $this->t('Daily'),
        'weekly' => $this->t('Weekly'),
      ],
      '#default_value' => $email_digest_default,
    ];

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
    return $form['contact']['social_links'];
  }

  /**
   * Submit handler to add a social link.
   */
  public function addSocialLink(array &$form, FormStateInterface $form_state): void {
    // Capture current form values for existing links.
    $links = $form_state->getValue(['contact', 'social_links', 'links']) ?? [];
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
      $links = $form_state->getValue(['contact', 'social_links', 'links']) ?? [];
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
      $this->messenger()->addError($this->t('Vendor not found.'));
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
    $this->logger->notice('VALIDATE ENTRY');
    parent::validateForm($form, $form_state);

    $this->logger->notice('VendorSettingsForm VALIDATE uid=@uid vendor_id=@vid', [
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
        $form_state->setError($form['profile']['name'], $this->t('Vendor Name field is required.'));
      }
      else {
        $form_state->setErrorByName('name', $this->t('Vendor Name field is required.'));
      }
    }

    // Normalize website URL (prepend scheme); do not hard-fail on bare domains.
    $website = $form_state->getValue(['contact', 'website']);
    if (!empty($website)) {
      $website = trim((string) $website);
      if (!preg_match('#^https?://#', $website)) {
        $website = 'https://' . $website;
        $form_state->setValue(['contact', 'website'], $website);
      }
    }

    // Same normalization for social link URIs (inputs are textfields to avoid HTML5 url blocking).
    $rows = $form_state->getValue(['contact', 'social_links', 'links']);
    if (is_array($rows)) {
      foreach ($rows as $delta => $row) {
        if (!is_array($row) || empty($row['uri'])) {
          continue;
        }
        $uri = trim((string) $row['uri']);
        if ($uri !== '' && !preg_match('#^https?://#', $uri)) {
          $uri = 'https://' . $uri;
        }
        $form_state->setValue(['contact', 'social_links', 'links', $delta, 'uri'], $uri);
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

    $this->logger->notice('@label SUMMARY: <pre>@data</pre>', [
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
    $this->logger->notice('SUBMIT ENTRY');

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
      $this->messenger()->addError($this->t('Vendor not found. Unable to save settings. Please refresh the page and try again.'));
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
      $this->messenger()->addError($this->t('Vendor Name field is required.'));
      return;
    }
    $vendor->setName(trim($name));

    if ($vendor->hasField('field_summary')) {
      $vendor->set('field_summary', $form_state->getValue(['profile', 'summary']));
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

    // Save contact information.
    if ($vendor->hasField('field_email')) {
      $vendor->set('field_email', $form_state->getValue(['contact', 'email']) ?: NULL);
    }

    if ($vendor->hasField('field_phone')) {
      $vendor->set('field_phone', $form_state->getValue(['contact', 'phone']) ?: NULL);
    }

    if ($vendor->hasField('field_website')) {
      $website = $form_state->getValue(['contact', 'website']);
      if (!empty($website)) {
        if (!preg_match('/^https?:\/\//', $website)) {
          $website = 'https://' . $website;
        }
        $vendor->set('field_website', ['uri' => $website]);
      }
      else {
        $vendor->set('field_website', NULL);
      }
    }

    if ($vendor->hasField('field_address')) {
      $vendor->set('field_address', $form_state->getValue(['contact', 'address']) ?: NULL);
    }

    // Save social links from form state (captures AJAX changes).
    if ($vendor->hasField('field_social_links')) {
      $social_links = [];
      $links = $form_state->getValue(['contact', 'social_links', 'links']) ?? [];
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
      'field_public_show_email' => ['public_page', 'show_email'],
      'field_public_show_phone' => ['public_page', 'show_phone'],
      'field_public_show_location' => ['public_page', 'show_location'],
      'field_public_show_website' => ['public_page', 'show_website'],
      'field_public_show_social_links' => ['public_page', 'show_social_links'],
      'field_public_show_summary' => ['public_page', 'show_summary'],
      'field_public_show_description' => ['public_page', 'show_description'],
      'field_public_show_banner' => ['public_page', 'show_banner'],
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
      $this->messenger()->addWarning($this->t('Your profile changes could not be linked to a Commerce store yet. Payment and tax settings may be incomplete; contact support if this persists.'));
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

}
