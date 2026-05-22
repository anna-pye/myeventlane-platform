<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Language\LanguageDefault;
use Drupal\Core\StringTranslation\TranslationManager;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_commerce\Service\CustomerTicketTierDisplayBuilder;
use Drupal\myeventlane_commerce\Service\TicketAccessContext;
use Drupal\myeventlane_commerce\Service\TicketCustomerDisplayGatewayInterface;
use Drupal\myeventlane_commerce\Service\TicketTierWaitlistHoldCounterInterface;
use Drupal\myeventlane_event\Service\EventPaidTicketLoaderInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\CustomerTicketTierDisplayBuilder
 *
 * @group myeventlane_commerce
 */
final class CustomerTicketTierDisplayBuilderTest extends UnitTestCase {

  /**
   * @covers ::buildForEvent
   */
  public function testPurchasableTierAppearsInVisibleRows(): void {
    $event = $this->eventNode(42, TRUE);
    $product = $this->createMock(ProductInterface::class);
    $product->method('isPublished')->willReturn(TRUE);
    $product->method('id')->willReturn('7');
    $product->method('getCacheTags')->willReturn(['commerce_product:7']);
    $product->method('getVariations')->willReturn([]);

    $variation = $this->variation(11, '10.00', 'General admission');
    $tier = $this->tier(5, 'General admission', TRUE, 'public', 50, TRUE, 11);

    $availability = $this->availabilityMock();
    $availability->method('buildDefaultCustomerAccessContext')->willReturn($this->customerContext(42));
    $availability->method('filterPurchasableVariations')->willReturn([$variation]);
    $availability->method('resolveTierForVariation')->willReturn($tier);
    $availability->method('countCompletedSoldForVariation')->willReturn(0);

    $lifecycle = $this->createMock(EventPaidTicketLoaderInterface::class);
    $lifecycle->method('loadOrderedTicketsForEvent')->willReturn([$tier]);

    $builder = $this->builder($availability, $lifecycle);
    $result = $builder->buildForEvent($event);

    $this->assertCount(1, $result['visible_rows']);
    $this->assertSame('General admission', $result['visible_rows'][0]['name']);
    $this->assertSame('$10.00', $result['visible_rows'][0]['price']);
    $this->assertSame('Available', $result['visible_rows'][0]['availability']);
    $this->assertSame([], $result['excluded_rows']);
  }

  /**
   * @covers ::buildForEvent
   */
  public function testHiddenTierIsExcludedNotVisible(): void {
    $event = $this->eventNode(42, TRUE);
    $product = $this->createMock(ProductInterface::class);
    $product->method('isPublished')->willReturn(TRUE);
    $product->method('id')->willReturn('7');
    $product->method('getCacheTags')->willReturn(['commerce_product:7']);
    $product->method('getVariations')->willReturn([]);

    $hidden_tier = $this->tier(8, 'Hidden VIP', TRUE, 'hidden', NULL);
    $variation = $this->variation(12, '20.00', 'Hidden VIP');

    $availability = $this->availabilityMock();
    $availability->method('buildDefaultCustomerAccessContext')->willReturn($this->customerContext(42));
    $availability->method('filterPurchasableVariations')->willReturn([]);
    $availability->method('explainVendorPreviewExclusion')->willReturn(
      'Hidden from checkout: ticket is not visible to this customer.'
    );
    $availability->method('resolveTierForVariation')->willReturn($hidden_tier);

    $lifecycle = $this->createMock(EventPaidTicketLoaderInterface::class);
    $lifecycle->method('loadOrderedTicketsForEvent')->willReturn([$hidden_tier]);

    $builder = $this->builder($availability, $lifecycle);
    $result = $builder->buildForEvent($event);

    $this->assertSame([], $result['visible_rows']);
    $this->assertCount(1, $result['excluded_rows']);
    $this->assertStringContainsString('Hidden from checkout', $result['excluded_rows'][0]['diagnostic']);
  }

  /**
   * @covers ::buildForEvent
   */
  public function testMissingVariationIsExcluded(): void {
    $event = $this->eventNode(42, TRUE);
    $tier = $this->tier(9, 'Broken tier', TRUE, 'public', NULL, FALSE);

    $availability = $this->availabilityMock();
    $availability->method('buildDefaultCustomerAccessContext')->willReturn($this->customerContext(42));
    $availability->method('filterPurchasableVariations')->willReturn([]);
    $availability->method('explainVendorPreviewExclusion')->willReturn(
      'Hidden from checkout: linked product variation is missing or unpublished.'
    );

    $lifecycle = $this->createMock(EventPaidTicketLoaderInterface::class);
    $lifecycle->method('loadOrderedTicketsForEvent')->willReturn([$tier]);

    $builder = $this->builder($availability, $lifecycle);
    $result = $builder->buildForEvent($event);

    $this->assertSame([], $result['visible_rows']);
    $this->assertStringContainsString('variation', strtolower($result['excluded_rows'][0]['diagnostic']));
  }

