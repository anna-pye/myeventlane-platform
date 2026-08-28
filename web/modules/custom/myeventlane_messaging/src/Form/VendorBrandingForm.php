<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Form;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQuery;
use Drupal\myeventlane_vendor\Service\VendorBrandMediaManager;
use Drupal\myeventlane_vendor\Service\VendorImageFieldPolicy;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Vendor messaging branding — edits canonical vendor entity fields only.
 *
 * The organiser profile settings form owns the full profile workflow.
 * This lightweight form deliberately duplicates none of that logic.
 * long-form profile copy belongs on /vendor/settings (single source of truth).
 */
final class VendorBrandingForm extends FormBase {

  private const DEFAULT_PRIMARY_COLOR = '#6b46ff';

  /**
   * Constructs the vendor branding form.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected LoggerInterface $logger,
    protected CurrentVendorResolverInterface $vendorResolver,
    protected UserVendorMembershipQuery $userVendorMembershipQuery,
    protected VendorBrandMediaManager $brandMediaManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('cache_tags.invalidator'),
      $container->get('logger.channel.myeventlane_messaging'),
      $container->get('myeventlane_vendor.current_vendor_resolver'),
      $container->get('myeventlane_vendor.user_vendor_membership_query'),
      $container->get('myeventlane_vendor.brand_media_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_messaging_vendor_branding_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?Vendor $vendor = NULL, ?string $action = NULL): array {
    $vendor = $vendor ?? $this->loadCurrentVendor();

    $form['#tree'] = TRUE;
    $form['#action'] = $action ?: $this->getRequest()->getRequestUri();
    $form['#attributes']['class'][] = 'mel-vendor-brand-v2';
    $form['#attributes']['class'][] = 'mel-no-ajax';
    $form['#attached']['library'][] = 'myeventlane_messaging/vendor_branding';
    $form['#attached']['library'][] = 'myeventlane_vendor_theme/global-styling';

    if (!$vendor instanceof Vendor || !$this->currentUserCanEditVendor($vendor)) {
      $this->logger->error('Vendor branding form could not be built for user @uid.', [
        '@uid' => (string) $this->currentUser->id(),
      ]);

      $form['branding'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-card']],
        'error' => [
          '#markup' => '<p>' . $this->t('Vendor not found. Please contact support.') . '</p>',
        ],
      ];
      return $form;
    }

    $form['vendor_id'] = [
      '#type' => 'hidden',
      '#value' => (int) $vendor->id(),
    ];

    $form['logo_field_name'] = [
      '#type' => 'value',
      '#value' => $this->getLogoFieldName($vendor),
    ];

    $form['branding'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-card', 'mel-vendor-branding', 'mel-vendor-brand-v2__assets']],
    ];

    $form['branding']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Your message branding'),
      '#attributes' => ['class' => ['mel-vendor-brand-v2__title']],
    ];

    $form['branding']['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['mel-vendor-branding__intro', 'mel-vendor-brand-v2__section-lede']],
      '#value' => $this->t('Set the reusable look for emails sent from MyEventLane. These assets are shared with your organiser profile, so you only need to maintain them in one place.'),
    ];

    $form['branding']['profile_link'] = [
      '#type' => 'link',
      '#title' => $this->t('← Back to Account settings'),
      '#url' => Url::fromRoute('myeventlane_vendor.console.settings'),
      '#attributes' => ['class' => ['mel-vendor-brand-v2__header-link']],
    ];

    if ($form['logo_field_name']['#value'] !== '') {
      $logo_default = $this->getManagedFileDefaultValue(
        $vendor,
        $form['logo_field_name']['#value'],
        VendorBrandMediaManager::ASSET_EMAIL_LOGO,
      );
      $form['branding']['logo_preview'] = $this->buildAssetPreview(
        $logo_default,
        (string) $this->t('@name logo preview', ['@name' => $vendor->label()]),
        'logo',
      );
      $form['branding']['logo'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Email logo override'),
        '#upload_location' => 'public://vendor_logos/',
        '#upload_validators' => [
          'FileExtension' => ['extensions' => 'png jpg jpeg gif webp'],
          'FileSizeLimit' => ['fileLimit' => 5 * 1024 * 1024],
        ],
        '#default_value' => $logo_default,
        '#description' => $this->t('Optional. Used in message headers instead of your organiser logo. Square PNG, JPG or WebP works best; maximum 5 MB.'),
        '#wrapper_attributes' => ['class' => ['mel-vendor-brand-v2__asset-field']],
      ];
    }

    if ($vendor->hasField('field_banner_image')) {
      $banner_default = $this->getManagedFileDefaultValue(
        $vendor,
        'field_banner_image',
        VendorBrandMediaManager::ASSET_BANNER,
      );
      $form['branding']['banner_preview'] = $this->buildAssetPreview(
        $banner_default,
        (string) $this->t('@name banner preview', ['@name' => $vendor->label()]),
        'banner',
      );
      $form['branding']['banner'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Banner image'),
        '#upload_location' => 'public://vendor_banners/',
        '#upload_validators' => [
          'FileExtension' => ['extensions' => 'png jpg jpeg gif webp'],
          'FileSizeLimit' => ['fileLimit' => 10 * 1024 * 1024],
        ],
        '#default_value' => $banner_default,
        '#description' => $this->t('A wide PNG, JPG or WebP works best. Maximum 10 MB. This adds personality to supported email layouts and your organiser profile.'),
        '#wrapper_attributes' => ['class' => ['mel-vendor-brand-v2__asset-field']],
      ];
    }

    if ($vendor->hasField('field_accent_colour') || $vendor->hasField('field_msg_accent_color')) {
      $form['branding']['primary_color'] = [
        '#type' => 'color',
        '#title' => $this->t('Accent colour'),
        '#default_value' => $this->getPrimaryColor($vendor),
        '#description' => $this->t('Used for buttons and highlights in customer messages. Choose a colour with strong contrast so text remains easy to read.'),
        '#attributes' => ['class' => ['mel-vendor-brand-v2__colour']],
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['mel-vendor-brand-v2__actions']],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save branding'),
      '#attributes' => ['class' => ['mel-button', 'mel-button--primary', 'mel-btn', 'mel-btn--primary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $vendor = $this->loadSubmittedVendor($form_state);
    if (!$vendor instanceof Vendor || !$this->currentUserCanEditVendor($vendor)) {
      $form_state->setErrorByName('vendor_id', $this->t('Vendor not found.'));
      $this->logger->error('Vendor branding form failed access validation for user @uid and vendor @vendor_id.', [
        '@uid' => (string) $this->currentUser->id(),
        '@vendor_id' => (string) $form_state->getValue('vendor_id'),
      ]);
      return;
    }

    if (!$this->currentUser->hasPermission('administer myeventlane vendor')) {
      $submitted_vid = (int) ($form_state->getValue('vendor_id') ?? 0);
      $allowed_vids = $this->userVendorMembershipQuery->getVendorIdsForUser((int) $this->currentUser->id());
      if (!$submitted_vid || !in_array($submitted_vid, $allowed_vids, TRUE)) {
        $this->logger->warning('Vendor branding: rejected vendor_id uid=@uid vid=@vid', [
          '@uid' => (string) $this->currentUser->id(),
          '@vid' => (string) $submitted_vid,
        ]);
        $form_state->setErrorByName('vendor_id', $this->t('We could not verify your organiser account for this save. Please reload the page and try again.'));
        return;
      }
    }

    $values = $form_state->getValue('branding');
    if (!is_array($values)) {
      $form_state->setErrorByName('branding', $this->t('Branding values were missing.'));
      $this->logger->error('Vendor branding form submitted without tree-structured branding values for vendor @vendor_id.', [
        '@vendor_id' => (string) $vendor->id(),
      ]);
      return;
    }

    $primary_color = $values['primary_color'] ?? self::DEFAULT_PRIMARY_COLOR;
    if (!is_scalar($primary_color) || !preg_match('/^#[0-9a-fA-F]{6}$/', (string) $primary_color)) {
      $form_state->setErrorByName('branding][primary_color', $this->t('Enter a valid hex colour.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $vendor = $this->loadSubmittedVendor($form_state);
    $values = $form_state->getValue('branding');

    if (!$vendor instanceof Vendor || !$this->currentUserCanEditVendor($vendor) || !is_array($values)) {
      $this->logger->error('Vendor branding form submit failed for user @uid and vendor @vendor_id.', [
        '@uid' => (string) $this->currentUser->id(),
        '@vendor_id' => (string) $form_state->getValue('vendor_id'),
      ]);
      $this->messenger()->addError($this->t('Branding could not be saved. Please contact support.'));
      return;
    }

    try {
      $logo_field_name = (string) $form_state->getValue('logo_field_name', '');
      $this->saveManagedFileField($vendor, $logo_field_name, $values['logo'] ?? [], 'logo');
      $this->saveManagedFileField($vendor, 'field_banner_image', $values['banner'] ?? [], 'banner');
      $this->savePrimaryColor($vendor, $values['primary_color'] ?? self::DEFAULT_PRIMARY_COLOR);

      $this->brandMediaManager->synchroniseFromLegacy($vendor, [
        VendorBrandMediaManager::ASSET_EMAIL_LOGO,
        VendorBrandMediaManager::ASSET_BANNER,
      ]);

      $vendor->save();

      $this->cacheTagsInvalidator->invalidateTags([
        'myeventlane_vendor:' . $vendor->id(),
        'myeventlane_vendor_list',
      ]);

      $this->messenger()->addStatus($this->t('Branding saved successfully.'));
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to save vendor branding for vendor @vendor_id: @message', [
        '@vendor_id' => (string) $vendor->id(),
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Branding could not be saved. Please try again.'));
    }
  }

  /**
   * Loads the current user's vendor entity.
   */
  private function loadCurrentVendor(): ?Vendor {
    $resolved = $this->vendorResolver->resolveFromCurrentUser();
    if ($resolved instanceof Vendor) {
      return $resolved;
    }

    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      $ids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('field_vendor_users', $uid)
        ->range(0, 1)
        ->execute();
    }

