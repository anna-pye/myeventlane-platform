<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core;

use Drupal\Core\Url;

/**
 * Builds governed presentation render arrays (mel_empty_state) for operational surfaces.
 *
 * Canonical copy slots are owned by MelReadinessHelper (MELStateSystem vocabulary).
 * This service composes MELComponentSystem theme hooks only — no parallel empty markup.
 */
final class GovernedOperationalTemplates {

  public function __construct(
    private readonly MelReadinessHelper $readiness,
  ) {}

  /**
   * @param array{heading: string, heading_element?: string, what_happened?: string, why_empty?: string, next_action?: string, cta?: mixed} $parts
   *
   * @return array<string, mixed>
   */
  public function buildMelEmptyState(array $parts): array {
    return [
      '#theme' => 'mel_empty_state',
      '#heading' => $parts['heading'],
      '#heading_element' => $parts['heading_element'] ?? 'h2',
      '#what_happened' => $parts['what_happened'] ?? '',
      '#why_empty' => $parts['why_empty'] ?? '',
      '#next_action' => $parts['next_action'] ?? '',
      '#cta' => $parts['cta'] ?? NULL,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function myTicketsOverviewEmpty(): array {
    $s = $this->readiness->customerMyTicketsOverviewEmptySlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => 'h2',
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => $this->browseEventsPrimaryLink(),
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function categoryFollowEmpty(): array {
    $s = $this->readiness->customerCategoryFollowEmptySlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => 'h2',
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => $this->browseEventsPrimaryLink('view.upcoming_events.page_events'),
    ]);
  }

  /**
   * Quiet-week empty under an existing followed category heading (section owns h2).
   *
   * @return array<string, mixed>
   */
  public function categoryFollowWeeklyQuietEmpty(): array {
    $s = $this->readiness->customerCategoryFollowWeeklyQuietSlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => 'h3',
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => $this->browseEventsPrimaryLink('view.upcoming_events.page_events'),
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function customerMyEventsDashboardUpcomingEmpty(): array {
    $s = $this->readiness->customerMyEventsDashboardUpcomingEmptySlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => 'h2',
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => $this->browseEventsPrimaryLink('view.upcoming_events.page_events'),
    ]);
  }

