<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event\Unit;

use Drupal\myeventlane_event\EventCard\EventMerchandisingPresenter;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event\EventCard\EventMerchandisingPresenter
 * @group myeventlane_event
 */
final class EventMerchandisingPresenterTest extends UnitTestCase {

  /**
   * @covers ::present
   */
  public function testAttendanceOutranksPriceOnSpotlight(): void {
    $presenter = $this->createPresenter();
    $result = $presenter->present([
      'layout' => 'spotlight',
      'attendance' => 52,
      'price_label' => 'From $25',
      'card_type' => 'paid',
      'is_sold_out' => FALSE,
    ]);

    self::assertSame('attendance', $result['primary']['signal_key'] ?? '');
    self::assertSame('community', $result['primary']['modifier'] ?? '');
    self::assertFalse($result['show_ticket_type_pill']);
    self::assertNull($result['secondary']);
    self::assertNull($result['spotlight_chip']);
  }

  /**
   * @covers ::present
   */
  public function testSingleSignalNoSecondaryWhenAttendanceOnSpotlight(): void {
    $presenter = $this->createPresenter();
    $result = $presenter->present([
      'layout' => 'spotlight',
      'attendance' => 52,
      'is_low_stock' => TRUE,
      'card_type' => 'paid',
      'price_label' => 'From $25',
      'is_sold_out' => FALSE,
    ]);

    self::assertSame('attendance', $result['primary']['signal_key'] ?? '');
    self::assertNull($result['secondary']);
    self::assertNull($result['spotlight_chip']);
    self::assertNull($result['urgency']);
  }

  /**
   * @covers ::present
   */
  public function testSoldOutIsPrimarySignal(): void {
    $presenter = $this->createPresenter();
    $result = $presenter->present([
      'layout' => 'poster',
      'attendance' => 10,
      'is_sold_out' => TRUE,
      'card_type' => 'paid',
      'price_label' => 'From $25',
    ]);

    self::assertSame('sold_out', $result['primary']['signal_key'] ?? '');
    self::assertSame('Sold out', $result['image_badge_label']);
    self::assertSame('warning', $result['image_badge_modifier']);
    self::assertFalse($result['show_ticket_type_pill']);
  }

  /**
   * @covers ::present
   */
  public function testHeroProofUsesAttendanceBeforeUrgency(): void {
    $presenter = $this->createPresenter();
    $result = $presenter->present([
      'layout' => 'hero',
      'attendance' => 8,
      'is_low_stock' => TRUE,
      'card_type' => 'paid',
    ]);

    self::assertSame('attendance', $result['hero_proof']['signal_key'] ?? '');
    self::assertSame('attendance', $result['primary']['signal_key'] ?? '');
  }

  /**
   * @covers ::present
   */
  public function testTicketPillOnlyWhenNoPrimarySignal(): void {
    $presenter = $this->createPresenter();
    $result = $presenter->present([
      'layout' => 'poster',
      'card_type' => 'paid',
      'price_label' => 'From $25',
      'is_sold_out' => FALSE,
    ]);

    self::assertNull($result['primary']);
    self::assertTrue($result['show_ticket_type_pill']);
    self::assertSame('From $25', $result['ticket_pill_label']);
  }

  /**
   * @covers ::present
   */
  public function testRsvpDefersActionToCtaChip(): void {
    $presenter = $this->createPresenter();
    $result = $presenter->present([
      'layout' => 'spotlight',
      'attendance' => 24,
      'card_type' => 'rsvp',
      'is_sold_out' => FALSE,
    ]);

    self::assertFalse($result['show_ticket_type_pill']);
    self::assertNull($result['ticket_pill_label']);
  }

  /**
   * @covers ::present
   */
  public function testSpotlightBadgeWhenPromotedOnSpotlight(): void {
    $presenter = $this->createPresenter();
    $result = $presenter->present([
      'layout' => 'spotlight',
      'is_promoted' => TRUE,
      'card_type' => 'rsvp',
    ]);

    self::assertSame('Spotlight', $result['image_badge_label']);
    self::assertSame('apricot', $result['image_badge_modifier']);
  }

  /**
   * @covers ::present
   */
  public function testSoldOutOutranksSpotlightOnPromotedPoster(): void {
    $presenter = $this->createPresenter();
    $result = $presenter->present([
      'layout' => 'poster',
      'is_promoted' => TRUE,
      'is_sold_out' => TRUE,
      'card_type' => 'paid',
    ]);

    self::assertSame('Sold out', $result['image_badge_label']);
    self::assertSame('warning', $result['image_badge_modifier']);
  }

  /**
   * @covers ::present
   */
  public function testSpotlightBadgeWhenPromotedOnPoster(): void {
    $presenter = $this->createPresenter();
    $result = $presenter->present([
      'layout' => 'poster',
      'is_promoted' => TRUE,
      'card_type' => 'rsvp',
    ]);

    self::assertSame('Spotlight', $result['image_badge_label']);
    self::assertSame('apricot', $result['image_badge_modifier']);
  }

  private function createPresenter(): EventMerchandisingPresenter {
    return new EventMerchandisingPresenter($this->getStringTranslationStub());
  }

}
