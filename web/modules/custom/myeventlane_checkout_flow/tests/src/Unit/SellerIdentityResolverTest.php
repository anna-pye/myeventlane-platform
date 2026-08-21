<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_flow\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\myeventlane_checkout_flow\Service\SellerIdentityResolver;
use PHPUnit\Framework\TestCase;

/**
 * Covers immutable organiser seller identity for buyer documents.
 *
 * @group myeventlane_checkout_flow
 * @coversDefaultClass \Drupal\myeventlane_checkout_flow\Service\SellerIdentityResolver
 */
final class SellerIdentityResolverTest extends TestCase {

  public function testCaptureUsesLegalBusinessNameAndPersistsSnapshot(): void {
    $businessName = $this->createMock(FieldItemListInterface::class);
    $businessName->method('isEmpty')->willReturn(FALSE);
    $businessName->method('getString')->willReturn('Sample Organiser Pty Ltd');

    $vendor = $this->createMock(ContentEntityInterface::class);
    $vendor->method('label')->willReturn('Sample Organiser');
    $vendor->method('id')->willReturn(57);
    $vendor->method('hasField')->willReturnCallback(
      static fn(string $field): bool => $field === 'field_business_name',
    );
    $vendor->method('get')->with('field_business_name')->willReturn($businessName);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->with('field_vendor_store', 55)->willReturnSelf();
    $query->method('sort')->with('id', 'ASC')->willReturnSelf();
    $query->method('range')->with(0, 1)->willReturnSelf();
    $query->method('execute')->willReturn([57 => 57]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('load')->with(57)->willReturn($vendor);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('hasDefinition')->with('myeventlane_vendor')->willReturn(TRUE);
    $entityTypeManager->method('getStorage')->with('myeventlane_vendor')->willReturn($storage);

    $store = $this->createMock(StoreInterface::class);
    $store->method('id')->willReturn(55);
    $store->method('hasField')->with('field_abn')->willReturn(FALSE);

    $order = $this->createMock(OrderInterface::class);
    $order->method('getData')->with(SellerIdentityResolver::ORDER_DATA_KEY)->willReturn(NULL);
    $order->method('getStore')->willReturn($store);
    $order->expects(self::once())
      ->method('setData')
      ->with(SellerIdentityResolver::ORDER_DATA_KEY, [
        'seller_name' => 'Sample Organiser Pty Ltd',
        'seller_abn' => '',
        'store_id' => 55,
        'vendor_id' => 57,
      ])
      ->willReturnSelf();

    $resolver = new SellerIdentityResolver($entityTypeManager);
    self::assertSame('Sample Organiser Pty Ltd', $resolver->capture($order)['seller_name']);
  }

  public function testSnapshotWinsOverMutableCurrentProfile(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects(self::never())->method('getStorage');

    $order = $this->createMock(OrderInterface::class);
    $order->method('getData')->with(SellerIdentityResolver::ORDER_DATA_KEY)->willReturn([
      'seller_name' => 'Seller At Purchase',
      'seller_abn' => '12 345 678 901',
      'store_id' => 55,
      'vendor_id' => 57,
    ]);
    $order->expects(self::never())->method('getStore');

    $resolver = new SellerIdentityResolver($entityTypeManager);
    self::assertSame([
      'seller_name' => 'Seller At Purchase',
      'seller_abn' => '12 345 678 901',
      'store_id' => 55,
      'vendor_id' => 57,
    ], $resolver->resolve($order));
  }

  public function testLegacyFallbackRemovesTechnicalStoreSuffix(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('hasDefinition')->with('myeventlane_vendor')->willReturn(FALSE);

    $store = $this->createMock(StoreInterface::class);
    $store->method('id')->willReturn(9);
    $store->method('getAddress')->willReturn(NULL);
    $store->method('label')->willReturn('Community Arts Group Store');
    $store->method('hasField')->with('field_abn')->willReturn(FALSE);

    $order = $this->createMock(OrderInterface::class);
    $order->method('getData')->with(SellerIdentityResolver::ORDER_DATA_KEY)->willReturn(NULL);
    $order->method('getStore')->willReturn($store);

    $resolver = new SellerIdentityResolver($entityTypeManager);
    self::assertSame('Community Arts Group', $resolver->resolve($order)['seller_name']);
  }

  public function testPlacementSubscriberCapturesBeforeOrderPlacement(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/EventSubscriber/SellerIdentitySnapshotSubscriber.php');
    self::assertIsString($source);
    self::assertStringContainsString('commerce_order.place.pre_transition', $source);
    self::assertStringContainsString('$this->sellerIdentity->capture($order)', $source);
  }

}
