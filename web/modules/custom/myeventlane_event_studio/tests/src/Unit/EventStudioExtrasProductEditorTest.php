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

  public function testOperationalVariationStockFieldsAreDetected(): void {
    $defs = [
      'field_mel_stock_quantity' => $this->createMock(\Drupal\Core\Field\FieldDefinitionInterface::class),
      'field_mel_limit_per_order' => $this->createMock(\Drupal\Core\Field\FieldDefinitionInterface::class),
      'field_mel_show_remaining' => $this->createMock(\Drupal\Core\Field\FieldDefinitionInterface::class),
      'field_mel_size' => $this->createMock(\Drupal\Core\Field\FieldDefinitionInterface::class),
    ];
    $manager = $this->createMock(EntityFieldManagerInterface::class);
    $manager->method('getFieldDefinitions')->willReturn($defs);
    $registry = new OperationalProductStudioFieldRegistry($manager);
    $this->assertTrue($registry->operationalVariationsSupportStock());
    $variation = $registry->variationFieldSupport('operational_merchandise_var');
    $this->assertTrue($variation['stock_quantity']);
    $this->assertTrue($variation['limit_per_order']);
    $this->assertTrue($variation['show_remaining']);
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
      'variant_stock' => [
        's' => ['stock_quantity' => 2, 'limit_per_order' => 2, 'show_remaining' => TRUE],
        'm' => ['stock_quantity' => 0, 'limit_per_order' => NULL, 'show_remaining' => TRUE],
      ],
    ]);
    $this->assertCount(2, $rows);
    $this->assertStringContainsString('TEE-99', $rows[0]['sku']);
    $this->assertSame(2, $rows[0]['stock_quantity']);
    $this->assertSame(0, $rows[1]['stock_quantity']);
  }

  public function testResolveVariantStockInputFallsBackToProductLevel(): void {
    $manager = $this->createPartialManager();
    $stock = $manager->resolveVariantStockInput([
      'stock_quantity' => 10,
      'limit_per_order' => 3,
      'show_remaining' => 1,
    ], 'l');
    $this->assertSame(10, $stock['stock_quantity']);
    $this->assertSame(3, $stock['limit_per_order']);
    $this->assertTrue($stock['show_remaining']);
  }

  public function testProductStatusKeysAreDefined(): void {
    $this->assertSame(['active', 'hidden', 'draft'], ['active', 'hidden', 'draft']);
  }

  public function testExtrasProductEditorBuilderClassExists(): void {
    $this->assertTrue(class_exists(\Drupal\myeventlane_event_studio\Service\EventStudioExtrasProductEditorBuilder::class));
  }

  public function testProductEditorUsesVendorManagedProductOptions(): void {
    $builder = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioExtrasProductEditorBuilder.php');
    $form = file_get_contents(dirname(__DIR__, 3) . '/src/Form/EventStudioEventExtrasForm.php');
    $manager = file_get_contents(dirname(__DIR__, 3) . '/src/Service/VendorOperationalProductCreationManager.php');
    $this->assertIsString($builder);
    $this->assertIsString($form);
    $this->assertIsString($manager);
    $this->assertStringContainsString('buildProductOptionsSection', $builder);
    $this->assertStringContainsString('Product options', $builder);
    $this->assertStringContainsString('variation_id', $builder);
    $this->assertStringContainsString('syncVariationsFromProductOptions', $manager);
    $this->assertStringContainsString('buildProductOptionsFromProduct', $manager);
    $this->assertStringContainsString('product_options', $form);
    $this->assertStringContainsString('submitAddProductOption', $form);
    $this->assertStringContainsString('option_label', $builder);
    $this->assertStringContainsString("['#parents'] = ['editor', 'product_options']", $builder);
    $this->assertStringContainsString('buildProductOptionStockTable', $builder);
    $this->assertStringContainsString('Edit stock per option', $builder);
    $this->assertStringContainsString('buildStockSummaryChips', $builder);
    $this->assertStringContainsString('summarizeProductOptionsStock', $manager);
    $this->assertStringContainsString('editorSlice', $form);
    preg_match(
      '/private function buildProductOptionCatalogRow([\s\S]*?)private function/s',
      $builder,
      $catalog_match,
    );
    $this->assertNotEmpty($catalog_match[1] ?? '');
    $this->assertStringNotContainsString('stock_quantity', $catalog_match[1]);
  }

  public function testSummarizeProductOptionsStockDefaultsToClosedDetails(): void {
    $manager = $this->createPartialManager();
    $summary = $manager->summarizeProductOptionsStock([
      ['option_label' => 'S', 'stock_quantity' => NULL],
      ['option_label' => 'M', 'stock_quantity' => ''],
    ]);
    $this->assertSame(2, $summary['option_count']);
    $this->assertSame(2, $summary['unlimited_count']);
    $this->assertFalse($summary['open_stock_details']);
    $this->assertSame('unlimited', $summary['stock_mode']);
  }

  public function testSummarizeProductOptionsStockOpensDetailsForFiniteOrSoldOut(): void {
    $manager = $this->createPartialManager();
    $sold_out = $manager->summarizeProductOptionsStock([
      ['option_label' => 'L', 'stock_quantity' => 0],
    ]);
    $this->assertTrue($sold_out['open_stock_details']);
    $this->assertSame('sold_out', $sold_out['stock_mode']);

    $finite = $manager->summarizeProductOptionsStock([
      ['option_label' => 'M', 'stock_quantity' => 12],
    ]);
    $this->assertTrue($finite['open_stock_details']);

    $limit = $manager->summarizeProductOptionsStock([
      ['option_label' => 'VIP', 'stock_quantity' => NULL, 'limit_per_order' => 2],
    ]);
    $this->assertTrue($limit['open_stock_details']);
  }

  public function testStockTablePreservesVariationIdParents(): void {
    $builder = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioExtrasProductEditorBuilder.php');
    $this->assertIsString($builder);
    $this->assertStringContainsString("['editor', 'product_options', 'rows', \$delta]", $builder);
    $this->assertStringContainsString("array_merge(\$parents, ['variation_id'])", $builder);
    $this->assertStringContainsString("array_merge(\$parents, ['stock_quantity'])", $builder);
  }

  public function testBookingPreviewUsesMetaLine(): void {
    $builder = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioExtrasProductEditorBuilder.php');
    $twig = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio-extra-preview.html.twig');
    $this->assertIsString($builder);
    $this->assertIsString($twig);
    $this->assertStringContainsString('#preview_meta_line', $builder);
    $this->assertStringContainsString('preview_meta_line', $twig);
    $this->assertStringContainsString('mel-event-product-editor__layout', $builder);
    $this->assertStringContainsString('mel-event-product-editor__aside', $builder);
    $this->assertStringContainsString('buildProductSetupSummarySection', $builder);
  }

  public function testStockHelperCopyAppearsOnceInStockPanel(): void {
    $builder = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioExtrasProductEditorBuilder.php');
    $this->assertIsString($builder);
    preg_match(
      '/private function buildStockLimitsPanelContent\([\s\S]*?private function buildPreviewPanelContent/s',
      $builder,
      $panel_match,
    );
    $this->assertNotEmpty($panel_match[0] ?? '');
    $panel = $panel_match[0];
    $this->assertSame(1, substr_count($panel, 'Blank = unlimited. 0 = sold out. Positive numbers are enforced.'));
    $this->assertSame(1, substr_count($panel, 'Limit per order is optional.'));
    $this->assertStringContainsString('Stock is enforced when customers add extras to cart.', $panel);
    preg_match(
      '/private function buildProductOptionCatalogRow\([\s\S]*?private function/s',
      $builder,
      $catalog_match,
    );
    $this->assertNotEmpty($catalog_match[0] ?? '');
    $this->assertSame(0, substr_count($catalog_match[0], 'Blank = unlimited'));
  }

  public function testNormalizeProductOptionsPreservesVariationId(): void {
    $ref = new \ReflectionClass(VendorOperationalProductCreationManager::class);
    $method = $ref->getMethod('normalizeProductOptionsInput');
    $manager = $this->createPartialManager();
    $rows = $method->invoke($manager, [
      [
        'variation_id' => 42,
        'option_label' => 'M',
        'sku' => 'TEE-m',
        'price_amount' => '35',
        'stock_quantity' => 5,
        'published' => 1,
      ],
    ]);
    $this->assertCount(1, $rows);
    $this->assertSame(42, $rows[0]['variation_id']);
    $this->assertSame('M', $rows[0]['option_label']);
    $this->assertSame(5, $rows[0]['stock_quantity']);
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
    $stock = new \Drupal\myeventlane_commerce\Service\OperationalVariationStockResolver($translator);
    $stockProp = $ref->getProperty('stockResolver');
    $stockProp->setAccessible(TRUE);
    $stockProp->setValue($manager, $stock);
    return $manager;
  }

}
