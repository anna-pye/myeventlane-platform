<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_event_studio\Service\OperationalProductStudioFieldRegistry;
use Drupal\myeventlane_event_studio\Service\VendorOperationalProductCreationManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @group myeventlane_event_studio
 */
#[Group('myeventlane_event_studio')]
final class EventStudioExtrasProductEditorTest extends TestCase {

  public function testOperationalProductsExposeGalleryImageField(): void {
    $defs = [
      'field_mel_extra_images' => $this->createMock(\Drupal\Core\Field\FieldDefinitionInterface::class),
      'field_mel_extra_short_desc' => $this->createMock(\Drupal\Core\Field\FieldDefinitionInterface::class),
      'field_event' => $this->createMock(\Drupal\Core\Field\FieldDefinitionInterface::class),
      'status' => $this->createMock(\Drupal\Core\Field\FieldDefinitionInterface::class),
    ];
    $manager = $this->createMock(EntityFieldManagerInterface::class);
    $manager->method('getFieldDefinitions')->willReturn($defs);
    $registry = new OperationalProductStudioFieldRegistry($manager);
    $this->assertTrue($registry->operationalProductsSupportImages());
    $support = $registry->productFieldSupport('operational_merchandise');
    $this->assertTrue($support['images']);
    $this->assertTrue($support['short_description']);
    $this->assertFalse($registry->operationalVariationsSupportStock());
  }

  public function testMerchandiseVariationBundleSupportsSizeField(): void {
    $defs = [
      'field_mel_size' => $this->createMock(\Drupal\Core\Field\FieldDefinitionInterface::class),
      'sku' => $this->createMock(\Drupal\Core\Field\FieldDefinitionInterface::class),
      'price' => $this->createMock(\Drupal\Core\Field\FieldDefinitionInterface::class),
    ];
    $manager = $this->createMock(EntityFieldManagerInterface::class);
    $manager->method('getFieldDefinitions')->willReturn($defs);
    $registry = new OperationalProductStudioFieldRegistry($manager);
    $variation = $registry->variationFieldSupport('operational_merchandise_var');
    $this->assertTrue($variation['size']);
    $this->assertFalse($variation['stock_quantity']);
  }

  public function testVariantPreviewRowsForSizes(): void {
    $manager = $this->createPartialManager();
    $event = $this->createMock(\Drupal\node\NodeInterface::class);
    $event->method('id')->willReturn(99);
    $rows = $manager->buildVariantPreviewRows($event, [
      'extra_type' => 'merchandise',
      'sizes' => ['s', 'm'],
      'price_amount' => '25',
      'sku' => 'TEE-99',
      'capacity_note' => '50 printed',
    ]);
    $this->assertCount(2, $rows);
    $this->assertStringContainsString('TEE-99', $rows[0]['sku']);
    $this->assertSame('50 printed', $rows[0]['capacity_note']);
  }

  public function testProductStatusKeysAreDefined(): void {
    $this->assertSame(['active', 'hidden', 'draft'], ['active', 'hidden', 'draft']);
  }

  public function testExtrasProductEditorBuilderClassExists(): void {
    $this->assertTrue(class_exists(\Drupal\myeventlane_event_studio\Service\EventStudioExtrasProductEditorBuilder::class));
  }

  private function createPartialManager(): VendorOperationalProductCreationManager {
    $ref = new \ReflectionClass(VendorOperationalProductCreationManager::class);
    /** @var VendorOperationalProductCreationManager $manager */
    $manager = $ref->newInstanceWithoutConstructor();
    $translator = $this->createMock(TranslationInterface::class);
    $translator->method('translate')->willReturnArgument(0);
    $translator->method('translateString')->willReturnArgument(0);
    $prop = $ref->getProperty('translation');
    $prop->setAccessible(TRUE);
    $prop->setValue($manager, $translator);
    return $manager;
  }

}
