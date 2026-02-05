<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_messaging\Service\BrandResolverInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for vendor-level messaging brand configuration.
 *
 * Saves brand settings to vendor entity fields (field_msg_*).
 * Used from vendor console at /vendor/dashboard/messaging/brand.
 */
final class VendorBrandConfigForm extends FormBase {

  /**
   * Entity type manager.
   */
  protected ?EntityTypeManagerInterface $entityTypeManager = NULL;

  /**
   * File URL generator.
   */
  protected ?FileUrlGeneratorInterface $fileUrlGenerator = NULL;

  /**
   * Current user.
   */
  protected ?AccountProxyInterface $currentUser = NULL;

  /**
   * Brand resolver.
   */
  protected ?BrandResolverInterface $brandResolver = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->fileUrlGenerator = $container->get('file_url_generator');
    $instance->currentUser = $container->get('current_user');
    $instance->brandResolver = $container->get('myeventlane_messaging.vendor_brand_resolver');
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
   * Gets the file URL generator with lazy loading fallback.
   */
  protected function getFileUrlGenerator(): FileUrlGeneratorInterface {
    if ($this->fileUrlGenerator === NULL) {
      $this->fileUrlGenerator = \Drupal::service('file_url_generator');
    }
    return $this->fileUrlGenerator;
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
   * Gets the brand resolver with lazy loading fallback.
   */
  protected function getBrandResolver(): ?BrandResolverInterface {
    if ($this->brandResolver === NULL && \Drupal::hasService('myeventlane_messaging.vendor_brand_resolver')) {
      $this->brandResolver = \Drupal::service('myeventlane_messaging.vendor_brand_resolver');
    }
    return $this->brandResolver;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_messaging_vendor_brand_config_form';
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
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor|null $vendor
   *   Vendor entity passed from vendor console controller.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $vendor = NULL): array {
    if (!$vendor instanceof Vendor) {
      $form['error'] = [
        '#markup' => $this->t('Vendor not found.'),
      ];
      return $form;
    }

    $vendor_id = (int) $vendor->id();
    $form_state->set('vendor_id', $vendor_id);
    $form_state->set('vendor', $vendor);

    $form['vendor_id'] = [
      '#type' => 'value',
      '#value' => $vendor_id,
    ];

    // Sender Identity section.
    $form['sender'] = [
      '#type' => 'details',
      '#title' => $this->t('Sender Identity'),
      '#open' => TRUE,
      '#description' => $this->t('These settings control how your emails appear to recipients.'),
    ];

    $form['sender']['from_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('From name'),
      '#description' => $this->t('Display name for outgoing emails (e.g. your organisation name).'),
      '#default_value' => $this->getFieldValue($vendor, 'field_msg_from_name', $vendor->getName()),
      '#maxlength' => 255,
    ];

    $form['sender']['from_email'] = [
      '#type' => 'email',
      '#title' => $this->t('From email'),
      '#description' => $this->t('Email address for outgoing emails. Leave blank to use platform default.'),
      '#default_value' => $this->getFieldValue($vendor, 'field_msg_from_email', ''),
    ];

    $form['sender']['reply_to'] = [
      '#type' => 'email',
      '#title' => $this->t('Reply-to email'),
      '#description' => $this->t('Where replies should go. Leave blank to use the from email.'),
      '#default_value' => $this->getFieldValue($vendor, 'field_msg_reply_to', ''),
    ];

    // Visual Branding section.
    $form['branding'] = [
      '#type' => 'details',
      '#title' => $this->t('Visual Branding'),
      '#open' => TRUE,
      '#description' => $this->t('Customise the look of your outgoing emails.'),
    ];

    // Logo upload.
    $logo_default = [];
    if ($vendor->hasField('field_msg_logo') && !$vendor->get('field_msg_logo')->isEmpty()) {
      $logo_default = [$vendor->get('field_msg_logo')->target_id];
    }

    $form['branding']['logo'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Email Logo'),
      '#description' => $this->t('Logo displayed in email header. Recommended: 200x50px PNG or JPG.'),
      '#default_value' => $logo_default,
      '#upload_location' => 'public://vendor-email-logos/',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg gif webp'],
        'file_validate_image_resolution' => ['800x400', '50x20'],
      ],
    ];

    // Accent colour dropdown with MEL palette.
    $accent_options = [
      '#f26d5b' => $this->t('Coral (MEL Primary)'),
      '#6e7ef2' => $this->t('Lavender'),
      '#4f9da6' => $this->t('Teal'),
      '#5b8c5a' => $this->t('Forest'),
      '#e8a838' => $this->t('Amber'),
      '#8b5cf6' => $this->t('Violet'),
      '#293241' => $this->t('Charcoal'),
    ];

    $form['branding']['accent_color'] = [
      '#type' => 'select',
      '#title' => $this->t('Accent colour'),
      '#description' => $this->t('Colour for buttons and links in emails.'),
      '#options' => $accent_options,
      '#default_value' => $this->getFieldValue($vendor, 'field_msg_accent_color', '#f26d5b'),
    ];

    // Footer section.
    $form['footer'] = [
      '#type' => 'details',
      '#title' => $this->t('Email Footer'),
      '#open' => TRUE,
    ];

    $form['footer']['footer_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Footer text'),
      '#description' => $this->t('Custom text shown in email footer. Leave blank for default.'),
      '#default_value' => $this->getFieldValue($vendor, 'field_msg_footer', ''),
      '#rows' => 2,
    ];