  /**
   * Vendor analytics events list fallback when legacy empty_state payload is absent.
   *
   * @return array<string, mixed>
   */
  public function vendorAnalyticsNoEventRowsEmpty(): array {
    $copy = $this->readiness->vendorActionNoEventsStrings();
    $cta = $this->optionalStudioCreateLink((string) $copy['action_label']);
    return $this->buildMelEmptyState([
      'heading' => $copy['title'],
      'heading_element' => 'h3',
      'what_happened' => $copy['message'],
      'why_empty' => '',
      'next_action' => '',
      'cta' => $cta,
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function vendorAttendeesDashboardEmpty(): array {
    $copy = $this->readiness->vendorActionNoEventsStrings();
    $cta = $this->optionalStudioCreateLink((string) $copy['action_label']);
    return $this->buildMelEmptyState([
      'heading' => $copy['title'],
      'heading_element' => 'h2',
      'what_happened' => $copy['message'],
      'why_empty' => '',
      'next_action' => '',
      'cta' => $cta,
    ]);
  }

  /**
   * Maps legacy analytics empty_state rows onto mel_empty_state.
   *
   * @param array<string, mixed> $legacy
   *   Keys: title, message, action_label, url (url string or NULL).
   *
   * @return array<string, mixed>
   */
  public function analyticsEventsEmptyFromLegacy(array $legacy): array {
    $title = (string) ($legacy['title'] ?? '');
    $message = (string) ($legacy['message'] ?? '');
    $actionLabel = $legacy['action_label'] ?? NULL;
    $url = $legacy['url'] ?? NULL;
    $cta = NULL;
    if (is_string($actionLabel) && $actionLabel !== '') {
      if ($url instanceof Url) {
        $cta = [
          '#type' => 'link',
          '#title' => $actionLabel,
          '#url' => $url,
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
        ];
      }
      elseif (is_string($url) && $url !== '') {
        $cta = [
          '#type' => 'link',
          '#title' => $actionLabel,
          '#url' => Url::fromUri('internal:' . $url),
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
        ];
      }
    }
    return $this->buildMelEmptyState([
      'heading' => $title,
      'heading_element' => 'h3',
      'what_happened' => $message,
      'why_empty' => '',
      'next_action' => '',
      'cta' => $cta,
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function accountDashboardTicketsEmpty(): array {
    $s = $this->readiness->customerAccountDashboardTicketsEmptySlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => 'h3',
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => $this->browseEventsPrimaryLink('view.upcoming_events.page_events', (string) $s['cta_label']),
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function accountDashboardRsvpsEmpty(): array {
    $s = $this->readiness->customerAccountDashboardRsvpsEmptySlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => 'h3',
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => $this->browseEventsPrimaryLink('view.upcoming_events.page_events', (string) $s['cta_label']),
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function accountDashboardPastPreviewEmpty(): array {
    $s = $this->readiness->customerAccountDashboardPastPreviewEmptySlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => 'h3',
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => NULL,
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function accountPastEventsPageEmpty(): array {
    $s = $this->readiness->customerAccountPastEventsPageEmptySlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => 'h2',
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => $this->browseEventsPrimaryLink('view.upcoming_events.page_events', (string) $s['cta_label']),
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function accountDashboardSavedEventsEmpty(): array {
    $s = $this->readiness->customerAccountDashboardSavedEventsEmptySlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => 'h3',
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => $this->browseEventsPrimaryLink('view.upcoming_events.page_events', (string) $s['cta_label']),
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function customerCategoriesFollowEmpty(): array {
    $s = $this->readiness->customerCategoriesFollowEmptySlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => 'h2',
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => $this->browseEventsPrimaryLink('view.upcoming_events.page_events', (string) $s['cta_label']),
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function customerCategoriesNoNewEventsThisWeek(): array {
    $s = $this->readiness->customerCategoriesNoNewEventsThisWeekSlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => 'h3',
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => NULL,
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function customerFollowedOrganisersEmpty(): array {
    $s = $this->readiness->customerFollowedOrganisersEmptySlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => 'h2',
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => $this->browseEventsPrimaryLink('view.upcoming_events.page_events', (string) $s['cta_label']),
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  private function browseEventsPrimaryLink(string $route = 'view.upcoming_events.page_events', ?string $title = NULL): array {
    $label = $title ?? (string) $this->readiness->customerPrimaryBrowseEventsCta();
    try {
      $url = Url::fromRoute($route);
    }
    catch (\Throwable) {
      $url = Url::fromRoute('<front>');
    }
    return [
      '#type' => 'link',
      '#title' => $label,
      '#url' => $url,
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
    ];
  }

  /**
   * @return array<string, mixed>|null
   */
  private function optionalStudioCreateLink(string $title): ?array {
    try {
      $url = Url::fromRoute('myeventlane_vendor.create_event_gateway');
    }
    catch (\Throwable) {
      return NULL;
    }
    return [
      '#type' => 'link',
      '#title' => $title,
      '#url' => $url,
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
    ];
  }

  /**
   * Governed empty cart (mel_empty_state) for cart surfaces.
   *
   * @return array<string, mixed>
   */
  public function checkoutCartEmpty(string $heading_element = 'h2'): array {
    $s = $this->readiness->customerCheckoutEmptyCartSlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => $heading_element,
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => $this->browseEventsPrimaryLink(),
    ]);
  }

  /**
   * Checkout sidebar when Commerce returns no summarisable line items.
   *
   * @return array<string, mixed>
   */
  public function checkoutOrderSummaryNoLinesEmpty(): array {
    $s = $this->readiness->customerCheckoutMissingOrderStateSlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => 'h2',
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => NULL,
    ]);
  }

  /**
   * Cart / checkout-adjacent: timed hold lapsed (mel_empty_state).
   *
   * @return array<string, mixed>
   */
  public function checkoutCartExpired(string $heading_element = 'h2'): array {
    $s = $this->readiness->customerCheckoutExpiredSlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => $heading_element,
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => $this->browseEventsPrimaryLink(),
    ]);
  }

  /**
   * Cart: ticket tier no longer purchasable at cart validation time.
   *
   * @return array<string, mixed>
   */
  public function checkoutCartUnavailableTicket(string $heading_element = 'h2'): array {
    $s = $this->readiness->customerCheckoutTicketSoldOutSlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => $heading_element,
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => $this->browseEventsPrimaryLink(),
    ]);
  }

  /**
   * Cart: no purchasable lines remain after a sell-through (mel_empty_state).
   *
   * @return array<string, mixed>
   */
  public function checkoutCartSoldOut(string $heading_element = 'h2'): array {
    $s = $this->readiness->customerCheckoutCartSoldOutSlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => $heading_element,
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => $this->browseEventsPrimaryLink(),
    ]);
  }

  /**
   * Cart: Commerce removed a line that failed availability re-check.
   *
   * @return array<string, mixed>
   */
  public function checkoutCartRemovedUnavailableLineItem(string $heading_element = 'h2'): array {
    $s = $this->readiness->customerCheckoutCartRemovedUnavailableSlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => $heading_element,
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => $this->browseEventsPrimaryLink(),
    ]);
  }

  /**
   * Payments route unavailable for the cart order (mel_empty_state).
   *
   * @return array<string, mixed>
   */
  public function checkoutCartPaymentUnavailable(string $heading_element = 'h2'): array {
    $s = $this->readiness->customerCheckoutPaymentUnavailableSlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => $heading_element,
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => $this->browseEventsPrimaryLink(),
    ]);
  }

