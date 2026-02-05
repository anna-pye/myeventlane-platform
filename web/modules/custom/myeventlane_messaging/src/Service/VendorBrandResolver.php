<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\myeventlane_messaging\ValueObject\Brand;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface;

/**
 * Resolves vendor branding for messages from Vendor entity fields.
 *
 * Resolution order (canonical):
 * 1. If vendor_id provided → load vendor → brand from vendor fields
 * 2. Else if vendor entity provided → brand from vendor fields
 * 3. Else if event_id provided → load event → field_event_vendor → brand
 * 4. Else if order provided → resolve vendor via store/event → brand
 * 5. Else → MEL platform defaults
 */
final class VendorBrandResolver implements BrandResolverInterface {

  /**
   * Constructs VendorBrandResolver.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory (for platform defaults).
   * @param \Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface $vendorResolver
   *   The current vendor resolver.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CurrentVendorResolverInterface $vendorResolver,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolve(array $context): Brand {
    // Use CurrentVendorResolver to find the vendor from context.
    $vendor = $this->vendorResolver->resolveFromContext($context);

    if ($vendor instanceof Vendor) {
      return $this->resolveForVendor($vendor);
    }

    return $this->getDefaultBrand();
  }

  /**
   * {@inheritdoc}
   */
  public function resolveForVendor(Vendor $vendor): Brand {
    $vendorId = (int) $vendor->id();

    // Get values from vendor entity fields.
    $fromName = $this->getFieldValue($vendor, 'field_msg_from_name');
    if ($fromName === '') {
      // Fallback to vendor name.
      $fromName = $vendor->getName() ?: 'MyEventLane';
    }

    $fromEmail = $this->getFieldValue($vendor, 'field_msg_from_email');
    $replyTo = $this->getFieldValue($vendor, 'field_msg_reply_to');
    if ($replyTo === '' && $fromEmail !== '') {
      $replyTo = $fromEmail;
    }

    $footerText = $this->getFieldValue($vendor, 'field_msg_footer');
    if ($footerText === '') {
      $footerText = Brand::DEFAULT_FOOTER;
    }

    $accentColor = $this->getFieldValue($vendor, 'field_msg_accent_color');
    if ($accentColor === '' || !$this->isValidHexColor($accentColor)) {
      $accentColor = Brand::DEFAULT_ACCENT_COLOR;
    }

    // Logo URL from field_msg_logo, fallback to field_vendor_logo.
    $logoUrl = $this->getLogoUrl($vendor, 'field_msg_logo');
    if ($logoUrl === '') {
      $logoUrl = $this->getLogoUrl($vendor, 'field_vendor_logo');
    }
    if ($logoUrl === '') {
      $logoUrl = $this->getLogoUrl($vendor, 'field_logo_image');
    }

    return new Brand(
      fromName: $fromName,
      fromEmail: $fromEmail,
      replyTo: $replyTo,
      footerText: $footerText,
      accentColor: $accentColor,
      logoUrl: $logoUrl,
      source: Brand::SOURCE_VENDOR,
      vendorId: $vendorId,
      eventId: NULL,
      marketing: [],
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultBrand(): Brand {
    $settings = $this->configFactory->get('myeventlane_messaging.settings');

    return new Brand(
      fromName: (string) ($settings->get('from_name') ?? 'MyEventLane'),
      fromEmail: (string) ($settings->get('from_email') ?? ''),
      replyTo: (string) ($settings->get('reply_to') ?? ''),
      footerText: Brand::DEFAULT_FOOTER,
      accentColor: Brand::DEFAULT_ACCENT_COLOR,
      logoUrl: '',
      source: Brand::SOURCE_DEFAULT,
      vendorId: NULL,
      eventId: NULL,
      marketing: [],
    );
  }

  /**
   * Gets a string field value from vendor entity.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The vendor entity.
   * @param string $fieldName
   *   The field name.
   *
   * @return string
   *   The field value, or empty string.
   */
  private function getFieldValue(Vendor $vendor, string $fieldName): string {
    if (!$vendor->hasField($fieldName) || $vendor->get($fieldName)->isEmpty()) {
      return '';
    }
    $value = $vendor->get($fieldName)->value;
    return is_string($value) ? $value : '';
  }

  /**
   * Gets the logo URL from an image field.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The vendor entity.
   * @param string $fieldName
   *   The image field name.
   *
   * @return string
   *   Absolute URL to the logo, or empty string.
   */
  private function getLogoUrl(Vendor $vendor, string $fieldName): string {
    if (!$vendor->hasField($fieldName) || $vendor->get($fieldName)->isEmpty()) {
      return '';
    }

    $file = $vendor->get($fieldName)->entity;
    if (!$file instanceof FileInterface) {
      return '';
    }

    try {
      return $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
    }
    catch (\Exception $e) {
      return '';
    }
  }

  /**
   * Validates a hex color code.
   *
   * @param string $color
   *   The color string to validate.
   *
   * @return bool
   *   TRUE if valid hex color, FALSE otherwise.
   */
  private function isValidHexColor(string $color): bool {
    return (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $color);
  }

}
