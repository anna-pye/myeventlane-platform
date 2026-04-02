<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\EntityIdNormalizer;
use Drupal\myeventlane_core\Service\OptionalServiceResolver;
use Drupal\myeventlane_event_studio\DTO\MelEventData;
use Drupal\myeventlane_event_studio\Service\EventRepository;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Preprocesses page variables for the vendor theme (sidebar, header, URLs).
 *
 * Extracted from myeventlane_vendor_theme_preprocess_page(). Behaviour and
 * $variables structure are identical to the prior inline implementation.
 */
final class VendorThemePagePreprocess {

  use StringTranslationTrait;

  /**
   * Constructs a VendorThemePagePreprocess.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\myeventlane_core\Service\OptionalServiceResolver $optionalServiceResolver
   *   Resolver for optional services (e.g. domain_detector).
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   * @param \Drupal\myeventlane_core\Service\EntityIdNormalizer $entityIdNormalizer
   *   Normalizes IDs before entity loadMultiple() (avoids array_flip warnings).
   * @param \Drupal\myeventlane_event_studio\Service\EventRepository $eventRepository
   *   Event read model for studio switcher cards (no node loadMultiple in vendor).
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ?OptionalServiceResolver $optionalServiceResolver,
    TranslationInterface $stringTranslation,
    private readonly EntityIdNormalizer $entityIdNormalizer,
    private readonly EventRepository $eventRepository,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Preprocesses page variables for the vendor theme.
   *
   * @param array<string, mixed> $variables
   *   The preprocess variables (passed by reference).
   */
  public function preprocess(array &$variables): void {
    $account = $this->currentUser->getAccount();

    if ($this->currentUser->isAuthenticated()) {
      $variables['page']['user_name'] = $account->getDisplayName();
      $variables['page']['user_initials'] = $this->getInitials($account->getDisplayName());

      $roles = $this->currentUser->getRoles();
      if (in_array('vendor', $roles, TRUE)) {
        $variables['page']['user_role'] = $this->t('Vendor');
      }
      elseif (in_array('administrator', $roles, TRUE)) {
        $variables['page']['user_role'] = $this->t('Administrator');
      }
      else {
        $variables['page']['user_role'] = $this->t('User');
      }
    }

    $variables['page']['workspace_name'] = NULL;
    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface && $node->bundle() === 'event') {
      foreach (['field_event_vendor', 'field_vendor'] as $vendorField) {
        if ($node->hasField($vendorField) && !$node->get($vendorField)->isEmpty()) {
          $vendor = $node->get($vendorField)->entity;
          if ($vendor) {
            $variables['page']['workspace_name'] = $vendor->label();
            break;
          }
        }
      }
    }

    if ($variables['page']['workspace_name'] === NULL && $this->currentUser->isAuthenticated()) {
      try {
        $vendorResolver = $this->optionalServiceResolver?->get('myeventlane_vendor.current_vendor_resolver');
        if ($vendorResolver && method_exists($vendorResolver, 'resolveFromCurrentUser')) {
          $vendor = $vendorResolver->resolveFromCurrentUser();
          if ($vendor) {
            $variables['page']['workspace_name'] = $vendor->label();
          }
        }
      }
      catch (\Throwable) {
        // Keep shell preprocessing resilient.
      }
    }

