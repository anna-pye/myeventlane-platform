<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract tests for VX2 Sprint 4 One Attendee Workspace convergence.
 *
 * @group myeventlane_event_studio
 */
final class EventStudioAttendeesAppContractTest extends TestCase {

  /**
   * Returns the event_studio module root path.
   */
  private function moduleRoot(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * Asserts Attendees section is an active workspace stack.
   */
  public function testAttendeesSectionUsesActiveWorkspaceStack(): void {
    $section = file_get_contents($this->moduleRoot() . '/src/Plugin/EventStudioSection/AttendeesSection.php');
    $this->assertNotFalse($section);
    $this->assertStringContainsString("section_state: 'active'", $section);
    $this->assertStringContainsString("renderTarget: 'attendees_stack'", $section);
  }

  /**
   * Asserts the section renderer builds the attendees stack.
   */
  public function testRendererBuildsAttendeesStack(): void {
    $renderer = file_get_contents($this->moduleRoot() . '/src/Service/EventStudioSectionRenderer.php');
    $this->assertNotFalse($renderer);
    $this->assertStringContainsString('buildAttendeesStack', $renderer);
    $this->assertStringContainsString("'attendees_stack'", $renderer);
    $this->assertStringContainsString('mel-event-studio-attendees-app', $renderer);
    $this->assertStringContainsString('EventAttendeeWorkspaceBuilder', $renderer);
  }

  /**
   * Asserts organiser language and controls in the workspace template.
   */
  public function testWorkspaceTemplateUsesOrganiserLanguage(): void {
    $twig = file_get_contents($this->moduleRoot() . '/templates/mel-event-studio-attendees-workspace.html.twig');
    $this->assertNotFalse($twig);
    $this->assertStringContainsString('Ticket / RSVP', $twig);
    $this->assertStringContainsString('Booking status', $twig);
    $this->assertStringContainsString('No attendees yet', $twig);
    $this->assertStringNotContainsString('<h2 class="mel-attendees-workspace__title">', $twig);
    $this->assertStringContainsString('data-mel-attendee-event-empty', $twig);
    $this->assertStringContainsString('data-mel-attendee-search', $twig);
    $this->assertStringContainsString('data-mel-attendee-filter', $twig);
    $this->assertStringContainsString('data-mel-attendee-reset', $twig);
    $this->assertStringContainsString('Showing @count of @total attendees', $twig);
    $this->assertStringContainsString("name=\"destination\"", $twig);
    $this->assertStringContainsString('workspace_attendees', $twig);
    $this->assertStringContainsString('matches_initial_filter', $twig);
    $this->assertStringContainsString('empty_filter', $twig);
    $this->assertStringNotContainsString('Ticket holders', $twig);
    $this->assertStringNotContainsString('Check-in module', $twig);
  }

  /**
   * Empty-state guidance follows the event's canonical publication state.
   */
  public function testEmptyStateAndActionsRespectGuestListState(): void {
    $builder = dirname(__DIR__, 4) . '/myeventlane_event_attendees/src/Service/EventAttendeeWorkspaceBuilder.php';
    $contents = file_get_contents($builder);
    $this->assertNotFalse($contents);
    $this->assertStringContainsString('buildWorkspaceActions($event, $total > 0)', $contents);
    $this->assertStringContainsString('if ($hasAttendees)', $contents);
    $this->assertStringContainsString('$event->isPublished()', $contents);
    $this->assertStringContainsString('Your event is live. Share it to receive your first booking or RSVP.', $contents);
    $this->assertStringContainsString('Finish publishing your event, then share it to welcome your first guest.', $contents);
    $this->assertStringContainsString('View and share event', $contents);
    $this->assertStringContainsString('Continue to Publishing', $contents);
    $this->assertStringContainsString("'open_new_tab' => \$published", $contents);
  }

  /**
   * Filtered cards must remain hidden despite their flex/grid presentation.
   */
  public function testVendorThemePreservesAttendeeHiddenState(): void {
    $scss = dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/src/scss/components/_mel-event-studio-attendees.scss';
    $contents = file_get_contents($scss);
    $this->assertNotFalse($contents);
    $this->assertStringContainsString('.mel-attendee-card[hidden]', $contents);
    $this->assertStringContainsString('.mel-attendees-workspace__list[hidden]', $contents);
    $this->assertStringContainsString('display: none', $contents);
  }

  /**
   * Asserts the attendees app library and JS hooks exist.
   */
  public function testAttendeesAppLibraryAndJsExist(): void {
    $libraries = file_get_contents($this->moduleRoot() . '/myeventlane_event_studio.libraries.yml');
    $this->assertNotFalse($libraries);
    $this->assertStringContainsString('mel_event_studio_attendees_app', $libraries);
    $this->assertFileExists($this->moduleRoot() . '/js/mel-event-studio-attendees-app.js');
    $js = file_get_contents($this->moduleRoot() . '/js/mel-event-studio-attendees-app.js');
    $this->assertNotFalse($js);
    $this->assertStringContainsString('attendee_filtered', $js);
    $this->assertStringContainsString('attendee_checked_in', $js);
    $this->assertStringContainsString('data-mel-attendee-search', $js);
    $this->assertStringContainsString('hasNarrowing', $js);
    $this->assertStringContainsString("activeFilter !== 'all'", $js);
    $this->assertStringContainsString("filter: 'reset'", $js);
    $this->assertStringContainsString("button.getAttribute('data-mel-attendee-filter') === 'all'", $js);
    $this->assertStringContainsString("ticketSelect.value = ''", $js);
    $this->assertStringContainsString("search.value = ''", $js);
    $this->assertStringContainsString('No checked-in guests', $js);
    $this->assertStringContainsString('Door Mode will update this list', $js);
  }

  /**
   * Asserts Door Mode and filter redirects are registered.
   *
   * Door Mode redirects apply only when the account has vendor console trust;
   * check-in-only team accounts keep legacy surfaces.
   */
  public function testDoorModeRedirectsAreRegistered(): void {
    $subscriber = file_get_contents($this->moduleRoot() . '/src/EventSubscriber/VendorLegacyWizardRedirectSubscriber.php');
    $this->assertNotFalse($subscriber);
    $this->assertStringContainsString('DOOR_MODE_REDIRECT_ROUTES', $subscriber);
    $this->assertStringContainsString('myeventlane_checkin.page', $subscriber);
    $this->assertStringContainsString('myeventlane_tickets.ticket_checkin', $subscriber);
    $this->assertStringContainsString('myeventlane_rsvp.checkin_list', $subscriber);
    $this->assertStringContainsString('VendorConsoleTrust', $subscriber);
    $this->assertStringContainsString('accountIsTrustedForVendorConsole', $subscriber);
    $this->assertStringContainsString("filter'] = 'rsvp'", $subscriber);
    $this->assertStringContainsString("filter'] = 'waitlist'", $subscriber);
  }

  /**
   * Asserts the workspace builder lives in the attendees module.
   */
  public function testWorkspaceBuilderLivesInAttendeesModule(): void {
    $builder = dirname(__DIR__, 4) . '/myeventlane_event_attendees/src/Service/EventAttendeeWorkspaceBuilder.php';
    $this->assertFileExists($builder);
    $contents = file_get_contents($builder);
    $this->assertNotFalse($contents);
    $this->assertStringContainsString('attendee_viewed', $contents);
    $this->assertStringContainsString('DENSE_TABLE_THRESHOLD', $contents);
    $this->assertStringContainsString('Message attendees', $contents);
    $this->assertStringContainsString('Export attendees', $contents);
    $this->assertStringContainsString('Door Mode', $contents);
  }

}
