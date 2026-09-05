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
    $this->assertStringContainsString('Merchandise & add-ons', $renderer);
    $this->assertStringContainsString("'myeventlane_event_studio.workspace_extras'", $renderer);
    $this->assertStringContainsString('mel-event-studio-sales-navigation', $renderer);
    $this->assertStringContainsString('mel-event-studio-ticket-workspace', $renderer);
    $this->assertStringContainsString("\$build['ticket_workspace']['operational']['ticket_preview'] = \$preview;", $renderer);
  }

  public function testMasterDetailKeepsEveryTicketControlInTheSubmittedForm(): void {
    $form = file_get_contents($this->moduleRoot() . '/src/Form/EventStudioOperationalTicketsForm.php');
    $javascript = file_get_contents($this->moduleRoot() . '/js/mel-event-studio-tickets-app.js');
    $css = file_get_contents($this->moduleRoot() . '/css/mel-event-studio-shell.css');

    $this->assertNotFalse($form);
    $this->assertStringContainsString('data-mel-ticket-select', $form);
    $this->assertStringContainsString('data-mel-ticket-editor', $form);
    $this->assertStringContainsString('data-mel-ticket-selector-name-label', $form);
    $this->assertStringContainsString('data-mel-ticket-selector-price-label', $form);
    $this->assertStringContainsString('data-mel-ticket-editor-heading', $form);
    $this->assertStringContainsString("\$this->t('Price (AUD)')", $form);
    $this->assertStringContainsString("number_format((float) \$price->getNumber(), 2, '.', '')", $form);
    $this->assertStringContainsString("\$form['tickets'][\$ticket_id]", $form);
    $this->assertStringContainsString("\$form['new_ticket']['quick_add'] = \$form['quick_add']", $form);

    $this->assertNotFalse($javascript);
    $this->assertStringContainsString('function selectTicketEditor(form, ticketId, focusEditor)', $javascript);
    $this->assertStringContainsString("form.classList.add('is-master-detail-ready')", $javascript);
    $this->assertStringContainsString('function updateTicketSelector(form, field)', $javascript);
    $this->assertStringContainsString("selector.querySelector('[data-mel-ticket-selector-name-label]')", $javascript);
    $this->assertStringContainsString("card.querySelector('[data-mel-ticket-editor-heading]')", $javascript);
    $this->assertStringNotContainsString('removeChild', $javascript);

    $this->assertNotFalse($css);
    $this->assertStringContainsString('.is-master-detail-ready [data-mel-ticket-editor]:not(.is-selected)', $css);
    $this->assertStringContainsString('.mel-event-studio-ticket-selector', $css);
  }

  public function testCompactWorkspaceOverridesTheLegacyWideGrid(): void {
    $css = file_get_contents($this->moduleRoot() . '/css/mel-event-studio-shell.css');
    $libraries = file_get_contents($this->moduleRoot() . '/myeventlane_event_studio.libraries.yml');
    $theme_scss = file_get_contents(
      dirname($this->moduleRoot(), 4) . '/web/themes/custom/myeventlane_vendor_theme/src/scss/components/_mel-event-studio-ticket-hierarchy.scss',
    );

    $this->assertNotFalse($css);
    $this->assertStringContainsString(
      ".mel-vendor .mel-event-studio[data-current-section-id='tickets'] .mel-event-studio-tickets-app",
      $css,
    );
    $this->assertStringContainsString('flex-direction: column;', $css);
    $this->assertStringContainsString('grid-template-columns: none;', $css);
    $this->assertStringContainsString('.mel-event-studio-ticket-workspace > *', $css);
    $this->assertStringContainsString('@media (max-width: 1199px)', $css);
    $this->assertStringContainsString(".mel-event-studio[data-current-section-id='tickets'] .mel-event-studio-operational-tickets", $css);
    $this->assertStringContainsString('order: 5;', $css);

    $this->assertNotFalse($libraries);
    $this->assertStringContainsString("mel_event_studio:\n  version: 1.33", $libraries);
    $this->assertStringContainsString("mel_event_studio_shell_only:\n  version: 1.23", $libraries);

    $this->assertNotFalse($theme_scss);
    $this->assertStringContainsString('.mel-event-studio--workspace .mel-event-studio-tickets-app', $theme_scss);
    $this->assertStringContainsString('display: flex;', $theme_scss);
    $this->assertStringContainsString('flex-direction: column;', $theme_scss);
    $this->assertStringContainsString('@media (max-width: 1199px)', $theme_scss);
  }

  public function testTicketEditorUsesTheAvailableCanvasWithoutCrushingFields(): void {
    $css = file_get_contents($this->moduleRoot() . '/css/mel-event-studio-shell.css');
    $theme_scss = file_get_contents(
      dirname($this->moduleRoot(), 4) . '/web/themes/custom/myeventlane_vendor_theme/src/scss/components/_mel-event-studio-ticket-hierarchy.scss',
    );

    $this->assertNotFalse($css);
    $this->assertStringContainsString('--mel-es-content-width: 76rem;', $css);
    $this->assertStringContainsString("'identity identity'", $css);
    $this->assertStringContainsString('.mel-event-studio-ticket-card__identity,', $css);
    $this->assertStringContainsString('width: 100%;', $css);
    $this->assertStringContainsString("input[type='text']", $css);
    $this->assertStringContainsString('inline-size: 100% !important;', $css);
    $this->assertStringContainsString('minmax(30rem, 1.7fr)', $css);
    $this->assertStringContainsString(
      ".mel-event-studio--workspace[data-current-section-id='tickets']\n  .mel-form",
      $css,
    );
    $this->assertStringNotContainsString(
      ".mel-event-studio--workspace[data-current-section-id='tickets']\n  .mel-event-studio-wizard-form.mel-form",
      $css,
    );
    $this->assertStringContainsString('max-width: none;', $css);

    $this->assertNotFalse($theme_scss);
    $this->assertStringContainsString('--mel-es-content-width: 76rem;', $theme_scss);
    $this->assertStringContainsString("'identity identity'", $theme_scss);
    $this->assertStringContainsString('inline-size: 100% !important;', $theme_scss);
    $this->assertStringNotContainsString(
      'grid-template-columns: minmax(0, 1fr) minmax(8rem, 0.42fr) minmax(9rem, 0.48fr);',
      $theme_scss,
    );
  }

  public function testMobileTicketEditorUsesOneRealColumnAndFullWidthControls(): void {
    $css = file_get_contents($this->moduleRoot() . '/css/mel-event-studio-shell.css');
    $theme_scss = file_get_contents(
      dirname($this->moduleRoot(), 4) . '/web/themes/custom/myeventlane_vendor_theme/src/scss/components/_mel-event-studio-ticket-hierarchy.scss',
    );

    $this->assertNotFalse($css);
    $this->assertStringContainsString('@media (max-width: 760px)', $css);
    $this->assertStringContainsString(
      "grid-template-areas:\n      'identity'\n      'pricing'\n      'capacity'\n      'badges';",
      $css,
    );
    $this->assertStringContainsString(':is(.form-item, input, select)', $css);
    $this->assertStringContainsString('min-height: 46px;', $css);
    $this->assertStringContainsString('font-size: 1rem;', $css);
    $this->assertStringContainsString('min-height: 48px;', $css);

    $this->assertNotFalse($theme_scss);
    $this->assertStringContainsString('@media (max-width: 767px)', $theme_scss);
    $this->assertStringContainsString(
      "grid-template-areas:\n      'identity'\n      'pricing'\n      'capacity'\n      'badges';",
      $theme_scss,
    );
    $this->assertStringContainsString(':is(.form-item, input, select)', $theme_scss);
  }

  public function testQuickAddPresetsPopulateTheExistingTicketFormWithoutSaving(): void {
    $form = file_get_contents($this->moduleRoot() . '/src/Form/EventStudioOperationalTicketsForm.php');
    $javascript = file_get_contents($this->moduleRoot() . '/js/mel-event-studio-tickets-app.js');

    $this->assertNotFalse($form);
    $this->assertStringContainsString('Quick add a ticket type', $form);
    $this->assertStringContainsString('data-mel-ticket-preset', $form);
    $this->assertStringContainsString("'general' => ['General admission', 'paid']", $form);
    $this->assertStringContainsString("'rsvp' => ['Free RSVP', 'rsvp']", $form);

    $this->assertNotFalse($javascript);
    $this->assertStringContainsString('function applyTicketPreset(button)', $javascript);
    $this->assertStringContainsString("details.querySelector('[name=\"new_ticket[ticket_kind]\"]')", $javascript);
    $this->assertStringContainsString("details.querySelector('[name=\"new_ticket[title]\"]')", $javascript);
    $this->assertStringContainsString("emitAnalytics('ticket_preset_selected'", $javascript);
    $this->assertStringNotContainsString('requestSubmit()', $javascript);
  }

  public function testReusableSetupsCopyConfigurationWithoutEventData(): void {
    $form = file_get_contents($this->moduleRoot() . '/src/Form/EventStudioOperationalTicketsForm.php');
    $javascript = file_get_contents($this->moduleRoot() . '/js/mel-event-studio-tickets-app.js');
    $lifecycle = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_event/src/Service/TicketTierLifecycleService.php');
    $entity = file_get_contents(dirname(__DIR__, 4) . '/mel_ticket/src/Entity/TicketType.php');

    $this->assertNotFalse($form);
    $this->assertStringContainsString('Save as a reusable ticket setup', $form);
    $this->assertStringContainsString('Start from a saved setup', $form);
    $this->assertStringContainsString('loadReusableTicketSetupsForAccount', $form);
    $this->assertStringContainsString('loadReusableTicketSetupForAccount', $form);
    $this->assertStringContainsString('updateAndSaveReusableTicketSetup', $form);
    $this->assertStringContainsString('cloneFromReusableTemplate', $form);
    $this->assertStringContainsString('Sales, capacity, attendees and orders are never copied.', $form);
    $this->assertStringContainsString('Manage saved setups', $form);
    $this->assertStringContainsString('Remove from saved setups', $form);
    $this->assertStringContainsString('Tickets already created from it will not change.', $form);
    $this->assertStringContainsString('submittedReusableSetupRows', $form);
    $this->assertStringContainsString('renameReusableTicketSetup', $form);
    $this->assertStringContainsString('archiveReusableTicketSetup', $form);

    $this->assertNotFalse($javascript);
    $this->assertStringContainsString('function applySavedTicketSetup(select)', $javascript);
    $this->assertStringContainsString("assign('[name=\"new_ticket[capacity]\"]', '', 'input')", $javascript);
    $this->assertStringContainsString("hydrated.value = '1'", $javascript);
    $this->assertStringContainsString('reusable_ticket_setup_selected', $javascript);
    $this->assertStringContainsString('mel-saved-ticket-setup-remove-safe-default', $javascript);

    $this->assertNotFalse($lifecycle);
    $this->assertStringContainsString('function saveReusableTicketSetupFromTicket', $lifecycle);
    $this->assertStringContainsString('function buildTicketValuesFromReusableTemplate', $lifecycle);
    $this->assertStringContainsString("\$values['template_source'] = ['target_id' => (int) \$template->id()]", $lifecycle);
    $this->assertStringContainsString("'commerce_variation' => NULL", $lifecycle);
    $this->assertStringContainsString("'event' => NULL", $lifecycle);
    $this->assertStringContainsString('function renameReusableTicketSetup', $lifecycle);
    $this->assertStringContainsString('function archiveReusableTicketSetup', $lifecycle);
    $archiveStart = strpos($lifecycle, 'public function archiveReusableTicketSetup');
    $archiveEnd = strpos($lifecycle, 'public function saveReusableTicketSetupFromTicket');
    $this->assertNotFalse($archiveStart);
    $this->assertNotFalse($archiveEnd);
    $archiveMethod = substr($lifecycle, $archiveStart, $archiveEnd - $archiveStart);
    $this->assertStringContainsString("TicketTypeInterface::LIFECYCLE_ARCHIVED", $archiveMethod);
    $this->assertStringNotContainsString('->delete()', $archiveMethod);

    $saveStart = strpos($lifecycle, 'private function reusableTicketSetupValues');
    $saveEnd = strpos($lifecycle, 'private function reusableTicketSetupMatches');
    $this->assertNotFalse($saveStart);
    $this->assertNotFalse($saveEnd);
    $saveMethod = substr($lifecycle, $saveStart, $saveEnd - $saveStart);
    $this->assertStringNotContainsString("get('capacity')", $saveMethod);
    $this->assertStringNotContainsString("get('sale_start')", $saveMethod);
    $this->assertStringNotContainsString("get('sale_end')", $saveMethod);
    $this->assertStringNotContainsString("get('field_attendee_questions')", $saveMethod);

    $this->assertNotFalse($entity);
    $this->assertStringContainsString('Reusable ticket setups cannot be attached to an event.', $entity);
    $this->assertStringContainsString("\$this->set('commerce_variation', NULL)", $entity);
    $this->assertStringContainsString("\$this->set('status', FALSE)", $entity);
    $this->assertStringNotContainsString('Paid tickets cannot be marked reusable', $entity);
  }

  public function testOperationalTicketsFormUsesOrganiserLanguage(): void {
    $form = file_get_contents($this->moduleRoot() . '/src/Form/EventStudioOperationalTicketsForm.php');
    $this->assertNotFalse($form);
    $this->assertStringContainsString('Step 2 of 3', $form);
    $this->assertStringContainsString('Create and manage tickets', $form);
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
    $preview = file_get_contents($this->moduleRoot() . '/templates/mel-event-ticket-preview.html.twig');

    $this->assertNotFalse($ticketsForm);
    $this->assertStringContainsString('Step 1 of 3', $ticketsForm);
    $this->assertStringContainsString('Choose how people book', $ticketsForm);
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

    $this->assertNotFalse($preview);
    $this->assertStringContainsString('Step 3 of 3', $preview);
    $this->assertStringContainsString('Check the customer preview', $preview);
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
    $this->assertStringContainsString("'#title' => \$controls_summary", $form);
    $this->assertStringContainsString("'#title' => \$this->t('Ticket actions')", $form);
    $this->assertStringContainsString("'#open' => \$ticket_has_errors", $form);
    $this->assertStringContainsString("str_contains((string) \$error_name, 'tickets][' . \$ticket_id)", $form);

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

  public function testWorkspaceUsesTicketingAsTheSectionLabel(): void {
    $section = file_get_contents($this->moduleRoot() . '/src/Plugin/EventStudioSection/TicketsSection.php');
    $tabs = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_vendor/src/Service/VendorEventTabsService.php');
    $fallback = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_vendor/src/Hook/VendorConsolePagePreprocess.php');

    $this->assertNotFalse($section);
    $this->assertStringContainsString("title: 'Ticketing'", $section);
    $this->assertNotFalse($tabs);
    $this->assertStringContainsString("translate('Ticketing')", $tabs);
    $this->assertNotFalse($fallback);
    $this->assertStringContainsString("\$this->t('Ticketing')", $fallback);
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
    $css = file_get_contents($this->moduleRoot() . '/css/mel-event-studio-shell.css');
    $this->assertNotFalse($form);
    $this->assertNotFalse($css);
    $this->assertStringContainsString('Choose only one ticket action: Duplicate, Archive, or permanently delete.', $form);
    $this->assertStringContainsString('mel-event-studio-ticket-card__action--duplicate', $form);
    $this->assertStringContainsString('mel-event-studio-ticket-card__action--archive', $form);
    $this->assertStringContainsString('.mel-event-studio-ticket-card__action {', $css);
    $this->assertStringContainsString('grid-template-columns: auto minmax(0, 1fr);', $css);
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
