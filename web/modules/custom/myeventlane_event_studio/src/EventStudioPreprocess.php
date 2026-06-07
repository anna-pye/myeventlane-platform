<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio;

use Drupal\Component\Utility\Unicode;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Service\PaidPublishStripeGate;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQuery;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Theme preprocess helpers for Event Studio templates.
 */
final class EventStudioPreprocess {

  use StringTranslationTrait;

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly UserVendorMembershipQuery $userVendorMembershipQuery,
    private readonly OnboardingManager $onboardingManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestStack $requestStack,
    private readonly DomainDetector $domainDetector,
    private readonly PaidPublishStripeGate $paidPublishStripeGate,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Adds contextual action URLs for the mel_event_studio theme hook.
   *
   * @param array<string, mixed> $variables
   *   Theme variables for mel_event_studio; expects element['#mel_studio_node'].
   */
  public function preprocess(array &$variables): void {
    $variables['mel_publish_blocked'] = FALSE;
    $variables['mel_publish_stripe_gate'] = FALSE;
    $variables['mel_stripe_connected'] = FALSE;
    $variables['mel_show_first_event_banner'] = FALSE;
    $variables['show_onboarding_sidebar'] = FALSE;
    $variables['onboarding_stages'] = [];

    $uid = (int) $this->currentUser->id();
    $vendor_ids = $uid > 0 ? $this->userVendorMembershipQuery->getVendorIdsForUser($uid) : [];

    if ($uid > 0 && $vendor_ids === []) {
      $variables['mel_publish_blocked'] = TRUE;
    }

    $stripe_connected = FALSE;
    $state = NULL;
    if ($uid > 0 && $vendor_ids !== []) {
      $state = $this->onboardingManager->loadVendorStateByUid($uid);
      $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load(reset($vendor_ids));
      if ($state !== NULL && $vendor instanceof Vendor) {
        $user = $this->entityTypeManager->getStorage('user')->load($uid);
        if ($user instanceof UserInterface) {
          $state = $this->onboardingManager->loadOrCreateVendor($user, $vendor);
          $this->onboardingManager->refreshFlags($state);
          $stripe_connected = !empty($state->getFlags()['stripe_connected']);
        }
      }
    }

    $variables['mel_stripe_connected'] = $stripe_connected;

    $path = '/create-event';
    $httpRequest = $this->requestStack->getCurrentRequest();
    if ($httpRequest !== NULL && $httpRequest->getPathInfo() !== '') {
      $path = (string) $httpRequest->getPathInfo();
    }
    $variables['mel_stripe_connect_url'] = Url::fromRoute('myeventlane_vendor.stripe_connect', [], [
      'query' => ['destination' => $path],
    ])->toString();

    if ($state !== NULL && !$state->isCompleted() && \function_exists('_myeventlane_vendor_theme_build_onboarding_stages')) {
      $variables['onboarding_stages'] = _myeventlane_vendor_theme_build_onboarding_stages($state);
      $variables['show_onboarding_sidebar'] = TRUE;
    }

    $mode = (string) ($variables['mode'] ?? '');
    $request = $this->requestStack->getCurrentRequest();
    $first_flag = $request && (string) $request->query->get('mel_first_event') === '1';
    if ($mode === 'create' && $state !== NULL && !$state->isCompleted()) {
      if ($first_flag || $state->getStage() === 'ask') {
        $variables['mel_show_first_event_banner'] = TRUE;
      }
    }

    $element = $variables['element'] ?? [];
    $node = $element['#mel_studio_node'] ?? NULL;
    $variables['mel_event_is_published'] = $node instanceof NodeInterface && $node->isPublished();

    $variables['mel_attendee_details_open'] = FALSE;
    if (isset($element['mel']['attendee_questions_editor']['items_state']['#default_value'])) {
      $raw = trim((string) $element['mel']['attendee_questions_editor']['items_state']['#default_value']);
      $variables['mel_attendee_details_open'] = $raw !== '' && $raw !== '[]';
    }

    if ($node instanceof NodeInterface && $node->bundle() === 'event' && $vendor_ids !== []) {
      $event_type = '';
      if ($node->hasField('field_event_type') && !$node->get('field_event_type')->isEmpty()) {
        $event_type = (string) $node->get('field_event_type')->value;
      }
      $is_paid = in_array($event_type, ['paid', 'both'], TRUE);
      if ($is_paid && $this->paidPublishStripeGate->validatePaidPublishAllowed($this->currentUser, (int) $node->id()) !== NULL) {
        $variables['mel_publish_blocked'] = TRUE;
        $variables['mel_publish_stripe_gate'] = TRUE;
      }
    }

    $actions = [
      'view' => NULL,
      'booking' => NULL,
      'scan' => NULL,
      'tickets' => NULL,
    ];

    if ($node instanceof NodeInterface && !$node->isNew()) {
      $nid = (int) $node->id();

      $view = $node->toUrl();
      if ($view->access()) {
        $actions['view'] = $view->toString();
      }

      $event_type = '';
      if ($node->hasField('field_event_type') && !$node->get('field_event_type')->isEmpty()) {
        $event_type = (string) $node->get('field_event_type')->value;
      }
      if ($event_type !== 'external') {
        $booking = Url::fromRoute('myeventlane_commerce.event_book', ['node' => $nid]);
        if ($booking->access()) {
          $actions['booking'] = $booking->toString();
        }
      }

      $scan = Url::fromRoute('myeventlane_tickets.ticket_scan', ['event' => $nid]);
      if ($scan->access()) {
        $actions['scan'] = $scan->toString();
      }

      $tickets = Url::fromRoute('myeventlane_vendor.console.event_tickets', ['event' => $nid]);
      if ($tickets->access()) {
        $actions['tickets'] = $tickets->toString();
      }
    }

    $variables['mel_event_actions'] = $actions;

    $variables['mel_publish_celebrate'] = FALSE;
    $variables['mel_publish_celebrate_share_url_absolute'] = NULL;
    $variables['mel_publish_celebrate_dismiss_url'] = NULL;
    $variables['mel_publish_celebrate_share'] = [];
    $variables['mel_publish_celebrate_calendar_url'] = NULL;
    $variables['mel_publish_celebrate_node_id'] = NULL;
    $variables['mel_publish_celebrate_boost_url'] = NULL;

    $celebrate_requested = FALSE;
    if ($request !== NULL && (string) $request->query->get('mel_celebrate') === '1') {
      $celebrate_requested = TRUE;
    }

    if ($celebrate_requested
      && $node instanceof NodeInterface
      && !$node->isNew()
      && $node->bundle() === 'event'
      && $node->isPublished()) {
      $handoff = $this->buildPublishSuccessHandoff($node);
      if ($handoff !== NULL) {
        $variables['mel_publish_celebrate'] = TRUE;
        $variables['mel_publish_celebrate_share_url_absolute'] = $handoff['view_url'];
        $variables['mel_publish_celebrate_dismiss_url'] = Url::fromRoute('myeventlane_event_studio.edit', ['node' => (int) $node->id()])->toString();
        $variables['mel_publish_celebrate_share'] = $handoff['share'];
        $variables['mel_publish_celebrate_calendar_url'] = $handoff['calendar_url'];
        $variables['mel_publish_celebrate_node_id'] = (int) $node->id();
        $variables['mel_publish_celebrate_boost_url'] = $handoff['boost_url'];
      }
    }

  }

