<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for vendor branding/image uploads during onboarding.
 */
final class VendorBrandingForm extends FormBase {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'vendor_branding_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?Vendor $vendor = NULL): array {
    if (!$vendor) {
      return $form;
    }

    $form['#vendor'] = $vendor;
    $form['#tree'] = TRUE;

    $form['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['description']],
      '#value' => $this->t('Add visual branding to your organiser page. These images help attendees recognize and trust your brand.'),
    ];

    // Logo field.
    if ($vendor->hasField('field_vendor_logo') || $vendor->hasField('field_logo_image')) {
      $logo_field = $vendor->hasField('field_vendor_logo') ? 'field_vendor_logo' : 'field_logo_image';
      $existing_logo_fid = NULL;
      if ($vendor->hasField($logo_field) && !$vendor->get($logo_field)->isEmpty()) {
        $existing_logo_fid = $vendor->get($logo_field)->target_id;
      }

      $form['logo'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Logo'),
        '#default_value' => $existing_logo_fid ? [$existing_logo_fid] : [],
        '#upload_location' => 'public://vendor-assets/',
        '#upload_validators' => [
          'FileExtension' => ['extensions' => 'png jpg jpeg gif svg webp'],
          'FileImageDimensions' => ['maxDimensions' => '2000x2000', 'minDimensions' => '100x100'],
        ],
        '#description' => $this->t('Your organisation logo. Recommended size: 400x400px. Square format works best.'),
      ];
      $form['logo_field_name'] = [
        '#type' => 'value',
        '#value' => $logo_field,
      ];
    }

    // Banner image field.
    if ($vendor->hasField('field_banner_image')) {
      $existing_banner_fid = NULL;
      if (!$vendor->get('field_banner_image')->isEmpty()) {
        $existing_banner_fid = $vendor->get('field_banner_image')->target_id;
      }

      $form['banner'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Banner Image'),
        '#default_value' => $existing_banner_fid ? [$existing_banner_fid] : [],
        '#upload_location' => 'public://vendor-assets/',
        '#upload_validators' => [
          'FileExtension' => ['extensions' => 'png jpg jpeg gif webp'],
          'FileImageDimensions' => ['maxDimensions' => '4000x2000', 'minDimensions' => '1200x300'],
        ],
        '#description' => $this->t('Banner image for your vendor page. Recommended size: 1920x400px.'),
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Continue'),
      '#button_type' => 'primary',
    ];

    $form['actions']['skip'] = [
      '#type' => 'link',
      '#title' => $this->t('Skip for now'),
      '#url' => \Drupal\Core\Url::fromRoute('myeventlane_vendor.onboard.first_event'),
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn-secondary'],
      ],
    ];

    $form['#attributes']['class'][] = 'mel-onboard-form';
    $form['#attributes']['class'][] = 'mel-onboard-branding-form';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    // File validation is handled by upload_validators.
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\myeventlane_vendor\Entity\Vendor $vendor */
    $vendor = $form['#vendor'];
    if (!$vendor) {
      return;
    }

    // Save logo.
    if (isset($form['logo'])) {
      $logo_field = $form_state->getValue(['logo_field_name']);
      if ($logo_field && $vendor->hasField($logo_field)) {
        $logo_fids = $form_state->getValue(['logo']);
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

    // Save banner.
    if (isset($form['banner']) && $vendor->hasField('field_banner_image')) {
      $banner_fids = $form_state->getValue(['banner']);
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

    // Save the vendor.
    $vendor->save();

    // Redirect to next step.
    $form_state->setRedirect('myeventlane_vendor.onboard.first_event');
  }

}