  // -------------------------------------------------------------------------
  // MEL Attendee Operations Layer empty states.
  // Slots owned by MelReadinessHelper. NEVER inline this copy in Twig.
  // -------------------------------------------------------------------------

  /**
   * @return array<string, mixed>
   */
  public function vendorAttendeeOperationsNoAttendeesYet(string $heading_element = 'h2'): array {
    $s = $this->readiness->vendorAttendeeOperationsNoAttendeesYetSlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => $heading_element,
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => NULL,
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function vendorAttendeeOperationsNoTicketSalesYet(string $heading_element = 'h3'): array {
    $s = $this->readiness->vendorAttendeeOperationsNoTicketSalesYetSlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => $heading_element,
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => NULL,
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function vendorAttendeeOperationsNoRsvpsYet(string $heading_element = 'h3'): array {
    $s = $this->readiness->vendorAttendeeOperationsNoRsvpsYetSlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => $heading_element,
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => NULL,
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function vendorAttendeeOperationsExportUnavailable(string $heading_element = 'h3'): array {
    $s = $this->readiness->vendorAttendeeOperationsExportUnavailableSlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => $heading_element,
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => NULL,
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function vendorAttendeeOperationsSearchEmpty(string $heading_element = 'h3'): array {
    $s = $this->readiness->vendorAttendeeOperationsSearchEmptySlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => $heading_element,
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => NULL,
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function vendorAttendeeOperationsFilterEmpty(string $heading_element = 'h3'): array {
    $s = $this->readiness->vendorAttendeeOperationsFilterEmptySlots();
    return $this->buildMelEmptyState([
      'heading' => $s['heading'],
      'heading_element' => $heading_element,
      'what_happened' => $s['what_happened'],
      'why_empty' => $s['why_empty'],
      'next_action' => $s['next_action'],
      'cta' => NULL,
    ]);
  }

}
