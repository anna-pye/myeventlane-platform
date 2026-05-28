<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\myeventlane_commerce\Service\EventOperationalAddonBuilder;
use Drupal\myeventlane_commerce\Service\OperationalExtraVisualPresenter;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\myeventlane_commerce\Service\OperationalVariationStockResolver;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\EventOperationalAddonBuilder
 *
 * @group myeventlane_commerce
 */
final class EventOperationalAddonBuilderTest extends UnitTestCase {

  private function builder(?OperationalExtraVisualPresenter $visual = NULL): EventOperationalAddonBuilder {
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $logger = $this->createMock(LoggerInterface::class);
    $merch = new OperationalMerchandiseManager(
      $etm,
      $this->getStringTranslationStub(),
      $logger,
    );
    $visual ??= new OperationalExtraVisualPresenter(
      $etm,
      $this->createMock(\Drupal\Core\File\FileUrlGeneratorInterface::class),
      $this->createMock(\Drupal\Core\Extension\ExtensionPathResolver::class),
      $this->getStringTranslationStub(),
    );
    $stock = new OperationalVariationStockResolver($this->getStringTranslationStub());
    return new EventOperationalAddonBuilder(
      $etm,
      $merch,
      $visual,
      $stock,
      $this->getStringTranslationStub(),
    );
  }

  public function testSanitizeAddonStripsForbiddenKeys(): void {
    $dirty = [
      'title' => 'Tee',
      'qr_payload' => 'secret',
      'nested' => [
        'scanner_secret' => 'x',
        'ok' => 1,
      ],
    ];
    $clean = $this->builder()->sanitizeAddon($dirty);
    $this->assertSame('Tee', $clean['title']);
    $this->assertArrayNotHasKey('qr_payload', $clean);
    $this->assertArrayNotHasKey('scanner_secret', $clean['nested']);
    $this->assertSame(1, $clean['nested']['ok']);
  }

  public function testSanitizeAddonStripsStockAndWarehouseKeys(): void {
    $dirty = [
      'stock_count' => 5,
      'warehouse_ids' => [1],
      'vendor_margin' => '10',
    ];
    $clean = $this->builder()->sanitizeAddon($dirty);
    $this->assertSame([], $clean);
  }

  public function testSanitizeAddonIncludesGalleryMetadataKeys(): void {
    $addon = $this->builder()->sanitizeAddon([
      'title' => 'Event T-shirt',
      'has_images' => FALSE,
      'primary_image' => ['url' => '/placeholder.svg', 'is_placeholder' => TRUE],
      'gallery_images' => [['url' => '/placeholder.svg']],
      'short_description' => 'Soft cotton tee',
      'pickup_note' => 'Collect at the merch table',
      'size_options' => [
        ['variation_id' => 10, 'size_key' => 'l', 'size_label' => 'L'],
      ],
    ]);
    $this->assertFalse($addon['has_images']);
    $this->assertSame('Soft cotton tee', $addon['short_description']);
    $this->assertSame('Collect at the merch table', $addon['pickup_note']);
    $this->assertCount(1, $addon['size_options']);
    $this->assertArrayNotHasKey('warehouse_ids', $addon);
  }

  public function testVisualPresenterFieldFirstDescriptionFallback(): void {
    $visual = $this->createMock(OperationalExtraVisualPresenter::class);
    $visual->method('buildProductVisualDocument')->willReturn([
      'short_description' => 'From field',
      'pickup_note' => 'Desk pickup',
      'primary_image' => ['url' => '/x.jpg'],
      'gallery_images' => [],
      'has_images' => TRUE,
      'image_alt' => 'Tee',
      'placeholder_url' => '/p.svg',
    ]);
    $visual->method('resolveVariationSize')->willReturn(['size_key' => 'm', 'size_label' => 'M']);
    $visual->method('meaningfulVariationLabel')->willReturn('');

    $product = $this->createMock(ProductInterface::class);
    $product->method('bundle')->willReturn('operational_merchandise');
    $product->method('isPublished')->willReturn(TRUE);
    $product->method('label')->willReturn('Tee');
    $product->method('id')->willReturn('1');
    $product->method('getStores')->willReturn([$this->createMock(\Drupal\commerce_store\Entity\StoreInterface::class)]);
    $product->method('hasField')->willReturnCallback(
      static fn (string $f): bool => in_array($f, ['field_event', 'field_mel_operational_product'], TRUE),
    );
    $event_field = $this->createMock(FieldItemListInterface::class);
    $event_field->method('isEmpty')->willReturn(FALSE);
    $event_field->method('__get')->willReturnMap([['target_id', '99']]);
    $op_field = $this->createMock(FieldItemListInterface::class);
    $op_field->method('isEmpty')->willReturn(FALSE);
    $op_field->method('__get')->willReturnMap([['value', '{"operational_summary":"JSON summary"}']]);
    $product->method('get')->willReturnCallback(
      static fn (string $f) => match ($f) {
        'field_event' => $event_field,
        default => $op_field,
      },
    );

    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('isPublished')->willReturn(TRUE);
    $variation->method('id')->willReturn('5');
    $variation->method('label')->willReturn('Price');
    $variation->method('getPrice')->willReturn(NULL);
    $product->method('getVariations')->willReturn([$variation]);

    $storage = $this->createMock(\Drupal\Core\Entity\EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query = $this->createMock(\Drupal\Core\Entity\Query\QueryInterface::class));
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([1]);
    $storage->method('loadMultiple')->willReturn([1 => $product]);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturn($storage);

    $logger = $this->createMock(LoggerInterface::class);
    $merch = new OperationalMerchandiseManager($etm, $this->getStringTranslationStub(), $logger);
    $builder = new EventOperationalAddonBuilder($etm, $merch, $visual, new OperationalVariationStockResolver($this->getStringTranslationStub()), $this->getStringTranslationStub());

    $event = $this->createMock(\Drupal\node\NodeInterface::class);
    $event->method('id')->willReturn('99');
    $event->method('bundle')->willReturn('event');

    $built = $builder->buildForEvent($event);
    $this->assertSame('From field', $built['addons'][0]['short_description']);
    $this->assertSame('m', $built['addons'][0]['variations'][0]['size_key']);
  }

}