    try {
      $domain_detector = $this->optionalServiceResolver?->get('myeventlane_core.domain_detector');
      if ($domain_detector !== NULL) {
        $variables['page']['main_site_url'] = $domain_detector->buildDomainUrl('/', 'public');
      }
      else {
        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
          $scheme = $request->getScheme();
          $host = preg_replace('/^(vendor|admin)\./', '', $request->getHost());
          $variables['page']['main_site_url'] = $scheme . '://' . $host . '/';
        }
        else {
          $variables['page']['main_site_url'] = '/';
        }
      }
    }
    catch (\Exception) {
      $variables['page']['main_site_url'] = '/';
    }

    $variables['page']['is_admin'] = FALSE;
    $variables['page']['admin_portal_url'] = NULL;
    if ($this->currentUser->isAuthenticated()) {
      $roles = $this->currentUser->getRoles();
      if (in_array('administrator', $roles, TRUE) || $this->currentUser->id() === 1) {
        $variables['page']['is_admin'] = TRUE;

        try {
          $admin_url = Url::fromRoute('myeventlane_admin_dashboard.overview', [], ['absolute' => TRUE]);
          $variables['page']['admin_portal_url'] = $admin_url->toString(TRUE)->getGeneratedUrl();
        }
        catch (\Exception) {
          try {
            $domain_detector = $this->optionalServiceResolver?->get('myeventlane_core.domain_detector');
            if ($domain_detector !== NULL) {
              $variables['page']['admin_portal_url'] = $domain_detector->buildDomainUrl('/admin/myeventlane', 'admin');
            }
          }
          catch (\Exception) {
            // Service not available.
          }

          if (($variables['page']['admin_portal_url'] ?? '') === '') {
            $request = $this->requestStack->getCurrentRequest();
            if ($request) {
              $scheme = $request->getScheme();
              $host = preg_replace('/^vendor\./', 'admin.', $request->getHost());
              if (!str_starts_with($host, 'admin.')) {
                $host = 'admin.' . preg_replace('/^(admin|vendor)\./', '', $host);
              }
              $variables['page']['admin_portal_url'] = $scheme . '://' . $host . '/admin/myeventlane';
            }
            else {
              $variables['page']['admin_portal_url'] = '/admin/myeventlane';
            }
          }
        }
      }
    }

    $route_name = $this->routeMatch->getRouteName();
    $variables['page']['active_section'] = $this->getActiveSection($route_name);
    $variables['page']['vendor_shell_nav_items'] = $this->buildVendorShellNavItems($variables['page']['active_section'], $route_name);
    $variables['page']['shell_page_title'] = $this->getShellPageTitle($route_name);
    $variables['page']['shell_page_subtitle'] = $this->getShellPageSubtitle($route_name);
    $variables['page']['shell_primary_action'] = $this->getShellPrimaryAction();
    if ($this->isEventStudioShellRoute($route_name)) {
      [$selected_event_card, $other_event_cards] = $this->buildStudioSwitcherCards();
      $variables['page']['wizard_selected_event'] = $selected_event_card;
      $variables['page']['wizard_other_events'] = $other_event_cards;
    }

    $variables['page']['logout_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Log out'),
      '#url' => Url::fromRoute('user.logout'),
    ];

    if ($this->currentUser->isAuthenticated()) {
      $user_menu_items = [
        [
          '#type' => 'link',
          '#title' => $this->t('Profile'),
          '#url' => Url::fromRoute('entity.user.canonical', ['user' => $this->currentUser->id()]),
          '#attributes' => ['class' => ['user-menu__item']],
        ],
        [
          '#type' => 'link',
          '#title' => $this->t('Log out'),
          '#url' => Url::fromRoute('user.logout'),
          '#attributes' => ['class' => ['user-menu__item']],
        ],
      ];
      $variables['page']['user_menu'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['user-menu']],
        'user_info' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['user-menu__info']],
          'initials' => [
            '#markup' => '<span class="user-menu__initials">' . ($variables['page']['user_initials'] ?? 'U') . '</span>',
          ],
          'name' => [
            '#markup' => '<span class="user-menu__name">' . ($variables['page']['user_name'] ?? 'User') . '</span>',
          ],
        ],
        'dropdown' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['user-menu__dropdown']],
          'items' => $user_menu_items,
        ],
      ];
    }
    else {
      $variables['page']['user_menu'] = [];
    }

    $variables['page']['quick_actions'] = [];
    if (($variables['page']['is_admin'] ?? FALSE) && !empty($variables['page']['admin_portal_url'])) {
      $variables['page']['quick_actions'][] = [
        '#type' => 'link',
        '#title' => $this->t('Admin Portal'),
        '#url' => Url::fromUri($variables['page']['admin_portal_url']),
        '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary', 'mel-header__action-btn']],
      ];
    }
    if ($this->currentUser->isAuthenticated()) {
      $variables['page']['quick_actions'][] = [
        '#type' => 'link',
        '#title' => $this->t('+ Create Event'),
        '#url' => Url::fromRoute('myeventlane_event_studio.create'),
        '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary', 'mel-header__action-btn']],
      ];
    }

    if ($route_name === 'myeventlane_vendor.console.settings' && isset($variables['page']['content']['vendor_profile_settings'])) {
      $form = $variables['page']['content']['vendor_profile_settings'];
      $variables['page']['content']['vendor_profile_settings'] = [
        '#theme' => 'myeventlane_vendor_console_page',
        '#title' => 'Settings',
        '#body' => $form,
        '#attached' => $form['#attached'] ?? [],
      ];
    }
  }

  /**
   * Get user initials from display name.
   */
  private function getInitials(string $name): string {
    $parts = explode(' ', trim($name));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
      if ($part !== '') {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
      }
    }
    return $initials !== '' ? $initials : 'U';
  }

  /**
   * Determine active section from route name.
   */
  private function getActiveSection(?string $route_name): string {
    if ($route_name === NULL || $route_name === '') {
      return 'dashboard';
    }
    $mapping = [
      'myeventlane_vendor.console.dashboard' => 'dashboard',
      'myeventlane_vendor.console.events' => 'events',
      'myeventlane_vendor.console.events_add' => 'events',
      'entity.node.edit_form' => 'events',
      'myeventlane_event_studio.create' => 'events',
      'myeventlane_event_studio.edit' => 'events',
      'myeventlane_vendor.console.event_overview' => 'events',
      'myeventlane_vendor.console.event_workspace' => 'events',
      'myeventlane_vendor.console.event_editor' => 'events',
      'myeventlane_vendor.console.event_tickets' => 'events',
      'myeventlane_vendor.console.event_promotion' => 'events',
      'myeventlane_vendor.console.event_analytics' => 'events',
      'myeventlane_vendor.console.event_settings' => 'events',
      'myeventlane_vendor.console.event_publish' => 'events',
      'myeventlane_vendor.console.event_order_view' => 'orders',
      'myeventlane_vendor.console.event_orders' => 'orders',
      'myeventlane_checkout_flow.vendor_attendees' => 'attendees',
      'myeventlane_vendor.console.event_attendees' => 'attendees',
      'myeventlane_vendor.console.event_rsvps' => 'attendees',
      'myeventlane_vendor.console.payouts' => 'payouts',
      'myeventlane_vendor.console.boost' => 'growth',
      'myeventlane_vendor.console.audience' => 'audience',
      'myeventlane_vendor.console.settings' => 'settings',
      'myeventlane_vendor.console.messaging_brand' => 'settings',
      'myeventlane_help_centre.vendor_help' => 'help',
      'myeventlane_help_centre.home' => 'help',
      'myeventlane_help_centre.vendors_index' => 'help',
      'myeventlane_vendor.dashboard' => 'dashboard',
      'myeventlane_vendor.console.dashboard' => 'dashboard',
    ];
    return $mapping[$route_name] ?? 'dashboard';
  }

  /**
   * Builds primary vendor navigation items for the shared shell sidebar.
   *
   * @param string $active_section
   *   The active section key.
   * @param string|null $route_name
   *   The current route name.
   *
   * @return array<int, array<string, mixed>>
   *   Sidebar navigation items with safe route handling.
   */
  private function buildVendorShellNavItems(string $active_section, ?string $route_name): array {
    $wizard_menu = $this->buildEventWizardSubmenu($route_name);
    $items = [
      [
        'key' => 'dashboard',
        'label' => $this->t('Dashboard'),
        'icon' => 'dashboard',
        'route' => 'myeventlane_vendor.console.dashboard',
      ],
      [
        'key' => 'events',
        'label' => $this->t('Events'),
        'icon' => 'events',
        'route' => 'myeventlane_vendor.console.events',
        'children' => $wizard_menu,
      ],
      [
        'key' => 'orders',
        'label' => $this->t('Orders'),
        'icon' => 'orders',
        'route' => NULL,
      ],
      [
        'key' => 'attendees',
        'label' => $this->t('Attendees'),
        'icon' => 'attendees',
        'route' => 'myeventlane_checkout_flow.vendor_attendees',
      ],
      [
        'key' => 'audience',
        'label' => $this->t('Audience'),
        'icon' => 'audience',
        'route' => 'myeventlane_vendor.console.audience',
      ],
      [
        'key' => 'payouts',
        'label' => $this->t('Payouts'),
        'icon' => 'payouts',
        'route' => 'myeventlane_vendor.console.payouts',
      ],
      [
        'key' => 'growth',
        'label' => $this->t('Growth'),
        'icon' => 'growth',
        'route' => 'myeventlane_vendor.console.boost',
      ],
      [
        'key' => 'settings',
        'label' => $this->t('Settings'),
        'icon' => 'settings',
        'route' => 'myeventlane_vendor.console.settings',
      ],
      [
        'key' => 'help',
        'label' => $this->t('Help'),
        'icon' => 'help',
        'route' => 'myeventlane_help_centre.home',
        'path_fallback' => '/help',
      ],
    ];

    $built = [];
    foreach ($items as $item) {
      $url = NULL;
      $is_disabled = FALSE;

      if (!empty($item['route'])) {
        try {
          $url = Url::fromRoute((string) $item['route'])->toString();
        }
        catch (\Throwable) {
          if (!empty($item['path_fallback'])) {
            $url = (string) $item['path_fallback'];
            $is_disabled = FALSE;
          }
          else {
            $is_disabled = TRUE;
          }
        }
      }
      else {
        $is_disabled = TRUE;
      }

      $item['url'] = $url;
      $item['is_disabled'] = $is_disabled;
      $item['is_active'] = $active_section === $item['key'];
      $built[] = $item;
    }

    return $built;
  }

  /**
   * Builds events submenu links for canonical event wizard steps.
   *
   * @param string|null $route_name
   *   The current route name.
   *
   * @return array<int, array<string, mixed>>
   *   Child menu items for the events navigation item.
   */
  private function buildEventWizardSubmenu(?string $route_name): array {
    $event_id = $this->resolveCurrentEventId();
    if ($event_id === NULL) {
      return [];
    }

    $url = $this->safeRouteToString('myeventlane_event_studio.edit', ['node' => $event_id]);
    $is_active = $route_name === 'myeventlane_event_studio.edit';

    return [
      [
        'label' => (string) $this->t('Event builder'),
        'url' => $url,
        'is_disabled' => $url === NULL,
        'is_active' => $is_active,
      ],
    ];
  }

  /**
   * Resolves the current event id from route parameters when available.
   *
   * @return int|null
   *   The event node id, or NULL if there is no event context.
   */
  private function resolveCurrentEventId(): ?int {
    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface && $node->bundle() === 'event') {
      return (int) $node->id();
    }

    $event = $this->routeMatch->getParameter('event');
    if ($event instanceof NodeInterface && $event->bundle() === 'event') {
      return (int) $event->id();
    }

    if (is_numeric($node) && (int) $node > 0) {
      return (int) $node;
    }
    if (is_numeric($event) && (int) $event > 0) {
      return (int) $event;
    }

    return NULL;
  }

  /**
   * Builds a route URL string and safely returns NULL on failures.
   *
   * @param string $route_name
   *   Route machine name.
   * @param array<string, mixed> $route_parameters
   *   Optional route parameters.
   *
   * @return string|null
   *   Route URL string or NULL if route is unavailable.
   */
  private function safeRouteToString(string $route_name, array $route_parameters = [], array $options = []): ?string {
    try {
      return Url::fromRoute($route_name, $route_parameters, $options)->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Returns a user-friendly page title for the shell header.
   */
  private function getShellPageTitle(?string $route_name): string {
    $mapping = [
      'myeventlane_vendor.console.dashboard' => (string) $this->t('Dashboard'),
      'myeventlane_vendor.console.events' => (string) $this->t('Events'),
      'myeventlane_vendor.console.payouts' => (string) $this->t('Payouts'),
      'myeventlane_vendor.console.boost' => (string) $this->t('Growth'),
      'myeventlane_vendor.console.audience' => (string) $this->t('Audience'),
      'myeventlane_vendor.console.settings' => (string) $this->t('Settings'),
      'myeventlane_vendor.console.studio' => (string) $this->t('Studio'),
    ];

    return $mapping[$route_name ?? ''] ?? (string) $this->t('Vendor workspace');
  }

  /**
   * Returns a short subtitle for the shell header.
   */
  private function getShellPageSubtitle(?string $route_name): string {
    $mapping = [
      'myeventlane_vendor.console.dashboard' => (string) $this->t('Track performance and keep your next steps clear.'),
      'myeventlane_vendor.console.events' => (string) $this->t('Manage your event lineup and publish with confidence.'),
      'myeventlane_vendor.console.payouts' => (string) $this->t('Review balances, transfers, and payout readiness.'),
      'myeventlane_vendor.console.boost' => (string) $this->t('Grow your reach with promotion tools.'),
      'myeventlane_vendor.console.audience' => (string) $this->t('Understand and engage your attendees.'),
      'myeventlane_vendor.console.settings' => (string) $this->t('Update account, profile, and workspace preferences.'),
      'myeventlane_vendor.console.studio' => (string) $this->t('Build and refine your event experience.'),
    ];

    return $mapping[$route_name ?? ''] ?? (string) $this->t('Welcome to your MyEventLane organiser workspace.');
  }

  /**
   * Returns shell header primary action when available.
   *
   * @return array<string, string>|null
   *   Primary action link data.
   */
  private function getShellPrimaryAction(): ?array {
    if (!$this->currentUser->isAuthenticated()) {
      return NULL;
    }

    try {
      return [
        'label' => (string) $this->t('Create event'),
        'url' => Url::fromRoute('myeventlane_event_studio.create')->toString(),
      ];
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Vendor shell: show event switcher only on Event Studio routes (not legacy wizard).
   *
   * Legacy step-wizard and node add/edit remain mappable in the sidebar for staff
   * who hit those URLs directly; they do not enable vendor event-switcher chrome.
   */
  private function isEventStudioShellRoute(?string $route_name): bool {
    if ($route_name === 'myeventlane_event_studio.create') {
      return TRUE;
    }
    if ($route_name === 'myeventlane_event_studio.edit') {
      try {
        $node = $this->routeMatch->getParameter('node');
        return $node instanceof NodeInterface && $node->bundle() === 'event';
      }
      catch (\Throwable) {
        return FALSE;
      }
    }
    return FALSE;
  }

  /**
   * Builds selected + other event cards for Event Studio shell (DTO-backed).
   *
   * @return array{0: array<string, mixed>|null, 1: array<int, array<string, mixed>>}
   *   Selected event card and list of other event cards.
   */
  private function buildStudioSwitcherCards(): array {
    $selected_id = $this->resolveCurrentEventId();
    if (!$this->currentUser->isAuthenticated()) {
      return [NULL, []];
    }

    $query_result = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('uid', (int) $this->currentUser->id())
      ->sort('changed', 'DESC')
      ->range(0, 24)
      ->execute();
    if ($query_result === []) {
      return [NULL, []];
    }

    $nids = $this->entityIdNormalizer->normalizeNodeIds(array_values($query_result));
    if ($nids === []) {
      return [NULL, []];
    }

    $selected = NULL;
    $others = [];
    foreach ($this->eventRepository->loadMany($nids) as $dto) {
      if (!$dto instanceof MelEventData) {
        continue;
      }

      $event_id = $dto->id;
      $card = [
        'id' => $event_id,
        'title' => $dto->title,
        'date' => $this->formatStudioCardDate($dto),
        'status' => $this->formatStudioCardStatus($dto),
        'url' => $this->safeRouteToString('myeventlane_event_studio.edit', ['node' => $event_id]),
      ];
      if ($selected_id !== NULL && $event_id === $selected_id) {
        $selected = $card;
      }
      else {
        $others[] = $card;
      }
    }

    return [$selected, $others];
  }

  private function formatStudioCardDate(MelEventData $dto): string {
    if ($dto->start_timestamp > 0) {
      return date('D, j M Y - H:i', $dto->start_timestamp);
    }
    if ($dto->start !== NULL && $dto->start !== '') {
      $timestamp = strtotime($dto->start);
      if ($timestamp !== FALSE) {
        return date('D, j M Y - H:i', $timestamp);
      }
      return $dto->start;
    }
    return (string) $this->t('Date TBD');
  }

  private function formatStudioCardStatus(MelEventData $dto): string {
    if ($dto->moderation_state !== '') {
      return match ($dto->moderation_state) {
        'published', 'live' => (string) $this->t('Published'),
        'review', 'needs_review' => (string) $this->t('Review'),
        default => (string) $this->t('Draft'),
      };
    }
    return $dto->published ? (string) $this->t('Published') : (string) $this->t('Draft');
  }

}
