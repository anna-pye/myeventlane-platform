<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

require_once dirname(__DIR__, 3) . '/src/Service/VendorImageFieldPolicy.php';

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\myeventlane_vendor\Service\VendorImageFieldPolicy;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_vendor\Service\VendorImageFieldPolicy
 * @group myeventlane_vendor
 */
final class VendorImageFieldPolicyTest extends UnitTestCase {

  /**
   * @covers ::canonicalPublicLogoField
   * @covers ::emailOverrideField
   */
  public function testCanonicalAndEmailLogoContracts(): void {
    $vendor = $this->createMock(ContentEntityInterface::class);
    $vendor->method('hasField')->willReturnCallback(
      static fn(string $field): bool => in_array($field, [
        'field_vendor_logo',
        'field_logo_image',
        'field_msg_logo',
      ], TRUE),
    );
    $empty = $this->createMock(FieldItemListInterface::class);
    $empty->method('isEmpty')->willReturn(TRUE);
    $vendor->method('get')->willReturn($empty);

    self::assertSame('field_vendor_logo', VendorImageFieldPolicy::canonicalPublicLogoField($vendor));
    self::assertSame('field_msg_logo', VendorImageFieldPolicy::emailOverrideField($vendor));
    self::assertSame(
      ['field_vendor_logo', 'field_logo_image'],
      VendorImageFieldPolicy::PUBLIC_LOGO_FIELDS,
    );
    self::assertSame(
      ['field_msg_logo', 'field_vendor_logo', 'field_logo_image'],
      VendorImageFieldPolicy::EMAIL_LOGO_FIELDS,
    );
  }

  /**
   * @covers ::canonicalPublicLogoField
   */
  public function testLegacyPublicLogoFallback(): void {
    $vendor = $this->createMock(ContentEntityInterface::class);
    $vendor->method('hasField')->willReturnCallback(
      static fn(string $field): bool => $field === 'field_logo_image',
    );
    $empty = $this->createMock(FieldItemListInterface::class);
    $empty->method('isEmpty')->willReturn(TRUE);
    $vendor->method('get')->willReturn($empty);

    self::assertSame('field_logo_image', VendorImageFieldPolicy::canonicalPublicLogoField($vendor));
  }

  /**
   * @covers ::canonicalPublicLogoField
   */
  public function testPopulatedLegacyLogoBeatsEmptyPreferredField(): void {
    $vendor = $this->createMock(ContentEntityInterface::class);
    $vendor->method('hasField')->willReturn(TRUE);
    $empty = $this->createMock(FieldItemListInterface::class);
    $empty->method('isEmpty')->willReturn(TRUE);
    $populated = $this->createMock(FieldItemListInterface::class);
    $populated->method('isEmpty')->willReturn(FALSE);
    $vendor->method('get')->willReturnMap([
      ['field_vendor_logo', $empty],
      ['field_logo_image', $populated],
    ]);

    self::assertSame('field_logo_image', VendorImageFieldPolicy::canonicalPublicLogoField($vendor));
  }

}
