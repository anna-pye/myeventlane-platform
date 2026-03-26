<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\OptionalServiceResolver;
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
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ?OptionalServiceResolver $optionalServiceResolver,
    TranslationInterface $stringTranslation,
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
    if ($this->isWizardRoute($route_name)) {
      [$selected_event_card, $other_event_cards] = $this->buildWizardEventCards();
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
        '#url' => Url::fromRoute('myeventlane_event.wizard.create'),
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
      'myeventlane_event.wizard.create' => 'events',
      'myeventlane_event.wizard.edit' => 'events',
      'myeventlane_event.wizard.basics' => 'events',
      'myeventlane_event.wizard.when_where' => 'events',
      'myeventlane_event.wizard.tickets' => 'events',
      'myeventlane_event.wizard.details' => 'events',
      'myeventlane_event.wizard.review' => 'events',
      'myeventlane_event.wizard.publish' => 'events',
      'myeventlane_event.wizard.success' => 'events',
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
    $has_event_context = $event_id !== NULL;
    if (!$has_event_context) {
      // Only show wizard step tabs when editing/viewing a specific event context.
      return [];
    }

    $items = [];
    $wizard_steps = [
      [
        'label' => (string) $this->t('Basics'),
        'route' => 'myeventlane_event.wizard.basics',
      ],
      [
        'label' => (string) $this->t('When & Where'),
        'route' => 'myeventlane_event.wizard.when_where',
      ],
      [
        'label' => (string) $this->t('Tickets'),
        'route' => 'myeventlane_event.wizard.tickets',
      ],
      [
        'label' => (string) $this->t('Details'),
        'route' => 'myeventlane_event.wizard.details',
      ],
      [
        'label' => (string) $this->t('Review'),
        'route' => 'myeventlane_event.wizard.review',
      ],
      [
        'label' => (string) $this->t('Publish'),
        'route' => 'myeventlane_event.wizard.publish',
      ],
    ];

    foreach ($wizard_steps as $wizard_step) {
      $url = NULL;
      if ($has_event_context) {
        $url = $this->safeRouteToString((string) $wizard_step['route'], [
          'event' => $event_id,
        ]);
      }

      $items[] = [
        'label' => $wizard_step['label'],
        'url' => $url,
        'is_disabled' => !$has_event_context || $url === NULL,
        'is_active' => (string) $route_name === (string) $wizard_step['route'],
      ];
    }

    return $items;
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
        'url' => Url::fromRoute('myeventlane_event.wizard.create')->toString(),
      ];
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Determines whether current route is an event wizard step route.
   */
  private function isWizardRoute(?string $route_name): bool {
    return in_array((string) $route_name, [
      'myeventlane_event.wizard.basics',
      'myeventlane_event.wizard.when_where',
      'myeventlane_event.wizard.tickets',
      'myeventlane_event.wizard.details',
      'myeventlane_event.wizard.review',
      'myeventlane_event.wizard.publish',
      'myeventlane_event.wizard.success',
    ], TRUE);
  }

  /**
   * Builds selected + other event cards for wizard pages.
   *
   * @return array{0: array<string, mixed>|null, 1: array<int, array<string, mixed>>}
   *   Selected event card and list of other event cards.
   */
  private function buildWizardEventCards(): array {
    $selected_id = $this->resolveCurrentEventId();
    if (!$this->currentUser->isAuthenticated()) {
      return [NULL, []];
    }

    $event_ids = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('uid', (int) $this->currentUser->id())
      ->sort('changed', 'DESC')
      ->range(0, 24)
      ->execute();
    if ($event_ids === []) {
      return [NULL, []];
    }

    $selected = NULL;
    $others = [];
    $events = $this->entityTypeManager->getStorage('node')->loadMultiple($event_ids);
    foreach ($events as $event) {
      if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
        continue;
      }

      $event_id = (int) $event->id();
      $card = [
        'id' => $event_id,
        'title' => $event->label(),
        'date' => $this->formatWizardEventDate($event),
        'status' => $this->formatWizardEventStatus($event),
        'url' => $this->safeRouteToString('myeventlane_event.wizard.basics', ['event' => $event_id]),
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

  /**
   * Formats event date copy for wizard cards.
   */
  private function formatWizardEventDate(NodeInterface $event): string {
    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $raw = (string) $event->get('field_event_start')->value;
      $timestamp = strtotime($raw);
      if ($timestamp !== FALSE) {
        return date('D, j M Y - H:i', $timestamp);
      }
      return $raw;
    }
    return (string) $this->t('Date TBD');
  }

  /**
   * Formats wizard card status label.
   */
  private function formatWizardEventStatus(NodeInterface $event): string {
    if ($event->hasField('moderation_state') && !$event->get('moderation_state')->isEmpty()) {
      $state = (string) $event->get('moderation_state')->value;
      return match ($state) {
        'published', 'live' => (string) $this->t('Published'),
        'review', 'needs_review' => (string) $this->t('Review'),
        default => (string) $this->t('Draft'),
      };
    }
    return $event->isPublished() ? (string) $this->t('Published') : (string) $this->t('Draft');
  }

}
