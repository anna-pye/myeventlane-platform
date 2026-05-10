<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_account\Service\AccountLinksService;
use Drupal\myeventlane_account\Service\CustomerAccountHeroBuilder;
use Drupal\myeventlane_account\Service\CustomerHubDataBuilder;
use Drupal\myeventlane_core\Service\DisplayNameResolver;
use Drupal\myeventlane_core\GovernedOperationalTemplates;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for the customer My Account experience.
 */
final class MyAccountController extends ControllerBase {

  /**
   * Constructs MyAccountController.
   */
  public function __construct(
    private readonly AccountLinksService $accountLinksService,
    private readonly DisplayNameResolver $displayNameResolver,
    private readonly TimeInterface $time,
    private readonly GovernedOperationalTemplates $operationalTemplates,
    private readonly CustomerHubDataBuilder $customerHubDataBuilder,
    private readonly CustomerAccountHeroBuilder $customerAccountHeroBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_account.account_links'),
      $container->get('myeventlane_core.display_name_resolver'),
      $container->get('datetime.time'),
      $container->get('myeventlane_surface.governed_operational_templates'),
      $container->get('myeventlane_account.customer_hub_data_builder'),
      $container->get('myeventlane_account.customer_account_hero_builder'),
    );
  }

  /**
   * Renders the customer My Account dashboard.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   A render array or redirect for anonymous users.
   */
  public function dashboard(): array|RedirectResponse {
    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      return new RedirectResponse(
        Url::fromRoute('user.login', [], ['query' => ['destination' => '/my-account']])->toString(),
        302
      );
    }

    $displayName = $this->displayNameResolver->getDisplayName($account);
    $userId = (int) $account->id();
    $now = (int) $this->time->getRequestTime();

    $participation = $this->customerHubDataBuilder->buildParticipationLists($userId, (string) $account->getEmail(), $now, TRUE);
    $upcomingTickets = $participation['upcoming_tickets'];
    $upcomingRsvps = $participation['upcoming_rsvps'];
    $pastEvents = $participation['past_events'];
    $savedEventsPreview = $this->customerHubDataBuilder->buildSavedEventsPreview($userId, 3);

    $accountLinks = $this->accountLinksService->buildNavigationItems();

    $cache = (new CacheableMetadata())
      ->addCacheContexts(['user', 'route'])
      ->addCacheTags(['user:' . $userId, 'node_list', 'flag.flag.event_save'])
      ->setCacheMaxAge(300);
    foreach (array_merge($upcomingTickets, $upcomingRsvps, $pastEvents, $savedEventsPreview) as $event) {
      $cache->addCacheTags(['node:' . $event['id']]);
    }

    $reviewEligible = $this->getReviewEligibleEvents(array_slice($pastEvents, 0, 3), $userId);

    $heroData = $this->customerAccountHeroBuilder->buildDashboardHero(
      $userId,
      count($upcomingTickets),
      count($upcomingRsvps),
    );

    $build = [
      '#theme' => 'myeventlane_my_account_dashboard',
      '#display_name' => $displayName,
      '#upcoming_tickets' => array_slice($upcomingTickets, 0, 3),
      '#upcoming_rsvps' => array_slice($upcomingRsvps, 0, 3),
      '#saved_events' => $savedEventsPreview,
      '#past_events' => array_slice($pastEvents, 0, 3),
      '#account_links' => $accountLinks,
      '#show_review_cta' => $this->config('myeventlane_account.reviews')->get('enabled') ?? FALSE,
      '#review_eligible' => $reviewEligible,
      '#attached' => [
        'library' => ['myeventlane_theme/global-styling'],
      ],
      '#account_hero' => [
        '#theme' => 'mel_account_hero',
        '#headline' => $heroData['headline'],
        '#body' => $heroData['body'],
        '#primary_label' => $heroData['primary_label'],
        '#primary_url' => $heroData['primary_url'],
        '#secondary_label' => $heroData['secondary_label'],
        '#secondary_url' => $heroData['secondary_url'],
      ],
    ];
    if ($upcomingTickets === []) {
      $build['#mel_account_dashboard_tickets_empty'] = $this->operationalTemplates->accountDashboardTicketsEmpty();
    }
    if ($upcomingRsvps === []) {
      $build['#mel_account_dashboard_rsvps_empty'] = $this->operationalTemplates->accountDashboardRsvpsEmpty();
    }
    if ($pastEvents === []) {
      $build['#mel_account_dashboard_past_empty'] = $this->operationalTemplates->accountDashboardPastPreviewEmpty();
    }
    if ($savedEventsPreview === []) {
      $build['#mel_account_dashboard_saved_empty'] = $this->operationalTemplates->accountDashboardSavedEventsEmpty();
    }
    $cache->applyTo($build);
    return $build;
  }

  /**
   * Redirects legacy /my-settings to the canonical route including the user parameter.
   */
  public function settingsRedirect(): RedirectResponse {
    $account = $this->currentUser();
    $url = Url::fromRoute('myeventlane_account.settings', ['user' => $account->id()]);
    return new RedirectResponse($url->toString(), 302);
  }

  /**
   * Renders profile & settings inside the customer shell (no standalone Drupal edit route).
   */
  public function settings(UserInterface $user): array|RedirectResponse {
    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      return new RedirectResponse(
        Url::fromRoute('user.login', [], ['query' => ['destination' => '/my-settings']])->toString(),
        302,
      );
    }

    $form = $this->entityFormBuilder()->getForm($user, 'default');

    $cache = (new CacheableMetadata())
      ->addCacheContexts(['user', 'route'])
      ->addCacheableDependency($user);

    $build = [
      '#theme' => 'mel_surface_customer_profile_settings',
      '#intro' => $this->t('Personal details, sign-in security, and how we stay in touch.'),
      '#form' => $form,
      '#attached' => [
        'library' => ['myeventlane_theme/global-styling'],
      ],
    ];
    $cache->applyTo($build);
    return $build;
  }

  /**
   * Renders the Past Events page.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   A render array or redirect for anonymous users.
   */
  public function pastEvents(): array|RedirectResponse {
    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      return new RedirectResponse(
        Url::fromRoute('user.login', [], ['query' => ['destination' => '/my-past-events']])->toString(),
        302
      );
    }

    $userId = (int) $account->id();
    $now = (int) $this->time->getRequestTime();

    $participation = $this->customerHubDataBuilder->buildParticipationLists($userId, (string) $account->getEmail(), $now, TRUE);
    $pastEvents = $participation['past_events'];

    $accountLinks = $this->accountLinksService->buildNavigationItems();

    $cache = (new CacheableMetadata())
      ->addCacheContexts(['user', 'route'])
      ->addCacheTags(['user:' . $userId, 'node_list'])
      ->setCacheMaxAge(300);
    foreach ($pastEvents as $event) {
      $cache->addCacheTags(['node:' . $event['id']]);
    }

    $reviewEligible = $this->getReviewEligibleEvents($pastEvents, $userId);

    $build = [
      '#theme' => 'myeventlane_my_account_past_events',
      '#past_events' => $pastEvents,
      '#account_links' => $accountLinks,
      '#show_review_cta' => $this->config('myeventlane_account.reviews')->get('enabled') ?? FALSE,
      '#review_eligible' => $reviewEligible,
      '#attached' => [
        'library' => ['myeventlane_theme/global-styling'],
      ],
    ];
    if ($pastEvents === []) {
      $build['#mel_account_past_events_page_empty'] = $this->operationalTemplates->accountPastEventsPageEmpty();
    }
    $cache->applyTo($build);
    return $build;
  }

  /**
   * Gets event IDs for which the user is eligible to leave a review.
   *
   * Eligibility: feature enabled, event has reviews enabled, event ended within
   * window_days, user attended, user has not already reviewed.
   *
   * @param array $pastEvents
   *   Array of past event data (id, end_timestamp, etc.).
   * @param int $userId
   *   The user ID.
   *
   * @return array
   *   Map of event_id => TRUE for eligible events.
   */
  private function getReviewEligibleEvents(array $pastEvents, int $userId): array {
    $config = $this->config('myeventlane_account.reviews');
    if (!$config->get('enabled')) {
      return [];
    }

    $windowDays = (int) ($config->get('window_days') ?? 14);
    $windowSeconds = $windowDays * 86400;
    $now = (int) $this->time->getRequestTime();

    $eligible = [];
    $nodeStorage = $this->entityTypeManager()->getStorage('node');

    if (!$this->entityTypeManager()->hasDefinition('event_review')) {
      return [];
    }

    $reviewStorage = $this->entityTypeManager()->getStorage('event_review');
    $existingIds = $reviewStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $userId)
      ->execute();
    $existingByEvent = [];
    if (!empty($existingIds)) {
      foreach ($reviewStorage->loadMultiple($existingIds) as $review) {
        $eid = $review->getEventId();
        if ($eid) {
          $existingByEvent[$eid] = TRUE;
        }
      }
    }

    foreach ($pastEvents as $eventData) {
      $eventId = $eventData['id'] ?? 0;
      if (!$eventId || isset($existingByEvent[$eventId])) {
        continue;
      }

      $endTs = $eventData['end_timestamp'] ?? $eventData['start_timestamp'] ?? 0;
      if ($endTs <= 0 || $endTs >= $now) {
        continue;
      }
      if (($now - $endTs) > $windowSeconds) {
        continue;
      }

      $event = $nodeStorage->load($eventId);
      if (!$event || $event->bundle() !== 'event') {
        continue;
      }
      if (!$event->hasField('field_reviews_enabled') || $event->get('field_reviews_enabled')->isEmpty()) {
        continue;
      }
      if (!(bool) $event->get('field_reviews_enabled')->value) {
        continue;
      }

      $eligible[$eventId] = TRUE;
    }

    return $eligible;
  }

}
