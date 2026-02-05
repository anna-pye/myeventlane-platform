<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Url;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Comprehensive vendor profile settings form.
 *
 * Uses CurrentVendorResolver for consistent vendor resolution.
 */
class VendorProfileSettingsForm extends FormBase {

  /**
   * The entity type manager.
   */
  protected ?EntityTypeManagerInterface $entityTypeManager = NULL;

  /**
   * The current user.
   */
  protected ?AccountProxyInterface $currentUser = NULL;

  /**
   * The onboarding manager.
   */
  protected ?OnboardingManager $onboardingManager = NULL;

  /**
   * The current vendor resolver.
   */
  protected ?CurrentVendorResolverInterface $vendorResolver = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUser = $container->get('current_user');
    $instance->onboardingManager = $container->get('myeventlane_onboarding.manager');
    $instance->vendorResolver = $container->get('myeventlane_vendor.current_vendor_resolver');
    return $instance;
  }

  /**
   * Gets the entity type manager with lazy loading fallback.
   */
  protected function getEntityTypeManager(): EntityTypeManagerInterface {
    if ($this->entityTypeManager === NULL) {
      $this->entityTypeManager = \Drupal::entityTypeManager();
    }
    return $this->entityTypeManager;
  }

  /**
   * Gets the current user with lazy loading fallback.
   */
  protected function getCurrentUser(): AccountProxyInterface {
    if ($this->currentUser === NULL) {
      $this->currentUser = \Drupal::currentUser();
    }
    return $this->currentUser;
  }

  /**
   * Gets the vendor resolver with lazy loading fallback.
   */
  protected function getVendorResolver(): ?CurrentVendorResolverInterface {
    if ($this->vendorResolver === NULL && \Drupal::hasService('myeventlane_vendor.current_vendor_resolver')) {
      $this->vendorResolver = \Drupal::service('myeventlane_vendor.current_vendor_resolver');
    }
    return $this->vendorResolver;
  }

  /**
   * Gets the onboarding manager with lazy loading fallback.
   */
  protected function getOnboardingManager(): ?OnboardingManager {
    if ($this->onboardingManager === NULL && \Drupal::hasService('myeventlane_onboarding.manager')) {
      $this->onboardingManager = \Drupal::service('myeventlane_onboarding.manager');
    }
    return $this->onboardingManager;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'vendor_profile_settings';
  }

  /**
   * Gets the current vendor using CurrentVendorResolver.
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor|null
   *   The vendor entity, or NULL if not found.
   */
  protected function getCurrentVendor(): ?Vendor {
    $resolver = $this->getVendorResolver();
    if ($resolver) {
      return $resolver->resolveFromCurrentUser();
    }

    // Fallback to legacy resolution if service unavailable.
    $uid = (int) $this->getCurrentUser()->id();
    if ($uid === 0) {
      return NULL;
    }

    $storage = $this->getEntityTypeManager()->getStorage('myeventlane_vendor');
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
    // Try to get vendor from form state first (for rebuilds).
    if (!$vendor) {
      $vendor = $form_state->get('vendor');
      // If vendor is in form state, reload it fresh to avoid stale data.
      if ($vendor && $vendor->id()) {
        $this->getEntityTypeManager()->getStorage('myeventlane_vendor')->resetCache([$vendor->id()]);
        $vendor = $this->getEntityTypeManager()->getStorage('myeventlane_vendor')->load($vendor->id());
      }
    }

    // If still no vendor, try to load by ID from form state or form values.
    if (!$vendor) {
      $vendor_id = $form_state->get('vendor_id');
      if (!$vendor_id && $form_state->hasValue('vendor_id')) {
        $vendor_id = $form_state->getValue('vendor_id');
      }
      if ($vendor_id) {
        $this->getEntityTypeManager()->getStorage('myeventlane_vendor')->resetCache([$vendor_id]);
        $vendor = $this->getEntityTypeManager()->getStorage('myeventlane_vendor')->load($vendor_id);
      }
    }

    // If still no vendor, try to get from current user via resolver.
    if (!$vendor) {
      $vendor = $this->getCurrentVendor();
      if ($vendor && $vendor->id()) {
        $this->getEntityTypeManager()->getStorage('myeventlane_vendor')->resetCache([$vendor->id()]);
        $vendor = $this->getEntityTypeManager()->getStorage('myeventlane_vendor')->load($vendor->id());
      }
    }

    if (!$vendor) {
      $form['error'] = [
        '#markup' => '<p>' . $this->t('Vendor not found. Please contact support.') . '</p>',
      ];
      return $form;
    }

    // Store vendor in form state for use in submit.
    $form_state->set('vendor', $vendor);
    $form_state->set('vendor_id', $vendor->id());

    // Store vendor ID in form for rebuilds and POST submissions.
    $form['vendor_id'] = [
      '#type' => 'value',
      '#value' => $vendor->id(),
      '#weight' => -1000,
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
        $user = $this->getEntityTypeManager()->getStorage('user')->load((int) $this->getCurrentUser()->id());
        $onboardingManager = $this->getOnboardingManager();
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
        \Drupal::logger('myeventlane_vendor')->warning('Onboarding panel failed on settings form: @m', ['@m' => $e->getMessage()]);
      }
    }

    // Override form action URL on vendor domain.
    if (\Drupal::hasService('myeventlane_core.domain_detector')) {
      $domain_detector = \Drupal::service('myeventlane_core.domain_detector');
      if ($domain_detector->isVendorDomain()) {
        $form['#action'] = Url::fromRoute('myeventlane_vendor.console.settings', [], ['absolute' => TRUE])->toString();
      }
    }

    $form['#tree'] = TRUE;
    $form['#attached']['library'][] = 'myeventlane_vendor_theme/global-styling';
    $form['#attached']['library'][] = 'myeventlane_vendor/vendor_settings';

    // Vertical tabs for different sections.
    $form['tabs'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Vendor Settings'),
      '#default_tab' => 'edit-profile',
    ];

    // Profile Information Section.
    $form['profile'] = [
      '#type' => 'details',
      '#title' => $this->t('Profile Information'),
      '#group' => 'tabs',
      '#open' => TRUE,
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

    // Visual Assets Section.
    $form['visual'] = [
      '#type' => 'details',
      '#title' => $this->t('Visual Assets'),
      '#group' => 'tabs',
    ];

    if ($vendor->hasField('field_vendor_logo') || $vendor->hasField('field_logo_image')) {
      $logo_field = $vendor->hasField('field_vendor_logo') ? 'field_vendor_logo' : 'field_logo_image';
      $logo_default = [];
      if (!$vendor->get($logo_field)->isEmpty()) {
        $logo_default = [$vendor->get($logo_field)->target_id];
      }
      $form['visual']['logo'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Logo'),
        '#default_value' => $logo_default,
        '#upload_location' => 'public://vendor-assets/',
        '#upload_validators' => [
          'file_validate_extensions' => ['png jpg jpeg gif svg webp'],
          'file_validate_image_resolution' => ['2000x2000', '100x100'],
        ],
        '#description' => $this->t('Your organization logo. Recommended size: 400x400px. Square format works best.'),
      ];
      $form['visual']['logo_field_name'] = [
        '#type' => 'value',
        '#value' => $logo_field,
      ];
    }

    if ($vendor->hasField('field_banner_image')) {
      $banner_default = [];
      if (!$vendor->get('field_banner_image')->isEmpty()) {
        $banner_default = [$vendor->get('field_banner_image')->target_id];
      }
      $form['visual']['banner'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Banner Image'),
        '#default_value' => $banner_default,
        '#upload_location' => 'public://vendor-assets/',
        '#upload_validators' => [
          'file_validate_extensions' => ['png jpg jpeg gif webp'],
          'file_validate_image_resolution' => ['4000x2000', '1200x300'],
        ],
        '#description' => $this->t('Banner image for your vendor page. Recommended size: 1920x400px.'),
      ];
    }

    // Contact Information Section.
    $form['contact'] = [
      '#type' => 'details',
      '#title' => $this->t('Contact Information'),
      '#group' => 'tabs',
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
      $form['contact']['website'] = [
        '#type' => 'url',
        '#title' => $this->t('Website'),
        '#default_value' => $website_uri,
        '#description' => $this->t('Your organization website URL.'),
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
          '#type' => 'url',
          '#default_value' => $value['uri'] ?? '',
          '#size' => 40,
        ];
        $form['contact']['social_links']['links'][$delta]['remove'] = [
          '#type' => 'submit',
          '#value' => $this->t('Remove'),
          '#name' => 'remove_social_' . $delta,
          '#submit' => ['::removeSocialLink'],
          '#ajax' => [
            'callback' => '::ajaxRefreshSocialLinks',
            'wrapper' => 'social-links-wrapper',
          ],
          '#limit_validation_errors' => [],
        ];
      }

      $form['contact']['social_links']['add'] = [
        '#type' => 'submit',
        '#value' => $this->t('Add Social Link'),
        '#submit' => ['::addSocialLink'],
        '#ajax' => [
          'callback' => '::ajaxRefreshSocialLinks',
          'wrapper' => 'social-links-wrapper',
        ],
        '#limit_validation_errors' => [],
      ];
    }

    // Public Page Settings Section.
    $form['public'] = [
      '#type' => 'details',
      '#title' => $this->t('Public Page Settings'),
      '#group' => 'tabs',
      '#description' => $this->t('Control what information is displayed on your public vendor page.'),
    ];

    if ($vendor->hasField('field_public_show_email')) {
      $form['public']['show_email'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show email on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_email', FALSE),
      ];
    }

    if ($vendor->hasField('field_public_show_phone')) {
      $form['public']['show_phone'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show phone on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_phone', FALSE),
      ];
    }

    if ($vendor->hasField('field_public_show_location')) {
      $form['public']['show_location'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show address/location on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_location', FALSE),
      ];
    }

    if ($vendor->hasField('field_website') && $vendor->hasField('field_public_show_website')) {
      $form['public']['show_website'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show website on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_website', FALSE),
        '#description' => $this->t('Display your website URL on your public vendor profile.'),
      ];
    }

    if ($vendor->hasField('field_social_links') && $vendor->hasField('field_public_show_social_links')) {
      $form['public']['show_social_links'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show social media links on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_social_links', FALSE),
        '#description' => $this->t('Display your social media links on your public vendor profile.'),
      ];
    }

    if ($vendor->hasField('field_summary') && $vendor->hasField('field_public_show_summary')) {
      $form['public']['show_summary'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show summary on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_summary', FALSE),
        '#description' => $this->t('Display your short summary on your public vendor profile.'),
      ];
    }

    if ($vendor->hasField('field_description') && $vendor->hasField('field_public_show_description')) {
      $form['public']['show_description'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show description on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_description', FALSE),
        '#description' => $this->t('Display your full description on your public vendor profile.'),
      ];
    }

    if ($vendor->hasField('field_banner_image') && $vendor->hasField('field_public_show_banner')) {
      $form['public']['show_banner'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show banner image on public page'),
        '#default_value' => (bool) $this->getFieldValue($vendor, 'field_public_show_banner', FALSE),
        '#description' => $this->t('Display your banner image on your public vendor profile.'),
      ];
    }

    // Recurring Venues Section.
    $form['venues'] = [
      '#type' => 'details',
      '#title' => $this->t('Recurring Venues'),
      '#group' => 'tabs',
      '#description' => $this->t('Save frequently used venues to quickly add them to events.'),
    ];

    $form['venues']['venue_list'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Venue management will be implemented here. For now, venues are managed per-event.') . '</p>',
    ];

    // Payment & Store Settings Section.
    $form['payment'] = [
      '#type' => 'details',
      '#title' => $this->t('Payment & Store Settings'),
      '#group' => 'tabs',
    ];

    // Business Information subsection.
    $form['payment']['business'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Business Information'),
      '#description' => $this->t('Legal business details for invoices and tax documents.'),
    ];

    if ($vendor->hasField('field_business_name')) {
      $form['payment']['business']['business_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Legal Business Name'),
        '#default_value' => $this->getFieldValue($vendor, 'field_business_name', ''),
        '#description' => $this->t('Your registered business name (if different from display name).'),
        '#maxlength' => 255,
      ];
    }

    if ($vendor->hasField('field_abn')) {
      $form['payment']['business']['abn'] = [
        '#type' => 'textfield',
        '#title' => $this->t('ABN'),
        '#default_value' => $this->getFieldValue($vendor, 'field_abn', ''),
        '#description' => $this->t('Australian Business Number (e.g., 12 345 678 901).'),
        '#maxlength' => 14,
        '#pattern' => '[0-9 ]{11,14}',
      ];
    }

    // Store & Stripe subsection.
    $form['payment']['store'] = [
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

        $form['payment']['store']['details'] = [
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
        $form['payment']['store']['stripe_section'] = [
          '#type' => 'fieldset',
          '#title' => $this->t('Stripe Connect'),
        ];

        if ($stripe_connected) {
          $status_markup = '<div class="stripe-status stripe-status--connected">';
          $status_markup .= '<span class="status-indicator status-indicator--success"></span>';
          $status_markup .= '<strong>' . $this->t('Connected') . '</strong>';
          $status_markup .= '</div>';

          $form['payment']['store']['stripe_section']['status'] = [
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

          $form['payment']['store']['stripe_section']['capabilities'] = [
            '#theme' => 'item_list',
            '#items' => $capabilities,
            '#attributes' => ['class' => ['stripe-capabilities']],
          ];

          // Manage Stripe button.
          $form['payment']['store']['stripe_section']['manage'] = [
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

          $form['payment']['store']['stripe_section']['status'] = [
            '#type' => 'markup',
            '#markup' => $status_markup,
          ];

          // Connect Stripe button.
          $form['payment']['store']['stripe_section']['connect'] = [
            '#type' => 'link',
            '#title' => $this->t('Connect Stripe Account'),
            '#url' => Url::fromRoute('myeventlane_vendor.stripe_connect'),
            '#attributes' => [
              'class' => ['button', 'button--primary'],
            ],
          ];
        }

        // Tax settings info (read-only for now).
        if ($store->hasField('prices_include_tax')) {
          $prices_include_tax = !$store->get('prices_include_tax')->isEmpty()
            && (bool) $store->get('prices_include_tax')->value;

          $form['payment']['store']['tax_info'] = [
            '#type' => 'markup',
            '#markup' => '<p class="tax-info"><strong>' . $this->t('Tax Settings:') . '</strong> '
              . ($prices_include_tax ? $this->t('Prices include GST') : $this->t('Prices exclude GST'))
              . '</p>',
          ];
        }
      }
    }
    else {
      $form['payment']['store']['no_store'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning"><p>'
          . $this->t('No store configured. Please complete your account setup to enable payment processing.')
          . '</p></div>',
      ];
    }

    // Team Members Section.
    $form['team'] = [
      '#type' => 'details',
      '#title' => $this->t('Team Members'),
      '#group' => 'tabs',
      '#description' => $this->t('Manage users who have access to manage this vendor account.'),
    ];

    if ($vendor->hasField('field_vendor_users')) {
      $team_members = [];
      if (!$vendor->get('field_vendor_users')->isEmpty()) {
        foreach ($vendor->get('field_vendor_users') as $item) {
          if ($item->target_id) {
            $user = $this->getEntityTypeManager()->getStorage('user')->load($item->target_id);
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

      $form['team']['add_member_submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Add Member'),
        '#submit' => ['::addTeamMember'],
        '#ajax' => [
          'callback' => '::ajaxRefreshForm',
          'wrapper' => 'vendor-settings-form',
        ],
      ];
    }

    // Preferences Section - now loads from/saves to vendor entity fields.
    $form['preferences'] = [
      '#type' => 'details',
      '#title' => $this->t('Preferences'),
      '#group' => 'tabs',
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

    // Form wrapper for AJAX.
    $form['#prefix'] = '<div id="vendor-settings-form">';
    $form['#suffix'] = '</div>';

    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Settings'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * AJAX callback to refresh the form.
   */
  public function ajaxRefreshForm(array &$form, FormStateInterface $form_state): array {
    return $form;
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
    // Get current links from form state.
    $social_values = $form_state->get('social_links_values') ?? [];

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
    $form_state->setRebuild();
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
    }

    $form_state->setRebuild();
  }

  /**
   * Submit handler to add a team member.
   */
  public function addTeamMember(array &$form, FormStateInterface $form_state): void {
    $vendor = $this->loadVendorFromFormState($form_state);
    $user_id = $form_state->getValue(['team', 'add_member']);

    if (!$vendor) {
      $this->messenger()->addError($this->t('Vendor not found.'));
      $form_state->setRebuild();
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

    $form_state->setRebuild();
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
    $vendor = $form_state->get('vendor');
    if ($vendor instanceof Vendor) {
      return $vendor;
    }

    $vendor_id = $form_state->get('vendor_id') ?? $form_state->getValue('vendor_id');
    if ($vendor_id) {
      $vendor = $this->getEntityTypeManager()->getStorage('myeventlane_vendor')->load($vendor_id);
      if ($vendor instanceof Vendor) {
        $form_state->set('vendor', $vendor);
        $form_state->set('vendor_id', $vendor->id());
        return $vendor;
      }
    }

    return $this->getCurrentVendor();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate website URL if provided.
    $website = $form_state->getValue(['contact', 'website']);
    if (!empty($website) && !filter_var($website, FILTER_VALIDATE_URL)) {
      $form_state->setError($form['contact']['website'], $this->t('Please enter a valid website URL.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $vendor = $this->loadVendorFromFormState($form_state);

    if (!$vendor) {
      $this->messenger()->addError($this->t('Vendor not found. Unable to save settings. Please refresh the page and try again.'));
      $form_state->setRebuild();
      return;
    }

    // Save profile information.
    $vendor->setName($form_state->getValue(['profile', 'name']));

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
    if (isset($form['visual']['logo'])) {
      $logo_field = $form_state->getValue(['visual', 'logo_field_name']);
      if ($logo_field && $vendor->hasField($logo_field)) {
        $logo_fids = $form_state->getValue(['visual', 'logo']);
        if (!empty($logo_fids) && is_array($logo_fids)) {
          $file = $this->getEntityTypeManager()->getStorage('file')->load($logo_fids[0]);
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

    if (isset($form['visual']['banner']) && $vendor->hasField('field_banner_image')) {
      $banner_fids = $form_state->getValue(['visual', 'banner']);
      if (!empty($banner_fids) && is_array($banner_fids)) {
        $file = $this->getEntityTypeManager()->getStorage('file')->load($banner_fids[0]);
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
      'field_public_show_email' => ['public', 'show_email'],
      'field_public_show_phone' => ['public', 'show_phone'],
      'field_public_show_location' => ['public', 'show_location'],
      'field_public_show_website' => ['public', 'show_website'],
      'field_public_show_social_links' => ['public', 'show_social_links'],
      'field_public_show_summary' => ['public', 'show_summary'],
      'field_public_show_description' => ['public', 'show_description'],
      'field_public_show_banner' => ['public', 'show_banner'],
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

    // Save business information fields.
    if ($vendor->hasField('field_business_name')) {
      $vendor->set('field_business_name', trim((string) $form_state->getValue(['payment', 'business', 'business_name'])) ?: NULL);
    }
    if ($vendor->hasField('field_abn')) {
      $abn = trim((string) $form_state->getValue(['payment', 'business', 'abn']));
      // Normalize ABN format (remove spaces, validate).
      $abn = preg_replace('/\s+/', '', $abn);
      $vendor->set('field_abn', $abn ?: NULL);
    }

    // Validate and save.
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
              $user = $this->getEntityTypeManager()->getStorage('user')->load($item->target_id);
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
        $form_state->setRebuild();
        return;
      }
    }

    try {
      $vendor->save();

      // Clear entity cache.
      $this->getEntityTypeManager()->getStorage('myeventlane_vendor')->resetCache([$vendor->id()]);

      // Invalidate cache tags.
      $cache_tags = [
        'myeventlane_vendor:' . $vendor->id(),
        'myeventlane_vendor_list',
      ];
      \Drupal::service('cache_tags.invalidator')->invalidateTags($cache_tags);

      $this->messenger()->addStatus($this->t('Vendor settings saved successfully.'));
      $form_state->setRedirect('myeventlane_vendor.console.settings');
    }
    catch (\Exception $e) {
      \Drupal::logger('myeventlane_vendor')->error('Failed to save vendor settings: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('An error occurred while saving: @message', [
        '@message' => $e->getMessage(),
      ]));
      $form_state->setRebuild();
    }
  }

}
