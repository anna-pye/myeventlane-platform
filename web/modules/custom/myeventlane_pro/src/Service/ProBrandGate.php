<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_messaging\Service\BrandResolverInterface;
use Drupal\myeventlane_messaging\ValueObject\Brand;
use Drupal\myeventlane_vendor\Entity\Vendor;

/**
 * Decorates VendorBrandResolver to enforce Pro-only branding.
 *
 * When a vendor is not a Pro subscriber, this gate returns MEL platform
 * defaults instead of the vendor's custom brand settings. This ensures
 * custom email branding is exclusively a Pro feature.
 */
final class ProBrandGate implements BrandResolverInterface {

  private const PRO_ROLE = 'pro_organiser';

  public function __construct(
    private readonly BrandResolverInterface $inner,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolve(array $context): Brand {
    $brand = $this->inner->resolve($context);

    if ($brand->isFromVendor() && $brand->vendorId !== NULL) {
      if (!$this->isProVendor($brand->vendorId)) {
        return $this->inner->getDefaultBrand();
      }
    }

    return $brand;
  }

  /**
   * {@inheritdoc}
   */
  public function resolveForVendor(Vendor $vendor): Brand {
    if (!$this->isProVendor((int) $vendor->id())) {
      return $this->inner->getDefaultBrand();
    }

    return $this->inner->resolveForVendor($vendor);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultBrand(): Brand {
    return $this->inner->getDefaultBrand();
  }

  /**
   * Checks whether a vendor's owner holds the pro_organiser role.
   */
  private function isProVendor(int $vendorId): bool {
    $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')
      ->load($vendorId);

    if (!$vendor instanceof Vendor) {
      return FALSE;
    }

    $owner = $vendor->getOwner();
    if (!$owner) {
      return FALSE;
    }

    return in_array(self::PRO_ROLE, $owner->getRoles(), TRUE);
  }

}
