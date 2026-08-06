<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_messaging\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Test\TestKernel;
use Drupal\myeventlane_messaging\Form\VendorBrandingForm;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQuery;
use Drupal\myeventlane_vendor\Service\VendorBrandMediaManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests the vendor branding form's cached form-state compatibility.
 */
#[CoversClass(VendorBrandingForm::class)]
#[Group('myeventlane_messaging')]
final class VendorBrandingFormSerializationTest extends UnitTestCase {

  /**
   * Ensures injected services survive Drupal's form cache lifecycle.
   */
  public function testInjectedServicesSurviveSerialization(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $currentUser = $this->createMock(AccountProxyInterface::class);
    $cacheTagsInvalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $logger = $this->createMock(LoggerInterface::class);
    $vendorResolver = $this->createMock(CurrentVendorResolverInterface::class);
    $userVendorMembershipQuery = new UserVendorMembershipQuery(
      $entityTypeManager,
      $this->createMock(EntityFieldManagerInterface::class),
    );
    $brandMediaManager = new VendorBrandMediaManager(
      $entityTypeManager,
      $this->createMock(FileSystemInterface::class),
      $logger,
    );

    $services = [
      'entityTypeManager' => ['entity_type.manager', $entityTypeManager],
      'currentUser' => ['current_user', $currentUser],
      'cacheTagsInvalidator' => ['cache_tags.invalidator', $cacheTagsInvalidator],
      'logger' => ['logger.channel.myeventlane_messaging', $logger],
      'vendorResolver' => ['myeventlane_vendor.current_vendor_resolver', $vendorResolver],
      'userVendorMembershipQuery' => ['myeventlane_vendor.user_vendor_membership_query', $userVendorMembershipQuery],
      'brandMediaManager' => ['myeventlane_vendor.brand_media_manager', $brandMediaManager],
    ];

    $container = TestKernel::setContainerWithKernel();
    foreach ($services as [$serviceId, $service]) {
      $container->set($serviceId, $service);
    }

    $form = new VendorBrandingForm(
      $entityTypeManager,
      $currentUser,
      $cacheTagsInvalidator,
      $logger,
      $vendorResolver,
      $userVendorMembershipQuery,
      $brandMediaManager,
    );

    $restoredForm = unserialize(serialize($form), [
      'allowed_classes' => [VendorBrandingForm::class],
    ]);
    self::assertInstanceOf(VendorBrandingForm::class, $restoredForm);

    foreach ($services as $propertyName => [, $service]) {
      $property = new \ReflectionProperty($restoredForm, $propertyName);
      self::assertTrue(
        $property->isInitialized($restoredForm),
        sprintf('The %s service was not restored.', $propertyName),
      );
      self::assertSame($service, $property->getValue($restoredForm));
    }
  }

}
