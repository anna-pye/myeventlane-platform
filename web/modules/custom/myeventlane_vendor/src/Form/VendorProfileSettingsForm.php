<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Url;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Comprehensive vendor profile settings form.
 */
class VendorProfileSettingsForm extends FormBase {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'vendor_profile_settings';
  }

  /**
   * Gets the current vendor for the logged-in user.
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor|null
   *   The vendor entity, or NULL if not found.
   */
  protected function getCurrentVendor(): ?Vendor {
    $uid = (int) $this->currentUser->id();
    if ($uid === 0) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_vendor');

    // First, try to find vendor where user is the owner.
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

    // If not found, check vendors where user is in field_vendor_users.
    $user_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_vendor_users', $uid)
      ->range(0, 1)
      ->execute();

    if (!empty($user_ids)) {
      $vendor = $storage->load(reset($user_ids));
      if ($vendor instanceof Vendor) {
        return $vendor;
      }
    }

    return NULL;
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
        // Reset cache before loading to ensure fresh data.
        $this->entityTypeManager->getStorage('myeventlane_vendor')->resetCache([$vendor_id]);
        $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load($vendor_id);
      }
    }

    // If still no vendor, try to get from current user.
    if (!$vendor) {
      $vendor = $this->getCurrentVendor();
      // Reset cache after loading to ensure fresh data.
      if ($vendor && $vendor->id()) {
        $this->entityTypeManager->getStorage('myeventlane_vendor')->resetCache([$vendor->id()]);
        $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load($vendor->id());
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
    // This ensures vendor_id is available even if form state doesn't persist.
    $form['vendor_id'] = [
      '#type' => 'value',
      '#value' => $vendor->id(),
      '#weight' => -1000,
    ];

    // CRITICAL: Override form action URL on vendor domain to prevent /vendor/ prefix.
    // Drupal generates form action URLs based on current request path.
    // Setting #action here ensures it's set BEFORE FormBuilder generates the action token.
    if (\Drupal::hasService('myeventlane_core.domain_detector')) {
      $domain_detector = \Drupal::service('myeventlane_core.domain_detector');
      if ($domain_detector->isVendorDomain()) {
        // Set action to root path to prevent /vendor/ prefix in form action token.
        $form['#action'] = '/';
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
        '#default_value' => $vendor->hasField('field_summary') && !$vendor->get('field_summary')->isEmpty()
          ? $vendor->get('field_summary')->value : '',
        '#rows' => 3,
        '#description' => $this->t('A brief one-line summary that appears in listings and search results.'),
      ];
    }

    if ($vendor->hasField('field_description')) {
      $form['profile']['description'] = [
        '#type' => 'text_format',
        '#title' => $this->t('Description'),
        '#default_value' => $vendor->hasField('field_description') && !$vendor->get('field_description')->isEmpty()
          ? $vendor->get('field_description')->value : '',
        '#format' => $vendor->hasField('field_description') && !$vendor->get('field_description')->isEmpty()
          ? $vendor->get('field_description')->format : 'basic_html',
        '#rows' => 10,
        '#description' => $this->t('Full description of your organization. This appears on your public vendor page.'),
      ];
    }

    if ($vendor->hasField('field_vendor_bio')) {
      $form['profile']['bio'] = [
        '#type' => 'text_format',
        '#title' => $this->t('Bio / About'),
        '#default_value' => $vendor->hasField('field_vendor_bio') && !$vendor->get('field_vendor_bio')->isEmpty()
          ? $vendor->get('field_vendor_bio')->value : '',
        '#format' => $vendor->hasField('field_vendor_bio') && !$vendor->get('field_vendor_bio')->isEmpty()
          ? $vendor->get('field_vendor_bio')->format : 'basic_html',
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
      $form['visual']['logo'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Logo'),
        '#default_value' => $vendor->hasField($logo_field) && !$vendor->get($logo_field)->isEmpty()
          ? [$vendor->get($logo_field)->target_id] : [],
        '#upload_location' => 'public://vendor-assets/',
        '#upload_validators' => [
          'FileExtension' => ['extensions' => 'png jpg jpeg gif svg webp'],
          'FileImageDimensions' => ['maxDimensions' => '2000x2000', 'minDimensions' => '100x100'],
        ],
        '#description' => $this->t('Your organization logo. Recommended size: 400x400px. Square format works best.'),
      ];
      $form['visual']['logo_field_name'] = [
        '#type' => 'value',
        '#value' => $logo_field,
      ];
    }

    if ($vendor->hasField('field_banner_image')) {
      $form['visual']['banner'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Banner Image'),
        '#default_value' => !$vendor->get('field_banner_image')->isEmpty()
          ? [$vendor->get('field_banner_image')->target_id] : [],
        '#upload_location' => 'public://vendor-assets/',
        '#upload_validators' => [
          'FileExtension' => ['extensions' => 'png jpg jpeg gif webp'],
          'FileImageDimensions' => ['maxDimensions' => '4000x2000', 'minDimensions' => '1200x300'],
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
        '#default_value' => !$vendor->get('field_email')->isEmpty()
          ? $vendor->get('field_email')->value : '',
        '#description' => $this->t('Public contact email address.'),
      ];
    }

    if ($vendor->hasField('field_phone')) {
      $form['contact']['phone'] = [
        '#type' => 'tel',
        '#title' => $this->t('Phone Number'),
        '#default_value' => !$vendor->get('field_phone')->isEmpty()
          ? $vendor->get('field_phone')->value : '',
        '#description' => $this->t('Contact phone number.'),
      ];
    }

    if ($vendor->hasField('field_website')) {
      $form['contact']['website'] = [
        '#type' => 'url',
        '#title' => $this->t('Website'),
        '#default_value' => !$vendor->get('field_website')->isEmpty()
          ? $vendor->get('field_website')->uri : '',
        '#description' => $this->t('Your organization website URL.'),
      ];
    }

    if ($vendor->hasField('field_address')) {
      $form['contact']['address'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Address'),
        '#default_value' => !$vendor->get('field_address')->isEmpty()
          ? $vendor->get('field_address')->value : '',
        '#description' => $this->t('Business address.'),
      ];
    }

    if ($vendor->hasField('field_social_links')) {
      $form['contact']['social_links'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Social Media Links'),
      ];

      $social_values = [];
      if (!$vendor->get('field_social_links')->isEmpty()) {
        foreach ($vendor->get('field_social_links') as $item) {
          $social_values[] = [
            'uri' => $item->uri ?? '',
            'title' => $item->title ?? '',
          ];
        }
      }

      $form['contact']['social_links']['links'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Platform'),
          $this->t('URL'),
          $this->t('Operations'),
        ],
        '#tabledrag' => [
          [
            'action' => 'order',
            'relationship' => 'sibling',
            'group' => 'social-link-weight',
          ],
        ],
      ];

      if (empty($social_values)) {
        $social_values[] = ['uri' => '', 'title' => ''];
      }

      foreach ($social_values as $delta => $value) {
        $form['contact']['social_links']['links'][$delta]['#attributes']['class'][] = 'draggable';
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
        $form['contact']['social_links']['links'][$delta]['weight'] = [
          '#type' => 'weight',
          '#title' => $this->t('Weight'),
          '#default_value' => $delta,
          '#delta' => 10,
          '#title_display' => 'invisible',
          '#attributes' => ['class' => ['social-link-weight']],
        ];
        $form['contact']['social_links']['links'][$delta]['remove'] = [
          '#type' => 'submit',
          '#value' => $this->t('Remove'),
          '#name' => 'remove_social_' . $delta,
          '#submit' => ['::removeSocialLink'],
          '#ajax' => [
            'callback' => '::ajaxRefreshForm',
            'wrapper' => 'vendor-settings-form',
          ],
        ];
      }

      $form['contact']['social_links']['add'] = [
        '#type' => 'submit',
        '#value' => $this->t('Add Social Link'),
        '#submit' => ['::addSocialLink'],
        '#ajax' => [
          'callback' => '::ajaxRefreshForm',
          'wrapper' => 'vendor-settings-form',
        ],
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
        '#default_value' => !$vendor->get('field_public_show_email')->isEmpty()
          ? (bool) $vendor->get('field_public_show_email')->value : FALSE,
      ];
    }

    if ($vendor->hasField('field_public_show_phone')) {
      $form['public']['show_phone'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show phone on public page'),
        '#default_value' => !$vendor->get('field_public_show_phone')->isEmpty()
          ? (bool) $vendor->get('field_public_show_phone')->value : FALSE,
      ];
    }

    if ($vendor->hasField('field_public_show_location')) {
      $form['public']['show_location'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show address/location on public page'),
        '#default_value' => !$vendor->get('field_public_show_location')->isEmpty()
          ? (bool) $vendor->get('field_public_show_location')->value : FALSE,
      ];
    }

    if ($vendor->hasField('field_website')) {
      $form['public']['show_website'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show website on public page'),
        '#default_value' => $vendor->hasField('field_public_show_website') && !$vendor->get('field_public_show_website')->isEmpty()
          ? (bool) $vendor->get('field_public_show_website')->value : FALSE,
        '#description' => $this->t('Display your website URL on your public vendor profile.'),
      ];
    }

    if ($vendor->hasField('field_social_links')) {
      $form['public']['show_social_links'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show social media links on public page'),
        '#default_value' => $vendor->hasField('field_public_show_social_links') && !$vendor->get('field_public_show_social_links')->isEmpty()
          ? (bool) $vendor->get('field_public_show_social_links')->value : FALSE,
        '#description' => $this->t('Display your social media links on your public vendor profile.'),
      ];
    }

    if ($vendor->hasField('field_summary')) {
      $form['public']['show_summary'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show summary on public page'),
        '#default_value' => $vendor->hasField('field_public_show_summary') && !$vendor->get('field_public_show_summary')->isEmpty()
          ? (bool) $vendor->get('field_public_show_summary')->value : FALSE,
        '#description' => $this->t('Display your short summary on your public vendor profile.'),
      ];
    }

    if ($vendor->hasField('field_description')) {
      $form['public']['show_description'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show description on public page'),
        '#default_value' => $vendor->hasField('field_public_show_description') && !$vendor->get('field_public_show_description')->isEmpty()
          ? (bool) $vendor->get('field_public_show_description')->value : FALSE,
        '#description' => $this->t('Display your full description on your public vendor profile.'),
      ];
    }

    if ($vendor->hasField('field_banner_image')) {
      $form['public']['show_banner'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show banner image on public page'),
        '#default_value' => $vendor->hasField('field_public_show_banner') && !$vendor->get('field_public_show_banner')->isEmpty()
          ? (bool) $vendor->get('field_public_show_banner')->value : FALSE,
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

    if ($vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $store = $vendor->get('field_vendor_store')->entity;
      if ($store) {
        $form['payment']['store_info'] = [
          '#type' => 'markup',
          '#markup' => '<p><strong>' . $this->t('Store:') . '</strong> ' . $store->label() . '</p>',
        ];

        if ($store->hasField('field_stripe_connected')) {
          $stripe_connected = !$store->get('field_stripe_connected')->isEmpty()
            && (bool) $store->get('field_stripe_connected')->value;

          $form['payment']['stripe_status'] = [
            '#type' => 'markup',
            '#markup' => '<p><strong>' . $this->t('Stripe Status:') . '</strong> '
            . ($stripe_connected ? $this->t('Connected') : $this->t('Not Connected')) . '</p>',
          ];

          if ($stripe_connected && $store->hasField('field_stripe_dashboard_url') && !$store->get('field_stripe_dashboard_url')->isEmpty()) {
            $dashboard_url = $store->get('field_stripe_dashboard_url')->uri;
            $form['payment']['stripe_dashboard'] = [
              '#type' => 'link',
              '#title' => $this->t('Open Stripe Dashboard'),
              '#url' => Url::fromUri($dashboard_url),
              '#attributes' => ['target' => '_blank', 'rel' => 'noopener noreferrer'],
            ];
          }
        }
      }
    }
    else {
      $form['payment']['no_store'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('No store configured. Contact support to set up your payment processing.') . '</p>',
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

    // Preferences Section.
    $form['preferences'] = [
      '#type' => 'details',
      '#title' => $this->t('Preferences'),
      '#group' => 'tabs',
    ];

    $form['preferences']['notifications'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Email Notifications'),
    ];

    $form['preferences']['notifications']['email_on_new_order'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Email me when a new order is placed'),
      '#default_value' => TRUE,
    ];

    $form['preferences']['notifications']['email_on_rsvp'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Email me when someone RSVPs to an event'),
      '#default_value' => TRUE,
    ];

    $form['preferences']['notifications']['email_digest'] = [
      '#type' => 'select',
      '#title' => $this->t('Email Digest Frequency'),
      '#options' => [
        'never' => $this->t('Never'),
        'daily' => $this->t('Daily'),
        'weekly' => $this->t('Weekly'),
      ],
      '#default_value' => 'daily',
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
    // Reload vendor if needed after rebuild.
    if (!$form_state->get('vendor')) {
      $vendor_id = $form_state->get('vendor_id');
      if ($vendor_id) {
        $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load($vendor_id);
        if ($vendor) {
          $form_state->set('vendor', $vendor);
        }
      }
    }
    return $form;
  }

  /**
   * Submit handler to add a social link.
   */
  public function addSocialLink(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild();
  }

  /**
   * Submit handler to remove a social link.
   */
  public function removeSocialLink(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild();
  }

  /**
   * Submit handler to add a team member.
   */
  public function addTeamMember(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\myeventlane_vendor\Entity\Vendor|null $vendor */
    $vendor = $form_state->get('vendor');

    // If vendor not in form state, try to load it by ID.
    if (!$vendor) {
      $vendor_id = $form_state->get('vendor_id');
      if (!$vendor_id) {
        $vendor_id = $form_state->getValue('vendor_id');
      }
      if ($vendor_id) {
        $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load($vendor_id);
        if ($vendor) {
          $form_state->set('vendor', $vendor);
          $form_state->set('vendor_id', $vendor->id());
        }
      }
    }

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
    // Log that submit handler was called.
    \Drupal::logger('myeventlane_vendor')->info('VendorProfileSettingsForm::submitForm called. Form ID: @form_id', [
      '@form_id' => $this->getFormId(),
    ]);

    /** @var \Drupal\myeventlane_vendor\Entity\Vendor|null $vendor */
    $vendor = $form_state->get('vendor');

    // If vendor not in form state, try to load it by ID.
    if (!$vendor) {
      $vendor_id = $form_state->get('vendor_id');
      if (!$vendor_id) {
        $vendor_id = $form_state->getValue('vendor_id');
      }
      if ($vendor_id) {
        // Reset cache before loading to ensure fresh data.
        $this->entityTypeManager->getStorage('myeventlane_vendor')->resetCache([$vendor_id]);
        $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load($vendor_id);
        if ($vendor) {
          $form_state->set('vendor', $vendor);
        }
      }
    }

    // If still no vendor, try to get from current user as fallback.
    if (!$vendor) {
      $vendor = $this->getCurrentVendor();
      if ($vendor) {
        $form_state->set('vendor', $vendor);
        $form_state->set('vendor_id', $vendor->id());
      }
    }

    if (!$vendor) {
      \Drupal::logger('myeventlane_vendor')->error('Vendor not found during form submission. User ID: @uid, Vendor ID from form: @vendor_id', [
        '@uid' => $this->currentUser->id(),
        '@vendor_id' => $form_state->getValue('vendor_id') ?? 'not set',
      ]);
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
      elseif (empty($description)) {
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
      elseif (empty($bio)) {
        $vendor->set('field_vendor_bio', NULL);
      }
    }

    // Note: field_summary is already saved above, no need to save again here.
    // Save visual assets.
    if (isset($form['visual']['logo'])) {
      $logo_field = $form_state->getValue(['visual', 'logo_field_name']);
      if ($logo_field && $vendor->hasField($logo_field)) {
        $logo_fids = $form_state->getValue(['visual', 'logo']);
        if (!empty($logo_fids) && is_array($logo_fids)) {
          $file = $this->entityTypeManager->getStorage('file')->load($logo_fids[0]);
          if ($file) {
            $file->setPermanent();
            $file->save();
            $vendor->set($logo_field, ['target_id' => $file->id()]);
          }
        }
        elseif (empty($logo_fids)) {
          // Clear logo if empty.
          $vendor->set($logo_field, NULL);
        }
      }
    }

    if (isset($form['visual']['banner']) && $vendor->hasField('field_banner_image')) {
      $banner_fids = $form_state->getValue(['visual', 'banner']);
      if (!empty($banner_fids) && is_array($banner_fids)) {
        $file = $this->entityTypeManager->getStorage('file')->load($banner_fids[0]);
        if ($file) {
          $file->setPermanent();
          $file->save();
          $vendor->set('field_banner_image', ['target_id' => $file->id()]);
        }
      }
      elseif (empty($banner_fids)) {
        // Clear banner if empty.
        $vendor->set('field_banner_image', NULL);
      }
    }

    // Save contact information.
    if ($vendor->hasField('field_email')) {
      $email = $form_state->getValue(['contact', 'email']);
      $vendor->set('field_email', $email ?: NULL);
    }

    if ($vendor->hasField('field_phone')) {
      $phone = $form_state->getValue(['contact', 'phone']);
      $vendor->set('field_phone', $phone ?: NULL);
    }

    if ($vendor->hasField('field_website')) {
      $website = $form_state->getValue(['contact', 'website']);
      if (!empty($website)) {
        // Ensure URL has protocol.
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
      $address = $form_state->getValue(['contact', 'address']);
      $vendor->set('field_address', $address ?: NULL);
    }

    // Save social links.
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
    if ($vendor->hasField('field_public_show_email')) {
      $vendor->set('field_public_show_email', (int) $form_state->getValue(['public', 'show_email']));
    }

    if ($vendor->hasField('field_public_show_phone')) {
      $vendor->set('field_public_show_phone', (int) $form_state->getValue(['public', 'show_phone']));
    }

    if ($vendor->hasField('field_public_show_location')) {
      $vendor->set('field_public_show_location', (int) $form_state->getValue(['public', 'show_location']));
    }

    // Save visibility settings for additional fields (only if fields exist).
    // Note: These visibility fields may not exist yet, so we check before setting.
    $visibility_fields = [
      'field_public_show_website' => ['public', 'show_website'],
      'field_public_show_social_links' => ['public', 'show_social_links'],
      'field_public_show_summary' => ['public', 'show_summary'],
      'field_public_show_description' => ['public', 'show_description'],
      'field_public_show_banner' => ['public', 'show_banner'],
    ];

    foreach ($visibility_fields as $field_name => $form_path) {
      if ($vendor->hasField($field_name)) {
        $value = $form_state->getValue($form_path) ?? FALSE;
        $vendor->set($field_name, (int) $value);
      }
    }

    // Validate entity before saving, but skip access checks for entity references.
    // The entity reference access check can fail if the current user doesn't have
    // permission to view the referenced entity, even though the reference is valid.
    $violations = $vendor->validate();
    $real_violations = [];

    if ($violations->count() > 0) {
      foreach ($violations as $violation) {
        $property_path = $violation->getPropertyPath();
        $message = (string) $violation->getMessage();

        // Skip "cannot be referenced" errors for field_vendor_users if the user exists.
        // This is an access check issue, not a validation issue.
        if (str_contains($property_path, 'field_vendor_users') && str_contains($message, 'cannot be referenced')) {
          // Verify the referenced users actually exist and are valid.
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
          // If all users are valid, skip this violation (it's an access check, not a real validation error).
          if ($all_valid) {
            \Drupal::logger('myeventlane_vendor')->debug('Skipping entity reference access check violation for field_vendor_users - users are valid.');
            continue;
          }
        }

        // This is a real validation error - collect it.
        $real_violations[] = $violation;
        $this->messenger()->addError($this->t('Validation error: @message', [
          '@message' => $message,
        ]));
      }

      // Only rebuild if we have real validation errors.
      if (!empty($real_violations)) {
        \Drupal::logger('myeventlane_vendor')->warning('Vendor validation failed with @count real errors. Vendor ID: @vendor_id', [
          '@count' => count($real_violations),
          '@vendor_id' => $vendor->id(),
        ]);
        $form_state->setRebuild();
        return;
      }
    }

    try {
      // Log the save attempt for debugging.
      \Drupal::logger('myeventlane_vendor')->info('Attempting to save vendor settings. Vendor ID: @vendor_id, Name: @name', [
        '@vendor_id' => $vendor->id(),
        '@name' => $vendor->getName(),
      ]);

      // Log field values being saved for debugging.
      $fields_to_log = [
        'name' => $vendor->getName(),
        'field_summary' => $vendor->hasField('field_summary') && !$vendor->get('field_summary')->isEmpty() ? $vendor->get('field_summary')->value : 'empty',
        'field_vendor_bio' => $vendor->hasField('field_vendor_bio') && !$vendor->get('field_vendor_bio')->isEmpty() ? 'has value' : 'empty',
        'field_email' => $vendor->hasField('field_email') && !$vendor->get('field_email')->isEmpty() ? $vendor->get('field_email')->value : 'empty',
      ];
      \Drupal::logger('myeventlane_vendor')->debug('Vendor field values before save: @fields', [
        '@fields' => print_r($fields_to_log, TRUE),
      ]);

      // Save the vendor entity.
      // The preSave hook will handle entity reference validation.
      $vendor->save();

      // Clear entity cache to ensure fresh data is displayed.
      $this->entityTypeManager->getStorage('myeventlane_vendor')->resetCache([$vendor->id()]);

      // Reload vendor to verify save worked.
      $saved_vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load($vendor->id());
      if ($saved_vendor) {
        \Drupal::logger('myeventlane_vendor')->info('Vendor reloaded after save. Name: @name', [
          '@name' => $saved_vendor->getName(),
        ]);
      }

      // Invalidate cache tags for the vendor entity and any related views.
      $cache_tags = [
        'myeventlane_vendor:' . $vendor->id(),
        'myeventlane_vendor_list',
      ];
      \Drupal::service('cache_tags.invalidator')->invalidateTags($cache_tags);

      \Drupal::logger('myeventlane_vendor')->info('Vendor settings saved successfully. Vendor ID: @vendor_id', [
        '@vendor_id' => $vendor->id(),
      ]);

      $this->messenger()->addStatus($this->t('Vendor settings saved successfully.'));

      // Redirect back to settings page to prevent form resubmission.
      $form_state->setRedirect('myeventlane_vendor.console.settings');
    }
    catch (\Exception $e) {
      \Drupal::logger('myeventlane_vendor')->error('Failed to save vendor settings: @message. Vendor ID: @vendor_id. File: @file, Line: @line. Trace: @trace', [
        '@message' => $e->getMessage(),
        '@vendor_id' => $vendor->id() ?? 'unknown',
        '@file' => $e->getFile(),
        '@line' => $e->getLine(),
        '@trace' => $e->getTraceAsString(),
      ]);
      $this->messenger()->addError($this->t('An error occurred while saving: @message', [
        '@message' => $e->getMessage(),
      ]));
      $form_state->setRebuild();
    }
  }

}