    if (empty($ids)) {
      return NULL;
    }

    $vendor = $storage->load(reset($ids));
    return $vendor instanceof Vendor ? $vendor : NULL;
  }

  /**
   * Loads the submitted vendor entity.
   */
  private function loadSubmittedVendor(FormStateInterface $form_state): ?Vendor {
    $vendor_id = (int) $form_state->getValue('vendor_id');
    if ($vendor_id <= 0) {
      return NULL;
    }

    $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load($vendor_id);
    return $vendor instanceof Vendor ? $vendor : NULL;
  }

  /**
   * Checks whether the active account can edit this vendor branding.
   */
  private function currentUserCanEditVendor(Vendor $vendor): bool {
    if ($this->currentUser->hasPermission('administer myeventlane vendor') || (int) $this->currentUser->id() === 1) {
      return TRUE;
    }

    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return FALSE;
    }

    if ((int) $vendor->getOwnerId() === $uid) {
      return TRUE;
    }

    if (!$vendor->hasField('field_vendor_users') || $vendor->get('field_vendor_users')->isEmpty()) {
      return FALSE;
    }

    foreach ($vendor->get('field_vendor_users')->getValue() as $item) {
      if ((int) ($item['target_id'] ?? 0) === $uid) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Finds the vendor logo field this form can save.
   */
  private function getLogoFieldName(Vendor $vendor): string {
    return VendorImageFieldPolicy::emailOverrideField($vendor);
  }

  /**
   * Gets the Media-first managed_file default for a retained direct field.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The organiser whose asset is displayed.
   * @param string $field_name
   *   Retained direct-file field used when no Media reference exists.
   * @param string $asset_type
   *   Canonical Media asset type.
   *
   * @return array<int>
   *   File IDs for the managed file element.
   */
  private function getManagedFileDefaultValue(Vendor $vendor, string $field_name, string $asset_type): array {
    $mediaFile = $this->brandMediaManager->fileForAsset($vendor, $asset_type);
    if ($mediaFile !== NULL) {
      return [(int) $mediaFile->id()];
    }

    if ($field_name === '' || !$vendor->hasField($field_name) || $vendor->get($field_name)->isEmpty()) {
      return [];
    }

    $target_id = $vendor->get($field_name)->target_id;
    return $target_id ? [(int) $target_id] : [];
  }

  /**
   * Builds a visual preview for a saved branding asset.
   *
   * @param array<int> $file_ids
   *   Managed file IDs.
   * @param string $alt
   *   Accessible preview description.
   * @param string $variant
   *   Preview shape, either logo or banner.
   *
   * @return array<string, mixed>
   *   Image render array, or an empty array when no file is saved.
   */
  private function buildAssetPreview(array $file_ids, string $alt, string $variant): array {
    if (empty($file_ids[0])) {
      return [];
    }

    $file = $this->entityTypeManager->getStorage('file')->load((int) $file_ids[0]);
    if (!$file instanceof FileInterface) {
      return [];
    }

    return [
      '#theme' => 'image',
      '#uri' => $file->getFileUri(),
      '#alt' => $alt,
      '#attributes' => [
        'class' => [
          'mel-vendor-brand-v2__preview',
          'mel-vendor-brand-v2__preview--' . $variant,
        ],
      ],
    ];
  }

  /**
   * Gets the saved primary colour for the colour picker.
   */
  private function getPrimaryColor(Vendor $vendor): string {
    $value = $this->getScalarFieldValue($vendor, 'field_accent_colour');
    if ($value === '') {
      $value = $this->getScalarFieldValue($vendor, 'field_msg_accent_color');
    }

    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : self::DEFAULT_PRIMARY_COLOR;
  }

  /**
   * Gets a scalar field value.
   */
  private function getScalarFieldValue(Vendor $vendor, string $field_name): string {
    if (!$vendor->hasField($field_name) || $vendor->get($field_name)->isEmpty()) {
      return '';
    }

    $value = $vendor->get($field_name)->value;
    return is_scalar($value) ? trim((string) $value) : '';
  }

  /**
   * Saves a managed_file field value and marks the file permanent.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The organiser being updated.
   * @param string $field_name
   *   The retained direct-file field name.
   * @param array<mixed>|mixed $file_ids
   *   Managed file element value.
   * @param string $asset_type
   *   Asset label used for logging.
   */
  private function saveManagedFileField(Vendor $vendor, string $field_name, mixed $file_ids, string $asset_type): void {
    if ($field_name === '' || !$vendor->hasField($field_name)) {
      return;
    }

    if (!is_array($file_ids) || empty($file_ids[0])) {
      $vendor->set($field_name, NULL);
      return;
    }

    $file = $this->entityTypeManager->getStorage('file')->load((int) $file_ids[0]);
    if (!$file instanceof FileInterface) {
      $this->logger->error('Vendor branding @asset_type file @fid could not be loaded for vendor @vendor_id.', [
        '@asset_type' => $asset_type,
        '@fid' => (string) $file_ids[0],
        '@vendor_id' => (string) $vendor->id(),
      ]);
      return;
    }

    $file->setPermanent();
    $file->save();

    $value = ['target_id' => (int) $file->id()];
    if ($field_name === 'field_banner_image') {
      $value['alt'] = $vendor->label() . ' banner';
    }

    $vendor->set($field_name, $value);
  }

  /**
   * Saves the primary colour to the vendor entity.
   */
  private function savePrimaryColor(Vendor $vendor, mixed $value): void {
    $color = is_scalar($value) ? strtolower(trim((string) $value)) : self::DEFAULT_PRIMARY_COLOR;
    if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
      $color = self::DEFAULT_PRIMARY_COLOR;
    }

    if ($vendor->hasField('field_accent_colour')) {
      $vendor->set('field_accent_colour', $color);
    }

    if ($vendor->hasField('field_msg_accent_color')) {
      $allowed = $this->getMessagingAccentColorValues($vendor);
      if ($allowed === [] || in_array($color, $allowed, TRUE)) {
        $vendor->set('field_msg_accent_color', $color);
      }
    }
  }

  /**
   * Gets configured allowed values for the restricted messaging accent field.
   *
   * @return array<string>
   *   Allowed hex colour values.
   */
  private function getMessagingAccentColorValues(Vendor $vendor): array {
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