  private function builder(
    TicketCustomerDisplayGatewayInterface&MockObject $availability,
    ?EventPaidTicketLoaderInterface $lifecycle = NULL,
    ?TicketTierWaitlistHoldCounterInterface $waitlist = NULL,
  ): CustomerTicketTierDisplayBuilder {
    $lifecycle ??= $this->createMock(EventPaidTicketLoaderInterface::class);
    $lifecycle->method('loadOrderedTicketsForEvent')->willReturn([]);

    $formatter = $this->createMock(CurrencyFormatter::class);
    $formatter->method('format')->willReturnCallback(
      static fn (string $number, string $currency) => '$' . $number
    );

    $waitlist ??= $this->createMock(TicketTierWaitlistHoldCounterInterface::class);
    $waitlist->method('sumActiveOfferReserved')->willReturn(0);

    return new CustomerTicketTierDisplayBuilder(
      $availability,
      $lifecycle,
      $formatter,
      $waitlist,
      new TranslationManager(new LanguageDefault([])),
    );
  }

  /**
   * @return TicketCustomerDisplayGatewayInterface&MockObject
   */
  private function availabilityMock(): TicketCustomerDisplayGatewayInterface&MockObject {
    return $this->createMock(TicketCustomerDisplayGatewayInterface::class);
  }

  private function customerContext(int $event_id): TicketAccessContext {
    return new TicketAccessContext(
      eventId: $event_id,
      account: NULL,
      grantedTierIds: [],
      waitlistClaimEntryId: NULL,
      groupEligible: FALSE,
      routeSource: 'public_booking',
    );
  }

  private function eventNode(int $nid, bool $with_product): NodeInterface&MockObject {
    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn('event');
    $event->method('isNew')->willReturn(FALSE);
    $event->method('id')->willReturn((string) $nid);
    $event->method('getCacheTags')->willReturn(['node:' . $nid]);

    $product = NULL;
    if ($with_product) {
      $product = $this->createMock(ProductInterface::class);
      $product->method('isPublished')->willReturn(TRUE);
      $product->method('id')->willReturn('7');
      $product->method('getCacheTags')->willReturn(['commerce_product:7']);
      $product->method('getVariations')->willReturn([]);
    }

    $product_field = new EntityReferenceFieldStub($product);
    $ticket_types_field = new EntityReferenceFieldStub(NULL, TRUE);
    $empty_field = new EntityReferenceFieldStub(NULL, TRUE);

    $event->method('hasField')->willReturnCallback(
      static fn (string $name) => in_array($name, ['field_product_target', 'field_ticket_types'], TRUE)
    );
    $event->method('get')->willReturnCallback(
      static function (string $name) use ($product_field, $ticket_types_field, $empty_field) {
        return match ($name) {
          'field_product_target' => $product_field,
          'field_ticket_types' => $ticket_types_field,
          default => $empty_field,
        };
      }
    );

    return $event;
  }

  private function variation(int $id, string $amount, string $label): ProductVariationInterface&MockObject {
    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('id')->willReturn((string) $id);
    $variation->method('uuid')->willReturn('uuid-' . $id);
    $variation->method('label')->willReturn($label);
    $variation->method('getPrice')->willReturn(new Price($amount, 'AUD'));
    return $variation;
  }

  /**
   * @return TicketTypeInterface&MockObject
   */
  private function tier(
    int $id,
    string $title,
    bool $published,
    string $visibility,
    ?int $capacity,
    bool $with_variation = TRUE,
    ?int $variation_id = NULL,
  ): TicketTypeInterface&MockObject {
    $tier = $this->createStub(TicketTypeInterface::class);
    $tier->method('id')->willReturn((string) $id);
    $tier->method('getTitle')->willReturn($title);
    $tier->method('label')->willReturn($title);
    $tier->method('getTicketKind')->willReturn('paid');
    $tier->method('isArchived')->willReturn(FALSE);
    $tier->method('getCacheTags')->willReturn(['mel_ticket_type:' . $id]);
    $tier->method('hasField')->willReturnCallback(
      static fn (string $name) => in_array($name, ['visibility_mode', 'capacity', 'short_description', 'commerce_variation'], TRUE)
    );

    $variation = $with_variation
      ? $this->variation($variation_id ?? ($id + 100), '10.00', $title)
      : NULL;
    $empty_field = new ScalarFieldStub(NULL, TRUE);
    $tier->method('get')->willReturnCallback(
      static function (string $name) use ($visibility, $capacity, $variation, $empty_field) {
        return match ($name) {
          'visibility_mode' => new ScalarFieldStub($visibility),
          'capacity' => new ScalarFieldStub($capacity === NULL ? NULL : (string) $capacity, $capacity === NULL),
          'commerce_variation' => new EntityReferenceFieldStub($variation),
          'short_description' => new ScalarFieldStub(NULL, TRUE),
          default => $empty_field,
        };
      }
    );

    return $tier;
  }

}

/**
 * Minimal field list stub for entity reference fields in unit tests.
 */
final class EntityReferenceFieldStub {

  public mixed $entity;

  public mixed $target_id;

  public function __construct(
    mixed $entity,
    private readonly bool $empty = FALSE,
  ) {
    $this->entity = $entity;
    $this->target_id = is_object($entity) && method_exists($entity, 'id') ? $entity->id() : NULL;
  }

  public function isEmpty(): bool {
    return $this->empty;
  }

}

/**
 * Minimal field list stub for scalar ticket fields in unit tests.
 */
final class ScalarFieldStub {

  public mixed $value;

  public function __construct(
    mixed $value,
    private readonly bool $empty = FALSE,
  ) {
    $this->value = $value;
  }

  public function isEmpty(): bool {
    return $this->empty;
  }

}
