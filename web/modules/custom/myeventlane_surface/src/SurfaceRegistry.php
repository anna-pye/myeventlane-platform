<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;

/**
 * Canonical registry of MEL surfaces (documentation + runtime governance hints).
 */
final class SurfaceRegistry {

  /**
   * @return array<string, \Drupal\myeventlane_surface\SurfaceDefinition>
   *   Definitions keyed by surface enum value.
   */
  public function all(): array {
    $surfaces = [];

    $surfaces[MelSurfaceId::Public->value] = new SurfaceDefinition(
      id: MelSurfaceId::Public,
      label: 'Public surface',
      routeOwnership: [
        'owns' => [
          '<front>',
          'Public marketing and discovery routes',
          'Content entity canonical routes for events and taxonomy used by the public theme',
        ],
        'does_not_own' => [
          '/admin/*',
          '/vendor/*',
          '/my-account',
          '/my-* reserved customer prefixes where dedicated routes exist',
        ],
      ],
      layoutOwnership: [
        'shell' => 'myeventlane_theme page.html.twig family; optional mel-surface-public include',
        'regions' => 'Standard public regions (header, hero, content, footer).',
      ],
      navigationOwnership: [
        'primary' => 'Global discovery / site_header primary menu',
        'secondary' => 'Footer + contextual CTAs',
      ],
      allowedComponents: [
        'site_header',
        'discovery_cards',
        'trust_panels',
        'event_cards',
        'global_footer',
      ],
      formOwnership: [
        'Uses canonical MEL form presentation tokens via theme + mel_surface_* wrappers.',
        'Checkout/booking flows inherit commerce checkout surfaces with minimal chrome.',
      ],
      blockVisibilityRules: [
        'Global promotional blocks allowed unless superseded by checkout/auth governance.',
      ],
      uxRules: [
        'Marketing clarity; avoid vendor/customer operational clutter.',
      ],
      accessibilityExpectations: [
        'WCAG 2.1 AA for navigation landmarks, focus order, contrast.',
      ],
    );

    $surfaces[MelSurfaceId::Auth->value] = new SurfaceDefinition(
      id: MelSurfaceId::Auth,
      label: 'Authentication surface',
      routeOwnership: [
        'owns' => [
          'user.login',
          'user.register',
          'user.pass',
          'user.reset',
          'user.reset.login',
          'Email verification flows tied to core user routes',
          'Reserved for future MFA / social auth routes',
        ],
        'does_not_own' => [
          'Customer dashboard URLs (/my-account, /my-tickets, …)',
          'Vendor console (/vendor/*)',
        ],
      ],
      layoutOwnership: [
        'shell' => 'mel-surface-auth + minimal public chrome; Gin Login remains responsible for auth mechanics',
        'principle' => 'MEL owns presentation shell; Gin owns login form mechanics where enabled.',
      ],
      navigationOwnership: [
        'primary' => 'Minimal — branding link only via simplified header',
        'exclude' => ['Primary discovery menu', 'Create Event CTA strip', 'Commerce cart chip'],
      ],
      allowedComponents: [
        'compact_site_header',
        'status_messages',
        'core_auth_forms',
      ],
      formOwnership: [
        'Core User forms under MEL spacing + shared field/error patterns.',
      ],
      blockVisibilityRules: [
        'Suppress global commerce/marketing blocks that leak into auth flows.',
        'Uses SurfaceBlockGovernor denylist + simplified header rendering.',
      ],
      uxRules: [
        'Remove promotional noise; single-task focus; preserve escape hatch to home.',
      ],
      accessibilityExpectations: [
        'Announce errors; preserve labels and inline validation guidance.',
      ],
    );

    $surfaces[MelSurfaceId::Customer->value] = new SurfaceDefinition(
      id: MelSurfaceId::Customer,
      label: 'Customer shell',
      routeOwnership: [
        'owns' => [
          'myeventlane_account.dashboard (/my-account)',
          'myeventlane_account.settings (/my-settings/{user}), bare /my-settings redirects',
          'myeventlane_account.past_events (/my-past-events)',
          'myeventlane_checkout_flow.my_tickets (/my-tickets)',
          'myeventlane_checkout_flow.order_detail',
          'myeventlane_dashboard.customer (/my-events)',
          'Customer RSVP/profile helpers under /my-* where routed',
        ],
        'does_not_own' => [
          '/vendor/*',
          '/admin/*',
          'Public discovery shell chrome beyond shared header/footer contracts',
        ],
      ],
      layoutOwnership: [
        'shell' => 'page--account.html.twig shared sidebar layout',
        'cards' => 'Account dashboard cards + settings sections',
      ],
      navigationOwnership: [
        'primary' => 'Account sidebar via AccountLinksService',
        'exclude' => ['Duplicate Drupal account toolbar menus rendered as primary IA'],
      ],
      allowedComponents: [
        'account_cards',
        'ticket_lists',
        'rsvp_summaries',
        'settings_sections',
        'embedded_profile_forms_wrapped_by_mel_surface_customer_profile_settings',
      ],
      formOwnership: [
        'Canonical mel_surface_customer_profile_settings wrapper around entity user form API.',
        'Shared validation/error/helper patterns via theme form styles.',
      ],
      blockVisibilityRules: [
        'Prevent promotional blocks from replacing operational IA inside customer shell pages.',
      ],
      uxRules: [
        'Never expose raw Drupal profile URLs as primary IA for self-service.',
      ],
      accessibilityExpectations: [
        'Landmarks for nav + main; consistent heading order inside cards.',
      ],
    );

    $surfaces[MelSurfaceId::Vendor->value] = new SurfaceDefinition(
      id: MelSurfaceId::Vendor,
      label: 'Vendor shell',
      routeOwnership: [
        'owns' => [
          '/vendor/* operational console',
          'Stripe onboarding returns registered under vendor module routes',
        ],
        'does_not_own' => [
          '/admin/* staff tooling',
          'Anonymous public discovery except vendor profiles surfaced publicly',
        ],
      ],
      layoutOwnership: [
        'shell' => 'myeventlane_vendor_theme layouts',
        'focus' => 'Operational density with vendor isolation preserved server-side',
      ],
      navigationOwnership: [
        'primary' => 'Vendor console navigation + Studio entry points',
      ],
      allowedComponents: [
        'vendor_dashboard_cards',
        'event_editor_shells',
        'stripe_status_panels',
        'analytics_blocks scoped by store ownership',
      ],
      formOwnership: [
        'Commerce + entity forms remain underneath; presentation owned by vendor theme policies.',
      ],
      blockVisibilityRules: [
        'Do not inject public marketing hero blocks into vendor operational flows.',
      ],
      uxRules: [
        'Preserve vendor isolation + store ownership checks on every mutation.',
      ],
      accessibilityExpectations: [
        'Tables and KPI bars maintain readable contrast + keyboard access.',
      ],
    );

    $surfaces[MelSurfaceId::Staff->value] = new SurfaceDefinition(
      id: MelSurfaceId::Staff,
      label: 'Staff / administration surface',
      routeOwnership: [
        'owns' => [
          '/admin/*',
          'Staff dashboards + moderation/support tooling behind permissions',
        ],
        'does_not_own' => [
          'Customer or vendor product shells',
        ],
      ],
      layoutOwnership: [
        'shell' => 'Gin / Claro administrative themes — do not fork presentation here',
      ],
      navigationOwnership: [
        'primary' => 'Drupal toolbar + admin menus',
      ],
      allowedComponents: [
        'core_admin_components',
      ],
      formOwnership: [
        'Core entity forms permitted for privileged roles; unchanged theming policy.',
      ],
      blockVisibilityRules: [
        'No change to Gin block placements from this module.',
      ],
      uxRules: [
        'Operational truthfulness over marketing polish.',
      ],
      accessibilityExpectations: [
        'Follow contrib admin theme AA commitments.',
      ],
    );

    return $surfaces;
  }

}
