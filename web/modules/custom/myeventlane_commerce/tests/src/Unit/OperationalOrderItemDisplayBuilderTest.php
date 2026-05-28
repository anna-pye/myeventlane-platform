<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_commerce\Service\OperationalExtraVisualPresenter;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\myeventlane_commerce\Service\OperationalOrderItemDisplayBuilder;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\OperationalOrderItemDisplayBuilder
 *
 * @group myeventlane_commerce
 */
final class OperationalOrderItemDisplayBuilderTest extends UnitTestCase {

  /**
   * @covers ::buildForOrderItem
   */
  public function testReturnsNullForNonOperationalProduct(): void {
    $builder = $this->builder();
    $order_item = $this->orderItemFixture('default', 'Price', 'Regular ticket');
    $this->assertNull($builder->buildForOrderItem($order_item));
  }

  /**
   * @covers ::buildForOrderItem
   */
  public function testOperationalProductUsesProductLabelNotGenericVariationTitle(): void {
    $builder = $this->builder();
    $order_item = $this->orderItemFixture(
      'operational_merchandise',
      'Price',
      'Event T-shirt',
      operational_payload: [
        'operational_product_type' => 'merch_pickup',
        'operational_summary' => 'Collect your T-shirt at the venue after purchase.',
        'operational_chips' => [
          ['label' => 'Add-on', 'tone' => 'muted'],
          ['label' => 'Venue pickup', 'tone' => 'pickup'],
        ],
        'pickup_mode' => 'venue_pickup',
        'customer_visibility' => 'visible',
        'readiness_mode' => 'authoring',
      ],
      event_title: 'Night of Live Music at the Ritz',
    );
    $display = $builder->buildForOrderItem($order_item);
    $this->assertIsArray($display);
    $this->assertTrue($display[OperationalOrderItemDisplayBuilder::CONTRACT_FLAG]);
    $this->assertSame('Event T-shirt', $display['title']);
    $this->assertSame('', $display['variation_label']);
    $this->assertStringContainsString('Night of Live Music at the Ritz', (string) $display['subtitle']);
  }

  /**
   * @covers ::buildForOrderItem
   */
  public function testEventTitleResolvesFromProductFieldEvent(): void {
    $builder = $this->builder();
    $order_item = $this->orderItemFixture(
      'operational_merchandise',
      'Price',
      'Event T-shirt',
      event_title: 'Ritz Live Music Night',
      event_id: 1584,
    );
    $display = $builder->buildForOrderItem($order_item);
    $this->assertIsArray($display);
    $this->assertSame(1584, $display['event_id']);
    $this->assertSame('Ritz Live Music Night', $display['event_title']);
  }

  /**
   * @covers ::buildForOrderItem
   */
  public function testDescriptionResolvesFromOperationalPresentation(): void {
    $builder = $this->builder();
    $order_item = $this->orderItemFixture(
      'operational_merchandise',
      'Price',
      'Event T-shirt',
      operational_payload: [
        'operational_product_type' => 'merch_pickup',
        'operational_summary' => 'Collect your T-shirt at the venue after purchase.',
        'operational_chips' => [],
        'pickup_mode' => 'venue_pickup',
        'customer_visibility' => 'visible',
        'readiness_mode' => 'authoring',
      ],
    );
    $display = $builder->buildForOrderItem($order_item);
    $this->assertIsArray($display);
    $this->assertSame('Collect your T-shirt at the venue after purchase.', $display['description']);
  }

  /**
   * @covers ::stripForbiddenRecursive
   */
  public function testForbiddenKeysAreStrippedRecursively(): void {
    $builder = $this->builder();
    $out = $builder->stripForbiddenRecursive([
      'title' => 'Event T-shirt',
      'chips' => [
        ['label' => 'Add-on', 'qr_payload' => 'secret'],
      ],
      'scanner_secret' => 'nope',
    ]);
    $this->assertSame('Event T-shirt', $out['title']);
    $this->assertArrayNotHasKey('scanner_secret', $out);
    $this->assertArrayNotHasKey('qr_payload', $out['chips'][0]);
    $this->assertSame('Add-on', $out['chips'][0]['label']);
  }

  /**
   * @covers ::buildForOrderItem
   */
  public function testDisplayIncludesThumbnailSizeAndPickupNote(): void {
    $visual = $this->createMock(OperationalExtraVisualPresenter::class);
    $visual->method('buildProductVisualDocument')->willReturn([
      'short_description' => 'Soft tee',
      'pickup_note' => 'Collect at the merch table',
      'primary_image' => ['url' => 'https://example.test/tee.jpg', 'alt' => 'Tee'],
      'gallery_images' => [],
      'has_images' => TRUE,
      'image_alt' => 'Tee',
      'placeholder_url' => '',
    ]);
    $visual->method('buildPrimaryThumbnailForProduct')->willReturn([
      'url' => 'https://example.test/tee.jpg',
      'alt' => 'Tee',
    ]);
    $visual->method('resolveVariationSize')->willReturn(['size_key' => 'l', 'size_label' => 'L']);
    $visual->method('meaningfulVariationLabel')->willReturn('');

    $builder = $this->builder($visual);
    $order_item = $this->orderItemFixture(
      'operational_merchandise',
      'Price',
      'Event T-shirt',
    );
    $display = $builder->buildForOrderItem($order_item);
    $this->assertIsArray($display);
    $this->assertSame('L', $display['size_label']);
    $this->assertSame('Collect at the merch table', $display['pickup_note']);
    $this->assertStringContainsString('tee.jpg', (string) $display['thumbnail_url']);
  }

