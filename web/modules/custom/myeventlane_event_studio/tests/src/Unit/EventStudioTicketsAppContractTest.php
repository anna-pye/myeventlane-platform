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

  public function testSuccessfulTicketSaveReloadsTheCompleteWorkspace(): void {
    $form = file_get_contents($this->moduleRoot() . '/src/Form/EventStudioOperationalTicketsForm.php');

    $this->assertNotFalse($form);
    $this->assertStringContainsString("'Tickets saved and synced.'", $form);
    $this->assertStringContainsString(
      "\$form_state->setRedirect('myeventlane_event_studio.workspace_tickets'",
      $form,
    );
    $this->assertStringNotContainsString('$form_state->setRebuild(TRUE);', $form);
  }

  public function testTicketJourneyProgressesFromSavedBookingChoice(): void {
    $ticketsForm = file_get_contents($this->moduleRoot() . '/src/Form/EventStudioTicketsForm.php');
    $renderer = file_get_contents($this->moduleRoot() . '/src/Service/EventStudioSectionRenderer.php');

    $this->assertNotFalse($ticketsForm);
    $this->assertStringContainsString("return \$this->t('Save booking settings')", $ticketsForm);
    $this->assertStringContainsString("'myeventlane_event_studio.workspace_tickets'", $ticketsForm);
    $this->assertStringContainsString("'Booking settings saved.'", $ticketsForm);
    $this->assertStringNotContainsString("'#title' => \$this->t('Collect extra attendee details')", $ticketsForm);
    $this->assertStringContainsString("'myeventlane_event_studio.workspace_questions'", $ticketsForm);
    $this->assertStringContainsString('Manage attendee questions', $ticketsForm);
    $this->assertStringContainsString('Collection turns on automatically when an active question is saved.', $ticketsForm);

    $this->assertNotFalse($renderer);
    $this->assertStringContainsString("\$uses_ticket_types = in_array(\$event_type, ['paid', 'both'], TRUE)", $renderer);
    $this->assertStringContainsString('if ($uses_ticket_types)', $renderer);
    $this->assertStringContainsString('if ($preview_ready && $this->eventTicketPreviewBuilder', $renderer);
    $this->assertStringContainsString('if ($uses_ticket_types && $has_ticket_setup)', $renderer);
  }

  public function testOptionalSettingsAndSalesWindowResetAreFunctional(): void {
    $form = file_get_contents($this->moduleRoot() . '/src/Form/EventStudioOperationalTicketsForm.php');
    $javascript = file_get_contents($this->moduleRoot() . '/js/mel-event-studio-tickets-app.js');

    $this->assertNotFalse($form);
    $optionalSettings = strpos($form, "'advanced' => [");
    $bestValue = strpos($form, "'#title' => \$this->t('Highlight as best value')");
    $this->assertNotFalse($optionalSettings);
    $this->assertNotFalse($bestValue);
    $this->assertGreaterThan($optionalSettings, $bestValue);
    $this->assertStringContainsString("'#value' => \$this->t('Clear sales window')", $form);
    $this->assertStringContainsString('data-mel-reset-sales-window', $form);
    $this->assertStringContainsString("'type' => 'button'", $form);
    $this->assertStringContainsString('mel-event-studio-ticket-card__reset-status', $form);

    $this->assertNotFalse($javascript);
    $this->assertStringContainsString('function resetSalesWindow(button)', $javascript);
    $this->assertStringContainsString("field.value = ''", $javascript);
    $this->assertStringContainsString("field.defaultValue = ''", $javascript);
    $this->assertStringContainsString("field.removeAttribute('value')", $javascript);
    $this->assertStringContainsString("field.setAttribute('autocomplete', 'off')", $javascript);
    $this->assertStringContainsString("new Event('input', { bubbles: true })", $javascript);
    $this->assertStringContainsString('field.blur()', $javascript);
    $this->assertStringNotContainsString('firstField.focus()', $javascript);
    $this->assertStringContainsString('Sales window cleared. Save tickets to keep this change.', $javascript);
  }

  public function testBestValueHighlightIsOptional(): void {
    $lifecycle = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_event/src/Service/TicketTierLifecycleService.php');
    $manager = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_event/src/Service/TicketTypeManager.php');

    $this->assertNotFalse($lifecycle);
    $this->assertStringNotContainsString('BEST_VALUE_REQUIRED_MESSAGE', $lifecycle);
    $this->assertStringNotContainsString('Choose one Best value ticket', $lifecycle);
    $this->assertStringNotContainsString('validateBestValueSelectionForRows', $lifecycle);

    $this->assertNotFalse($manager);
    $this->assertStringContainsString('normalizeBestValueTicketSelection', $manager);
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

  public function testTicketActionsAreMutuallyExclusive(): void {
    $form = file_get_contents($this->moduleRoot() . '/src/Form/EventStudioOperationalTicketsForm.php');
    $this->assertNotFalse($form);
    $this->assertStringContainsString('Choose only one ticket action: Duplicate, Archive, or permanently delete.', $form);
    $this->assertStringContainsString("'delete' => !empty(\$row['delete'])", $form);
    $this->assertStringContainsString('if (count($selected_actions) > 1)', $form);
    $this->assertStringContainsString("!empty(\$row['archive']) && empty(\$row['duplicate'])", $form);
  }

  public function testPermanentDeletionUsesLifecycleGuard(): void {
    $form = file_get_contents($this->moduleRoot() . '/src/Form/EventStudioOperationalTicketsForm.php');
    $lifecycle = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_event/src/Service/TicketTierLifecycleService.php');
    $guard = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_event/src/Service/TicketTierDeletionGuard.php');

    $this->assertNotFalse($form);
    $this->assertStringContainsString('Permanently delete this ticket when I save', $form);
    $this->assertStringContainsString('evaluateTicketDeletion', $form);
    $this->assertStringContainsString('deleteTicketOnEvent', $form);
    $this->assertStringContainsString('ticket_deleted', $form);
    $this->assertStringContainsString('Choose only one ticket action', $form);
    $this->assertStringContainsString("'#default_value' => 0", $form);

    $this->assertNotFalse($lifecycle);
    $this->assertStringContainsString('function evaluateTicketDeletion', $lifecycle);
    $this->assertStringContainsString('function deleteTicketOnEvent', $lifecycle);
    $this->assertStringContainsString('$this->archiveTicketOnEvent($event, $ticket)', $lifecycle);
    $this->assertStringContainsString('$ticket->delete()', $lifecycle);

    $this->assertNotFalse($guard);
    $this->assertStringContainsString("'commerce_order_item'", $guard);
    $this->assertStringContainsString("'myeventlane_ticket'", $guard);
    $this->assertStringContainsString("'mel_ticket_waitlist_entry'", $guard);
    $this->assertStringContainsString("'mel_access_code'", $guard);
    $this->assertStringContainsString("'inspection_failed'", $guard);

    $javascript = file_get_contents($this->moduleRoot() . '/js/mel-event-studio-tickets-app.js');
    $this->assertNotFalse($javascript);
    $this->assertStringContainsString('mel-tickets-delete-safe-default', $javascript);
    $this->assertStringContainsString('checkbox.checked = false', $javascript);
  }

  public function testOperationalFormAttachesTicketsAppLibrary(): void {
    $form = file_get_contents($this->moduleRoot() . '/src/Form/EventStudioOperationalTicketsForm.php');
    $this->assertNotFalse($form);
    $this->assertStringContainsString("myeventlane_event_studio/mel_event_studio_tickets_app", $form);
  }

}