  /**
   * Builds workspace publish-success handoff URLs and copy for a live event.
   *
   * @return array<string, mixed>|null
   *   Handoff payload, or NULL when the event is not a published event node.
   */
  public function buildPublishSuccessHandoff(NodeInterface $node): ?array {
    if ($node->isNew() || $node->bundle() !== 'event' || !$node->isPublished()) {
      return NULL;
    }

    $event_id = (int) $node->id();
    $canonical_path = Url::fromRoute('entity.node.canonical', ['node' => $event_id])->toString();
    $canonical_absolute = $this->domainDetector->publicUrl($canonical_path);
    if (!str_starts_with($canonical_absolute, 'http')) {
      $canonical_absolute = Url::fromRoute(
        'entity.node.canonical',
        ['node' => $event_id],
        ['absolute' => TRUE],
      )->toString();
    }

    $snippet = Unicode::truncate($node->label(), 220, TRUE, TRUE);
    $tweet_q = UrlHelper::buildQuery([
      'url' => $canonical_absolute,
      'text' => $snippet,
    ]);

    $boost_url = $this->celebrateRouteUrl('myeventlane_boost.vendor_event_boost', ['event' => $event_id]);

    $calendar_url = NULL;
    if ($node->hasField('field_event_start') && !$node->get('field_event_start')->isEmpty()) {
      try {
        $calendar_url = Url::fromRoute(
          'myeventlane_event.calendar_ics',
          ['node' => $event_id],
          ['absolute' => TRUE],
        )->toString();
      }
      catch (\Throwable) {
        $calendar_url = NULL;
      }
    }

    return [
      'title' => (string) $this->t('🎉 Your event is now live'),
      'message' => (string) $this->t('Published successfully'),
      'boost_url' => $boost_url,
      'view_url' => $canonical_absolute,
      'share' => [
        'facebook' => 'https://www.facebook.com/sharer/sharer.php?' . UrlHelper::buildQuery(['u' => $canonical_absolute]),
        'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?' . UrlHelper::buildQuery(['url' => $canonical_absolute]),
        'twitter' => 'https://twitter.com/intent/tweet?' . $tweet_q,
      ],
      'calendar_url' => $calendar_url,
    ];
  }

  /**
   * Resolves a vendor growth URL when the route exists and is accessible.
   *
   * @param string $route
   *   Route name.
   * @param array<string, scalar> $parameters
   *   Route parameters.
   */
  private function celebrateRouteUrl(string $route, array $parameters): ?string {
    try {
      $url = Url::fromRoute($route, $parameters);
      if (!$url->access($this->currentUser)) {
        return NULL;
      }
      return $url->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
