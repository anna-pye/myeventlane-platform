<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event_studio\DTO\EventReadinessResult;
use Drupal\myeventlane_vendor\Service\PaidPublishStripeGate;
use Drupal\myeventlane_vendor\Service\VendorEventWorkspaceViewModelBuilder;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the Event Workspace Overview (organiser home).
 *
 * Reuses Workspace mission-control signals without a second product shell.
 */
final class EventWorkspaceOverviewBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly VendorEventWorkspaceViewModelBuilder $workspaceViewModel,
    private readonly PaidPublishStripeGate $stripeGate,
    private readonly EventReadinessFacade $readinessFacade,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Builds the Overview render array for one event.
   *
   * @return array<string, mixed>
   *   Themeable render array.
   */
  public function build(NodeInterface $event, AccountInterface $account): array {
    try {
      $workspace = $this->workspaceViewModel->build($event, $account);
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Workspace overview model failed for event @nid: @message', [
        '@nid' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      $workspace = [];
    }

    // Same facade bundle as the workspace readiness strip so idea rows match.
    $bundle = $this->readinessFacade->evaluate($event, $account);
    $readiness = $bundle['publish'];
    $recommended = is_array($bundle['recommended'] ?? NULL) ? $bundle['recommended'] : [];
    $nid = (int) $event->id();
    $published = $event->isPublished();

    $next = is_array($workspace['next_action'] ?? NULL) ? $workspace['next_action'] : [];
    $focus = is_array($workspace['todays_focus'] ?? NULL) ? $workspace['todays_focus'] : [];
    $sales = is_array($workspace['sales_snapshot'] ?? NULL) ? $workspace['sales_snapshot'] : [];
    $eventMeta = is_array($workspace['event'] ?? NULL) ? $workspace['event'] : [];
    $humanChecklist = $this->buildHumanChecklist(
      $readiness->completed,
      $readiness->errors,
      $readiness->warnings,
      $recommended,
    );

    $stripe = $this->buildStripeHealth($account, $event, $eventMeta);
    $remaining = count($readiness->errors);
    $nextRecommended = $this->resolveNextRecommendedAction($next, $readiness, $published, $nid);

    return [
      '#theme' => 'mel_event_studio_overview',
      '#status' => [
        'label' => (string) ($eventMeta['status_label'] ?? ($published ? $this->t('Live') : $this->t('Draft'))),
        'key' => (string) ($eventMeta['status'] ?? ($published ? 'live' : 'draft')),
      ],
      '#readiness' => [
        'ready' => $readiness->ready,
        'score' => (int) ($workspace['readiness']['score'] ?? 0),
        'items' => $humanChecklist,
        'headline' => $this->readinessHeadline($readiness->ready, $published, $remaining),
        'explanation' => $this->readinessExplanation($readiness),
      ],
      '#todays_tasks' => $focus,
      '#sales' => $sales,
      '#ticket_summary' => $this->metricFromSales($sales, 'tickets'),
      '#attendee_summary' => $this->metricFromSales($sales, 'attendees'),
      '#stripe' => $stripe,
      '#marketing' => $this->buildMarketingStatus($event, $account),
      '#analytics' => $this->buildAnalyticsSnapshot($workspace),
      '#next_action' => $nextRecommended,
      '#quick_links' => $this->buildQuickLinks($nid, $account),
      '#celebration' => $this->buildCelebrationHint($event, $readiness, $published),
    ];
  }

  /**
   * Builds a human checklist from readiness strings.
   *
   * Blocking errors use tone "attention". Warnings are non-blocking
   * (EventReadinessResult::ready can still be TRUE) and use tone "warning"
   * so they are not presented as publish blockers.
   *
   * @param list<string> $completed
   *   Completed readiness labels.
   * @param list<string> $errors
   *   Blocking readiness labels.
   * @param list<string> $warnings
   *   Warning readiness labels.
   * @param list<string> $recommendations
   *   Optional recommendation labels.
   *
   * @return list<array{label: string, complete: bool, tone: string}>
   *   Checklist rows for Overview.
   */
  private function buildHumanChecklist(array $completed, array $errors, array $warnings, array $recommendations): array {
    $items = [];
    foreach ($completed as $label) {
      $items[] = [
        'label' => $this->humaniseChecklistLabel((string) $label),
        'complete' => TRUE,
        'tone' => 'success',
      ];
    }
    foreach ($errors as $label) {
      $items[] = [
        'label' => $this->humaniseChecklistLabel((string) $label),
        'complete' => FALSE,
        'tone' => 'attention',
      ];
    }
    foreach ($warnings as $label) {
      $items[] = [
        'label' => $this->humaniseChecklistLabel((string) $label),
        'complete' => FALSE,
        'tone' => 'warning',
      ];
    }
    foreach ($recommendations as $label) {
      $items[] = [
        'label' => $this->humaniseChecklistLabel((string) $label),
        'complete' => FALSE,
        'tone' => 'idea',
      ];
    }
    return $items;
  }

  /**
   * Maps technical readiness copy to short organiser labels.
   */
  private function humaniseChecklistLabel(string $label): string {
    $trimmed = rtrim($label, '.');
    $map = [
      'Event title added' => (string) $this->t('Event title'),
      'Event dates complete' => (string) $this->t('Schedule'),
      'Booking mode selected' => (string) $this->t('Booking mode'),
      'Ticketing configured' => (string) $this->t('Tickets ready'),
      'Payment onboarding complete' => (string) $this->t('Payments connected'),
      'Vendor publish requirements complete' => (string) $this->t('Organiser profile ready'),
      'Branding image added' => (string) $this->t('Cover image'),
      'Capacity settings valid' => (string) $this->t('Capacity'),
      'External booking URL added' => (string) $this->t('External booking link'),
    ];
    return $map[$trimmed] ?? $trimmed;
  }

  /**
   * Builds the readiness headline for Overview.
   */
  private function readinessHeadline(bool $ready, bool $published, int $remaining): string {
    if ($published && $ready) {
      return (string) $this->t('Your event is live');
    }
    if ($ready) {
      return (string) $this->t('Ready to publish');
    }
    if ($remaining === 1) {
      return (string) $this->t("You're almost there…");
    }
    return (string) $this->t('A few things left before publishing');
  }

  /**
   * Explains why publishing is ready or blocked.
   */
  private function readinessExplanation(EventReadinessResult $readiness): string {
    if ($readiness->ready) {
      if ($readiness->warnings !== []) {
        return (string) $this->t('You can publish now. The items marked for review are optional improvements.');
      }
      return (string) $this->t('Everything needed to go live looks good.');
    }
    if (count($readiness->errors) === 1) {
      return (string) $this->t('One more thing before publishing: @reason', [
        '@reason' => $readiness->errors[0],
      ]);
    }
    if ($readiness->errors !== []) {
      return (string) $this->t('Finish the items below so guests can find and book your event.');
    }
    return (string) $this->t('Review the suggestions below to make your event page stronger.');
  }

  /**
   * Builds Stripe / payments health for Overview.
   *
   * Paid-ticket Stripe checks only run for paid / hybrid events.
   *
   * @param array<string, mixed> $eventMeta
   *   Workspace event metadata (may include event_type).
   *
   * @return array{label: string, tone: string, detail: string, url: ?string}
   *   Payments status card payload.
   */
  private function buildStripeHealth(AccountInterface $account, NodeInterface $event, array $eventMeta): array {
    $eventId = (int) $event->id();
    $eventType = $this->resolveEventBookingType($event, $eventMeta);
    if (!in_array($eventType, ['paid', 'both'], TRUE)) {
      return [
        'label' => (string) $this->t('Payments'),
        'tone' => 'muted',
        'detail' => (string) $this->t('Payments apply when you sell paid tickets for this event.'),
        'url' => $this->safeUrl('myeventlane_vendor.payouts'),
      ];
    }

    try {
      $denial = $this->stripeGate->validatePaidPublishAllowed($account, $eventId);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Stripe health check failed for overview event @nid: @message', [
        '@nid' => (string) $eventId,
        '@message' => $e->getMessage(),
      ]);
      return [
        'label' => (string) $this->t('Payments'),
        'tone' => 'attention',
        'detail' => (string) $this->t('We could not confirm Stripe just now.'),
        'url' => $this->safeUrl('myeventlane_vendor.payouts'),
      ];
    }

    if ($denial === NULL) {
      return [
        'label' => (string) $this->t('Payments'),
        'tone' => 'success',
        'detail' => (string) $this->t("Stripe connected — you're ready to get paid."),
        'url' => $this->safeUrl('myeventlane_vendor.payouts'),
      ];
    }

    return [
      'label' => (string) $this->t('Payments'),
      'tone' => 'attention',
      'detail' => $denial,
      'url' => $this->safeUrl('myeventlane_vendor.payouts'),
    ];
  }

  /**
   * Resolves booking mode for Overview payments gating.
   *
   * @param array<string, mixed> $eventMeta
   *   Workspace event metadata.
   */
  private function resolveEventBookingType(NodeInterface $event, array $eventMeta): string {
    $fromMeta = trim((string) ($eventMeta['event_type'] ?? ''));
    if (in_array($fromMeta, ['paid', 'rsvp', 'both', 'external', 'unknown'], TRUE)) {
      return $fromMeta === 'unknown' ? '' : $fromMeta;
    }
    if ($event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()) {
      return (string) $event->get('field_event_type')->value;
    }
    return '';
  }

  /**
   * Builds marketing status for Overview.
   *
   * @return array{label: string, detail: string, url: ?string}
   *   Marketing card payload.
   */
  private function buildMarketingStatus(NodeInterface $event, AccountInterface $account): array {
    $nid = (int) $event->id();
    $boostUrl = $this->safeUrl('myeventlane_boost.vendor_event_boost', ['event' => $nid]);
    if (!$event->isPublished()) {
      return [
        'label' => (string) $this->t('Marketing'),
        'detail' => (string) $this->t('Publish your event to share and Boost it.'),
        'url' => $this->safeUrl('myeventlane_event_studio.workspace_publishing', ['node' => $nid]),
      ];
    }
    return [
      'label' => (string) $this->t('Marketing'),
      'detail' => (string) $this->t('Share your page or Boost to reach more people.'),
      'url' => $boostUrl ?? $this->safeUrl('myeventlane_event_studio.workspace_marketing', ['node' => $nid]),
    ];
  }

  /**
   * Builds a short analytics snapshot from workspace metrics.
   *
   * @param array<string, mixed> $workspace
   *   Workspace view model.
   *
   * @return array{label: string, detail: string, url: ?string}
   *   Analytics card payload.
   */
  private function buildAnalyticsSnapshot(array $workspace): array {
    $metrics = is_array($workspace['metrics'] ?? NULL) ? $workspace['metrics'] : [];
    $parts = [];
    foreach ($metrics as $metric) {
      if (!is_array($metric)) {
        continue;
      }
      $label = trim((string) ($metric['label'] ?? ''));
      $value = trim((string) ($metric['value'] ?? ''));
      if ($label !== '' && $value !== '') {
        $parts[] = $label . ': ' . $value;
      }
      if (count($parts) >= 2) {
        break;
      }
    }
    $nid = (int) ($workspace['event']['nid'] ?? 0);
    return [
      'label' => (string) $this->t('Analytics'),
      'detail' => $parts !== []
        ? implode(' · ', $parts)
        : (string) $this->t('Publish your event to start tracking sales.'),
      'url' => $nid > 0 ? $this->safeUrl('myeventlane_event_studio.workspace_analytics', ['node' => $nid]) : NULL,
    ];
  }

  /**
   * Resolves the primary next action for Overview.
   *
   * Prefers Workspace readiness/publishing CTAs over generic mission-control
   * placeholders such as "Finish and publish your event" or "Event looks ready".
   * Concrete operational actions (unknown event type, manage bookings) still win.
   *
   * @param array<string, mixed> $next
   *   Workspace next-action payload.
   * @param \Drupal\myeventlane_event_studio\DTO\EventReadinessResult $readiness
   *   Publish readiness result.
   * @param bool $published
   *   Whether the event is published.
   * @param int $nid
   *   Event node id.
   *
   * @return array{title: string, message: string, action_label: ?string, url: ?string}
   *   Next-action card payload.
   */
  private function resolveNextRecommendedAction(array $next, EventReadinessResult $readiness, bool $published, int $nid): array {
    $title = trim((string) ($next['title'] ?? ''));
    $severity = (string) ($next['severity'] ?? '');

    // Keep hard blockers and live booking activity from mission-control.
    if ($title !== '' && ($severity === 'error' || ($published && $severity === 'info'))) {
      return [
        'title' => $title,
        'message' => (string) ($next['message'] ?? ''),
        'action_label' => isset($next['action_label']) ? (string) $next['action_label'] : NULL,
        'url' => isset($next['url']) && $next['url'] instanceof Url
          ? $next['url']->toString()
          : (isset($next['url']) ? (string) $next['url'] : NULL),
      ];
    }

    if (!$readiness->ready) {
      return [
        'title' => (string) $this->t('Continue setup'),
        'message' => $readiness->errors[0] ?? (string) $this->t('Finish the readiness checklist so you can publish.'),
        'action_label' => (string) $this->t('Review publishing'),
        'url' => $this->safeUrl('myeventlane_event_studio.workspace_publishing', ['node' => $nid]),
      ];
    }
    if (!$published) {
      return [
        'title' => (string) $this->t('Ready when you are'),
        'message' => (string) $this->t('Your event looks ready. Publish when you want guests to find it.'),
        'action_label' => (string) $this->t('Go to publishing'),
        'url' => $this->safeUrl('myeventlane_event_studio.workspace_publishing', ['node' => $nid]),
      ];
    }
    return [
      'title' => (string) $this->t('Share your event'),
      'message' => (string) $this->t('Your event is live. Share the page or message your attendees.'),
      'action_label' => (string) $this->t('Open marketing'),
      'url' => $this->safeUrl('myeventlane_event_studio.workspace_marketing', ['node' => $nid]),
    ];
  }

  /**
   * Builds Overview quick links.
   *
   * @return list<array{label: string, url: string}>
   *   Quick-link rows.
   */
  private function buildQuickLinks(int $nid, AccountInterface $account): array {
    $links = [];
    $map = [
      [(string) $this->t('Tickets'), 'myeventlane_event_studio.workspace_tickets', ['node' => $nid]],
      [(string) $this->t('Attendees'), 'myeventlane_event_studio.workspace_attendees', ['node' => $nid]],
      [(string) $this->t('Messages'), 'myeventlane_event_studio.workspace_messaging', ['node' => $nid]],
      [(string) $this->t('Orders'), 'myeventlane_event_studio.workspace_orders', ['node' => $nid]],
      [(string) $this->t('Analytics'), 'myeventlane_event_studio.workspace_analytics', ['node' => $nid]],
    ];
    foreach ($map as [$label, $route, $params]) {
      $url = $this->safeUrl($route, $params);
      if ($url !== NULL) {
        $links[] = ['label' => $label, 'url' => $url];
      }
    }
    return $links;
  }

  /**
   * Optional subtle celebration for early draft progress.
   *
   * @return array{show: bool, title: string, message: string}
   *   Celebration payload. show is FALSE when nothing should render.
   */
  private function buildCelebrationHint(NodeInterface $event, EventReadinessResult $readiness, bool $published): array {
    $title = trim($event->label());
    if ($title !== '' && strcasecmp($title, 'Untitled event') !== 0 && !$published && $readiness->completed !== []) {
      if (count($readiness->completed) === 1) {
        return [
          'show' => TRUE,
          'title' => (string) $this->t('Nice start'),
          'message' => (string) $this->t("Your first draft is underway. Keep going — you're building something people will love."),
        ];
      }
    }
    return [
      'show' => FALSE,
      'title' => '',
      'message' => '',
    ];
  }

  /**
   * Extracts a preferred metric string from the sales snapshot.
   *
   * @param array<string, mixed> $sales
   *   Sales snapshot payload.
   * @param string $prefer
   *   Preference key: tickets or attendees.
   *
   * @return string
   *   Metric value or empty string.
   */
  private function metricFromSales(array $sales, string $prefer): string {
    $metrics = is_array($sales['metrics'] ?? NULL) ? $sales['metrics'] : [];
    foreach ($metrics as $metric) {
      if (!is_array($metric)) {
        continue;
      }
      $label = strtolower((string) ($metric['label'] ?? ''));
      if ($prefer === 'tickets' && str_contains($label, 'ticket')) {
        return trim((string) ($metric['value'] ?? ''));
      }
      if ($prefer === 'attendees' && (str_contains($label, 'rsvp') || str_contains($label, 'attendee') || str_contains($label, 'check'))) {
        return trim((string) ($metric['value'] ?? ''));
      }
    }
    return '';
  }

  /**
   * Builds a route URL string when the route exists.
   *
   * @param string $route
   *   Route name.
   * @param array<string, mixed> $params
   *   Route parameters.
   */
  private function safeUrl(string $route, array $params = []): ?string {
    try {
      return Url::fromRoute($route, $params)->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
