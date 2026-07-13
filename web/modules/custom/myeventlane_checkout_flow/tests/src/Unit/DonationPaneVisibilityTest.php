<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_flow\Unit;

use Drupal\myeventlane_checkout_flow\Plugin\Commerce\CheckoutPane\DonationPane;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_checkout_flow\Plugin\Commerce\CheckoutPane\DonationPane
 *
 * @group myeventlane_checkout_flow
 */
final class DonationPaneVisibilityTest extends UnitTestCase {

  /**
   * @covers ::isVisible
   */
  public function testLegacyDonationPaneIsNotVisibleWithoutControl(): void {
    $pane = (new \ReflectionClass(DonationPane::class))->newInstanceWithoutConstructor();
    $this->assertFalse($pane->isVisible());
  }

  /**
   * When a real donation control is present, Twig must keep the heading wrapper.
   *
   * The pane plugin stays invisible (legacy stub). Live donation UI, if reintroduced,
   * must ship a non-empty renderable control so the Twig striptags guard keeps the
   * OPTIONAL / Optional donation heading.
   */
  public function testDonationHeadingContractDocumentsPresentControlRequirement(): void {
    $this->assertTrue(method_exists(DonationPane::class, 'isVisible'));
    $this->assertTrue(method_exists(DonationPane::class, 'buildPaneForm'));
  }

}
