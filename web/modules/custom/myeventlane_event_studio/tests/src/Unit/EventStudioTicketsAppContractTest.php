<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract tests for VX2 Sprint 3 Tickets app convergence.
 *
 * @group myeventlane_event_studio
 */
final class EventStudioTicketsAppContractTest extends TestCase {

  private function moduleRoot(): string {
    return dirname(__DIR__, 3);
  }

  public function testTicketsStackUsesProgressiveAdvancedTools(): void {
    $renderer = file_get_contents($this->moduleRoot() . '/src/Service/EventStudioSectionRenderer.php');
    $this->assertNotFalse($renderer);
    $this->assertStringContainsString('buildAdvancedTicketTools', $renderer);
    $this->assertStringContainsString('Advanced Ticket Tools', $renderer);
    $this->assertStringContainsString('advanced_tools_opened', $renderer);
    $this->assertStringContainsString('mel-event-studio-tickets-app', $renderer);
  }

  public function testOperationalTicketsFormUsesOrganiserLanguage(): void {
    $form = file_get_contents($this->moduleRoot() . '/src/Form/EventStudioOperationalTicketsForm.php');
    $this->assertNotFalse($form);
    $this->assertStringContainsString("\$this->t('Add Ticket')", $form);
    $this->assertStringContainsString("\$this->t('Add your first ticket')", $form);
    $this->assertStringContainsString('Duplicate ticket', $form);
    $this->assertStringContainsString('Archive ticket', $form);
    $this->assertStringContainsString('ticket_created', $form);
    $this->assertStringContainsString('ticket_archived', $form);
    $this->assertStringNotContainsString('Commerce Product', $form);
    $this->assertStringNotContainsString('Create Product', $form);
    $this->assertStringNotContainsString('SKU', $form);
  }

  public function testDuplicateAppliesSameRequestEditsBeforeCopy(): void {
    $form = file_get_contents($this->moduleRoot() . '/src/Form/EventStudioOperationalTicketsForm.php');
    $this->assertNotFalse($form);
    $this->assertStringContainsString('updateAndDuplicateTicketOnEvent', $form);
    $this->assertStringContainsString('buildDuplicateTicketTitle', $form);
    $this->assertStringNotContainsString(
      'duplicateTicketOnEvent($event, $ticket, $this->currentUser)',
      $form,
      'Form must use the transactional updateAndDuplicate helper, not a bare duplicate call.',
    );
  }

  public function testBookingModeHidesCommerceProductForOrganisers(): void {
    $form = file_get_contents($this->moduleRoot() . '/src/Form/EventStudioTicketsForm.php');
    $this->assertNotFalse($form);
    $this->assertStringContainsString("administer commerce_product", $form);
    $this->assertStringContainsString('organisers never manage Commerce products directly', $form);
  }

  public function testTicketManagerRemovesCommerceLeakCopy(): void {
    $form = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_vendor/src/Form/EventTicketManagerForm.php');
    $this->assertNotFalse($form);
    $this->assertStringNotContainsString('linked Commerce ticket product', $form);
    $this->assertStringContainsString('Event Workspace → Tickets', $form);
  }

  public function testLifecycleSupportsDuplicateTicket(): void {
    $lifecycle = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_event/src/Service/TicketTierLifecycleService.php');
    $this->assertNotFalse($lifecycle);
    $this->assertStringContainsString('function duplicateTicketOnEvent', $lifecycle);
    $this->assertStringContainsString('function updateAndDuplicateTicketOnEvent', $lifecycle);
    $this->assertStringContainsString('function buildDuplicateTicketTitle', $lifecycle);
    $this->assertStringContainsString('function applyTicketValuesWithoutSave', $lifecycle);
    $this->assertStringContainsString('startTransaction', $lifecycle);
    $this->assertStringContainsString("'waitlist_enabled'", $lifecycle);
    $this->assertStringContainsString("'hidden_label'", $lifecycle);
    $this->assertStringContainsString("'group_sale_mode'", $lifecycle);
    $this->assertStringContainsString('too long to duplicate', $lifecycle);
    $updatePos = strpos($lifecycle, '$this->updateTicketType($ticket, $event, []);');
    $duplicatePos = strpos($lifecycle, 'return $this->duplicateTicketOnEvent($event, $ticket, $account);');
    $this->assertNotFalse($updatePos);
    $this->assertNotFalse($duplicatePos);
    $this->assertLessThan(
      $duplicatePos,
      $updatePos,
      'Transactional helper must persist source before creating the copy.',
    );
  }

  public function testTicketsAppAssetsExist(): void {
    $this->assertFileExists($this->moduleRoot() . '/js/mel-event-studio-tickets-app.js');
    $js = file_get_contents($this->moduleRoot() . '/js/mel-event-studio-tickets-app.js');
    $this->assertNotFalse($js);
    $this->assertStringContainsString('mel-tickets-sticky-add', $js);
    $this->assertStringContainsString('openAddTicketPanel', $js);
    $this->assertStringContainsString('details.open = true', $js);
    $css = file_get_contents($this->moduleRoot() . '/css/mel-event-studio-shell.css');
    $this->assertNotFalse($css);
    $this->assertStringContainsString('mel-event-studio-ticket-sticky-add', $css);
    $this->assertStringContainsString('mel-event-studio-advanced-tools', $css);
  }

  public function testRsvpSalesSummaryUsesEventRsvpCount(): void {
    $form = file_get_contents($this->moduleRoot() . '/src/Form/EventStudioOperationalTicketsForm.php');
    $this->assertNotFalse($form);
    $this->assertStringContainsString('RsvpCapacityService', $form);
    $this->assertStringContainsString('countConfirmedRsvps', $form);
    $this->assertStringContainsString("RSVPs: 1 received", $form);
    $this->assertStringContainsString("getTicketKind() === 'rsvp'", $form);
  }

  public function testDuplicateAndArchiveAreMutuallyExclusive(): void {
    $form = file_get_contents($this->moduleRoot() . '/src/Form/EventStudioOperationalTicketsForm.php');
    $this->assertNotFalse($form);
    $this->assertStringContainsString('Choose either Duplicate ticket or Archive ticket, not both.', $form);
    $this->assertStringContainsString("!empty(\$row['archive']) && !empty(\$row['duplicate'])", $form);
    $this->assertStringContainsString("!empty(\$row['archive']) && empty(\$row['duplicate'])", $form);
  }

  public function testOperationalFormAttachesTicketsAppLibrary(): void {
    $form = file_get_contents($this->moduleRoot() . '/src/Form/EventStudioOperationalTicketsForm.php');
    $this->assertNotFalse($form);
    $this->assertStringContainsString("myeventlane_event_studio/mel_event_studio_tickets_app", $form);
  }

}