    // Preview section.
    $form['preview'] = [
      '#type' => 'details',
      '#title' => $this->t('Preview'),
      '#description' => $this->t('Save your settings, then send a test email to see how they appear.'),
    ];

    $form['preview']['send_test'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send test email'),
      '#submit' => ['::sendTestEmail'],
      '#limit_validation_errors' => [['vendor_id']],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save brand settings'),
        '#button_type' => 'primary',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate email addresses.
    $from_email = trim((string) $form_state->getValue('from_email'));
    if (!empty($from_email) && !filter_var($from_email, FILTER_VALIDATE_EMAIL)) {
      $form_state->setError($form['sender']['from_email'], $this->t('Please enter a valid email address.'));
    }

    $reply_to = trim((string) $form_state->getValue('reply_to'));
    if (!empty($reply_to) && !filter_var($reply_to, FILTER_VALIDATE_EMAIL)) {
      $form_state->setError($form['sender']['reply_to'], $this->t('Please enter a valid reply-to email address.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $vendor_id = $form_state->getValue('vendor_id');
    if (!$vendor_id) {
      $this->messenger()->addError($this->t('Vendor not found.'));
      return;
    }

    $vendor = $this->getEntityTypeManager()->getStorage('myeventlane_vendor')->load($vendor_id);
    if (!$vendor instanceof Vendor) {
      $this->messenger()->addError($this->t('Vendor not found.'));
      return;
    }

    // Save sender identity.
    if ($vendor->hasField('field_msg_from_name')) {
      $vendor->set('field_msg_from_name', trim((string) $form_state->getValue('from_name')));
    }
    if ($vendor->hasField('field_msg_from_email')) {
      $vendor->set('field_msg_from_email', trim((string) $form_state->getValue('from_email')) ?: NULL);
    }
    if ($vendor->hasField('field_msg_reply_to')) {
      $vendor->set('field_msg_reply_to', trim((string) $form_state->getValue('reply_to')) ?: NULL);
    }

    // Save logo.
    if ($vendor->hasField('field_msg_logo')) {
      $logo_fids = $form_state->getValue('logo');
      if (!empty($logo_fids) && is_array($logo_fids)) {
        $file = $this->getEntityTypeManager()->getStorage('file')->load($logo_fids[0]);
        if ($file) {
          $file->setPermanent();
          $file->save();
          $vendor->set('field_msg_logo', ['target_id' => $file->id()]);
        }
      }
      else {
        $vendor->set('field_msg_logo', NULL);
      }
    }

    // Save accent colour.
    if ($vendor->hasField('field_msg_accent_color')) {
      $vendor->set('field_msg_accent_color', $form_state->getValue('accent_color') ?: '#f26d5b');
    }

    // Save footer text.
    if ($vendor->hasField('field_msg_footer')) {
      $vendor->set('field_msg_footer', trim((string) $form_state->getValue('footer_text')) ?: NULL);
    }

    try {
      $vendor->save();

      // Invalidate cache tags.
      $cache_tags = [
        'myeventlane_vendor:' . $vendor->id(),
        'myeventlane_vendor_list',
      ];
      \Drupal::service('cache_tags.invalidator')->invalidateTags($cache_tags);

      $this->messenger()->addStatus($this->t('Brand settings saved.'));
    }
    catch (\Exception $e) {
      \Drupal::logger('myeventlane_messaging')->error('Failed to save brand settings: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('An error occurred while saving.'));
    }
  }

  /**
   * Send test email handler.
   */
  public function sendTestEmail(array &$form, FormStateInterface $form_state): void {
    $vendor_id = $form_state->getValue('vendor_id');
    \Drupal::logger('myeventlane_messaging')->info('sendTestEmail called, vendor_id: @vid', [
      '@vid' => $vendor_id ?? 'NULL',
    ]);

    if (!$vendor_id) {
      $this->messenger()->addError($this->t('Vendor not found.'));
      return;
    }

    $vendor = $this->getEntityTypeManager()->getStorage('myeventlane_vendor')->load($vendor_id);
    if (!$vendor instanceof Vendor) {
      $this->messenger()->addError($this->t('Vendor not found.'));
      return;
    }

    // Get current user's email.
    $currentUser = $this->getCurrentUser();
    $userAccount = $this->getEntityTypeManager()->getStorage('user')->load($currentUser->id());
    if (!$userAccount) {
      $this->messenger()->addError($this->t('Current user not found.'));
      return;
    }

    $userEmail = $userAccount->getEmail();
    if (empty($userEmail)) {
      $this->messenger()->addError($this->t('Your account does not have an email address.'));
      return;
    }

    try {
      // Get brand from resolver.
      $brandResolver = $this->getBrandResolver();
      $brand = $brandResolver ? $brandResolver->resolveForVendor($vendor) : NULL;
      $brandArray = $brand ? $brand->toArray() : [];

      $recipientName = $userAccount->getDisplayName();
      $vendorName = $vendor->getName();

      // Build test email body.
      $innerBody = '<p>Hi ' . htmlspecialchars($recipientName) . ',</p>';
      $innerBody .= '<p>This is a test email to show how your brand settings appear in outgoing emails from <strong>' . htmlspecialchars($vendorName) . '</strong>.</p>';
      $innerBody .= '<p>Your accent colour, logo, and footer text are applied to this email.</p>';
      $innerBody .= '<p style="text-align: center; margin: 2rem 0;"><a href="https://myeventlane.com.au" class="mel-btn">Sample Button</a></p>';
      $innerBody .= '<p>If everything looks good, your emails to customers will use these same brand settings.</p>';

      $context = array_merge($brandArray, [
        'vendor_id' => $vendor_id,
        'preheader' => (string) $this->t('This is a test email to preview your brand settings.'),
      ]);

      // Render email through the template.
      $renderer = \Drupal::service('renderer');
      $build = [
        '#theme' => 'myeventlane_email',
        '#body' => $innerBody,
        '#context' => $context,
      ];
      $html = (string) $renderer->renderInIsolation($build);

      // Send via mail manager.
      $mailManager = \Drupal::service('plugin.manager.mail');
      $module = 'myeventlane_messaging';
      $key = 'brand_test';
      $langcode = $currentUser->getPreferredLangcode();

      $params = [
        'subject' => $this->t('Test email - Brand preview for @vendor', [
          '@vendor' => $vendorName,
        ]),
        'html' => $html,
        'from_name' => $brandArray['from_name'] ?? 'MyEventLane',
        'from_email' => $brandArray['from_email'] ?? '',
        'reply_to' => $brandArray['reply_to'] ?? '',
      ];

      $result = $mailManager->mail($module, $key, $userEmail, $langcode, $params, NULL, TRUE);

      if ($result['result'] === TRUE) {
        $this->messenger()->addStatus($this->t('Test email sent to @email.', [
          '@email' => $userEmail,
        ]));
      }
      else {
        $this->messenger()->addWarning($this->t('Test email may not have been sent. Please check your mail configuration.'));
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('myeventlane_messaging')->error('Failed to send test email: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Failed to send test email: @message', [
        '@message' => $e->getMessage(),
      ]));
    }
  }

}