  public function testPlaceholderWhenNoImages(): void {
    $visual = $this->createMock(OperationalExtraVisualPresenter::class);
    $visual->method('buildProductVisualDocument')->willReturn([
      'short_description' => '',
      'pickup_note' => '',
      'primary_image' => ['url' => '/themes/custom/myeventlane_theme/images/mel/placeholders/mel-placeholder-default.svg', 'is_placeholder' => TRUE],
      'gallery_images' => [],
      'has_images' => FALSE,
      'image_alt' => 'Event extra',
      'placeholder_url' => '/themes/custom/myeventlane_theme/images/mel/placeholders/mel-placeholder-default.svg',
    ]);
    $visual->method('buildPrimaryThumbnailForProduct')->willReturn([
      'url' => '/themes/custom/myeventlane_theme/images/mel/placeholders/mel-placeholder-default.svg',
      'alt' => 'Event extra',
      'is_placeholder' => TRUE,
    ]);
    $visual->method('resolveVariationSize')->willReturn(['size_key' => '', 'size_label' => '']);
    $visual->method('meaningfulVariationLabel')->willReturn('');

    $display = $this->builder($visual)->buildForOrderItem(
      $this->orderItemFixture('operational_merchandise', 'Price', 'Hat'),
    );
    $this->assertIsArray($display);
    $this->assertStringContainsString('placeholder', (string) $display['thumbnail_url']);
  }

  public function testChipsRemainListShaped(): void {
    $builder = $this->builder();
    $order_item = $this->orderItemFixture(
      'operational_merchandise',
      'Price',
      'Event T-shirt',
      operational_payload: [
        'operational_product_type' => 'merch_pickup',
        'operational_chips' => [
          ['label' => 'Add-on', 'tone' => 'muted'],
          ['label' => 'Venue pickup', 'tone' => 'pickup'],
        ],
        'pickup_mode' => 'venue_pickup',
        'customer_visibility' => 'visible',
        'readiness_mode' => 'authoring',
      ],
    );
    $display = $builder->buildForOrderItem($order_item);
    $this->assertIsArray($display);
    $this->assertIsList($display['chips']);
    $this->assertGreaterThanOrEqual(2, count($display['chips']));
    $labels = array_column($display['chips'], 'label');
    $this->assertContains('Add-on', $labels);
  }

  private function builder(?OperationalExtraVisualPresenter $visual = NULL): OperationalOrderItemDisplayBuilder {
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $manager = new OperationalMerchandiseManager(
      $etm,
      $this->getStringTranslationStub(),
      $this->createMock(LoggerInterface::class),
    );
    $visual ??= new OperationalExtraVisualPresenter(
      $etm,
      $this->createMock(\Drupal\Core\File\FileUrlGeneratorInterface::class),
      $this->createMock(\Drupal\Core\Extension\ExtensionPathResolver::class),
      $this->getStringTranslationStub(),
    );
    $currency = $this->createMock(\Drupal\commerce_price\CurrencyFormatter::class);
    $currency->method('format')->willReturn('$35.00');
    return new OperationalOrderItemDisplayBuilder(
      $manager,
      $visual,
      $etm,
      $currency,
      $this->getStringTranslationStub(),
    );
  }

  /**
   * @param array<string, mixed>|null $operational_payload
   */
  private function orderItemFixture(
    string $product_bundle,
    string $variation_label,
    string $product_label,
    ?array $operational_payload = NULL,
    ?string $event_title = NULL,
    int $event_id = 0,
  ): OrderItemInterface {
    $event = NULL;
    if ($event_title !== NULL) {
      $event = $this->createMock(NodeInterface::class);
      $event->method('id')->willReturn((string) $event_id);
      $event->method('label')->willReturn($event_title);
      $event->method('bundle')->willReturn('event');
    }

    $event_field = new TestFieldItemList($event);
    $operational_field = new TestOperationalFieldItemList($operational_payload);

    $product = $this->createMock(ProductInterface::class);
    $product->method('bundle')->willReturn($product_bundle);
    $product->method('label')->willReturn($product_label);
    $product->method('hasField')->willReturnCallback(
      static fn (string $field): bool => in_array($field, ['field_event', 'field_mel_operational_product'], TRUE),
    );
    $product->method('get')->willReturnCallback(
      static fn (string $field) => match ($field) {
        'field_event' => $event_field,
        'field_mel_operational_product' => $operational_field,
        default => $event_field,
      },
    );

    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('label')->willReturn($variation_label);
    $variation->method('getProduct')->willReturn($product);

    $order_item = $this->createMock(OrderItemInterface::class);
    $order_item->method('getPurchasedEntity')->willReturn($variation);

    return $order_item;
  }

}

/**
 * Minimal field list stub for entity reference fields in unit tests.
 */
final class TestFieldItemList {

  public function __construct(public readonly ?NodeInterface $entity) {}

  public function isEmpty(): bool {
    return $this->entity === NULL;
  }

}

/**
 * Minimal operational product field stub.
 */
final class TestOperationalFieldItemList {

  /**
   * @param array<string, mixed>|null $payload
   */
  public function __construct(private readonly ?array $payload) {}

  public function isEmpty(): bool {
    return $this->payload === NULL;
  }

  public function __get(string $name): mixed {
    if ($name === 'value') {
      return json_encode($this->payload ?? [], JSON_THROW_ON_ERROR);
    }
    return NULL;
  }

}
