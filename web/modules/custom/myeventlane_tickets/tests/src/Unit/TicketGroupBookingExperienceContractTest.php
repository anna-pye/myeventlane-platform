<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the organiser and buyer ticket-group experience contract.
 *
 * @group myeventlane_tickets
 */
final class TicketGroupBookingExperienceContractTest extends TestCase {

  private function moduleRoot(): string {
    return dirname(__DIR__, 3);
  }

  public function testGroupEntityTargetsTicketTypesInsteadOfProducts(): void {
    $entity = file_get_contents($this->moduleRoot() . '/src/Entity/TicketGroup.php');
    $this->assertNotFalse($entity);
    $this->assertStringContainsString("\$fields['ticket_types']", $entity);
    $this->assertStringContainsString("->setSetting('target_type', 'mel_ticket_type')", $entity);
    $this->assertStringContainsString('Legacy ticket products', $entity);
  }

  public function testOrganiserFormExplainsWhatGroupsDo(): void {
    $form = file_get_contents($this->moduleRoot() . '/src/Form/TicketGroupForm.php');
    $this->assertNotFalse($form);
    $this->assertStringContainsString('A section organises tickets under a heading', $form);
    $this->assertStringContainsString('A bundle sells a fixed mix of tickets for one total price', $form);
    $this->assertStringContainsString("'#title' => \$this->t('Tickets in this section')", $form);
    $this->assertStringContainsString("\$form['bundle_components_picker']", $form);
    $this->assertStringContainsString('Price for one complete bundle', $form);
    $this->assertStringContainsString("\$form['event']['#access'] = FALSE", $form);
    $this->assertStringContainsString("\$form['ticket_products']['#access'] = FALSE", $form);
  }

  public function testPublicBookingFormRendersEnabledGroupSections(): void {
    $booking = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_commerce/src/Form/TicketSelectionForm.php');
    $this->assertNotFalse($booking);
    $this->assertStringContainsString('loadTicketGroupDisplay', $booking);
    $this->assertStringContainsString("->condition('status', 1)", $booking);
    $this->assertStringContainsString('mel-ticket-booking-group__title', $booking);
    $this->assertStringContainsString('buildTicketBundleOptions', $booking);
    $this->assertStringContainsString('addTicketBundleToCart', $booking);
    $this->assertStringContainsString("setData('mel_ticket_bundle_instance'", $booking);
    $this->assertStringContainsString("setData('mel_ticket_bundle_gross_unit_price'", $booking);
    $this->assertStringContainsString('ticketBundlePriceAllocator->allocateUnitPrices', $booking);
    $this->assertStringContainsString('->lock()', $booking);
    $this->assertStringContainsString('removeAddedBundleItems', $booking);
    $this->assertSame(2, substr_count($booking, '$this->removeAddedBundleItems($cart, $addedBundleItems)'));
    $this->assertStringContainsString("'name' => (string) \$this->t('Other tickets')", $booking);
  }

  public function testBundleCartKeepsPricingStableAndRemovesComponentsTogether(): void {
    $commerce_root = dirname(__DIR__, 4) . '/myeventlane_commerce';
    $preprocessor = file_get_contents($commerce_root . '/src/OrderPreprocessor/TicketBundlePricePreprocessor.php');
    $tax_cleanup = file_get_contents($commerce_root . '/src/OrderProcessor/TicketBundleTaxMarkerCleanupProcessor.php');
    $remove_form = file_get_contents($commerce_root . '/src/Form/RemoveTicketBundleForm.php');
    $routing = file_get_contents($commerce_root . '/myeventlane_commerce.routing.yml');
    $cart_controller = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_core/src/Controller/CartPageController.php');
    $this->assertNotFalse($preprocessor);
    $this->assertNotFalse($tax_cleanup);
    $this->assertNotFalse($remove_form);
    $this->assertNotFalse($routing);
    $this->assertNotFalse($cart_controller);
    $this->assertStringContainsString("getData('mel_ticket_bundle_gross_unit_price'", $preprocessor);
    $this->assertStringContainsString('setUnitPrice(new Price($number, $currency), TRUE)', $preprocessor);
    $this->assertStringContainsString('TAX_PLACEHOLDER_SOURCE', $preprocessor);
    $this->assertStringContainsString('removeAdjustment($adjustment)', $tax_cleanup);
    $this->assertStringContainsString('myeventlane_commerce.ticket_bundle_remove:', $routing);
    $this->assertStringContainsString('All tickets included in this bundle will be removed together.', $remove_form);
    $this->assertStringContainsString("getData('mel_ticket_bundle_instance'", $remove_form);
    $this->assertStringContainsString('buildTicketBundleControls', $cart_controller);
  }

  public function testBundleFieldsAndDatabaseUpdateAreDeclared(): void {
    $entity = file_get_contents($this->moduleRoot() . '/src/Entity/TicketGroup.php');
    $install = file_get_contents($this->moduleRoot() . '/myeventlane_tickets.install');
    $this->assertNotFalse($entity);
    $this->assertNotFalse($install);
    foreach (['group_mode', 'bundle_price', 'bundle_components'] as $field_name) {
      $this->assertStringContainsString("\$fields['{$field_name}']", $entity);
      $this->assertStringContainsString("'{$field_name}'", $install);
    }
    $this->assertStringContainsString('function myeventlane_tickets_update_8015', $install);
  }

  public function testDatabaseUpdateMigratesLegacyProductAssignments(): void {
    $install = file_get_contents($this->moduleRoot() . '/myeventlane_tickets.install');
    $this->assertNotFalse($install);
    $this->assertStringContainsString('function myeventlane_tickets_update_8012', $install);
    $this->assertStringContainsString("installFieldStorageDefinition(\n      'ticket_types'", $install);
    $this->assertStringContainsString("\$group->set('ticket_types', \$ticket_ids)", $install);
  }

}
