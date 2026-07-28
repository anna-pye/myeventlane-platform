<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Psr\Log\LoggerInterface;

/**
 * Workspace Health checklist for organiser Settings.
 *
 * Complements Payments / Messages / Marketing health language.
 * Reads existing entity + route state only — no invented config.
 */
final class VendorWorkspaceHealthService {

  use StringTranslationTrait;

  public function __construct(
    private readonly CurrentVendorResolverInterface $vendorResolver,
    private readonly VendorPaymentsHealthService $paymentsHealth,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Builds Workspace Health for the current organiser.
   *
   * @return array<string, mixed>
   *   Health card + checklist payload for Twig.
   */
  public function buildForCurrentUser(?Vendor $vendor = NULL): array {
    $vendor = $vendor ?? $this->vendorResolver->resolveFromCurrentUser();
    $payment = $this->paymentsHealth->buildForCurrentUser($vendor);

    $items = [
      $this->profileItem($vendor),
      $this->stripeItem($payment),
      $this->brandingItem($vendor),
      $this->notificationsItem($vendor),
      $this->policiesItem(),
      $this->helpItem(),
    ];

    $attention = array_values(array_filter(
      $items,
      static fn(array $item): bool => ($item['tone'] ?? '') === 'attention',
    ));
    $muted = array_values(array_filter(
      $items,
      static fn(array $item): bool => ($item['tone'] ?? '') === 'muted',
    ));

    if ($attention !== []) {
      $tone = 'attention';
      $headline = (string) $this->t('A few account settings need attention');
      $summary = (string) $this->t('Finish the highlighted items so guests and payouts stay smooth.');
      $next = (string) ($attention[0]['next_step'] ?? $this->t('Open the first yellow item below.'));
      $cta_label = (string) ($attention[0]['cta_label'] ?? $this->t('Review settings'));
      $cta_url = (string) ($attention[0]['cta_url'] ?? $this->safeRouteUrl('myeventlane_vendor.console.settings_profile') ?? '/vendor/settings/profile');
    }
    elseif ($muted !== []) {
      $tone = 'muted';
      $headline = (string) $this->t('Your Workspace is almost ready');
      $summary = (string) $this->t('Core settings look good. Review optional items when you have a moment.');
      $next = (string) $this->t('Skim notifications and brand defaults before your next event.');
      $cta_label = (string) $this->t('Review profile');
      $cta_url = $this->safeRouteUrl('myeventlane_vendor.console.settings_profile') ?? '/vendor/settings/profile';
    }
    else {
      $tone = 'success';
      $headline = (string) $this->t('Your Workspace is in good shape');
      $summary = (string) $this->t('Profile, payments, brand, and help resources are ready for guests.');
      $next = (string) $this->t('Come back anytime you need to update defaults or contact support.');
      $cta_label = (string) $this->t('Edit profile');
      $cta_url = $this->safeRouteUrl('myeventlane_vendor.console.settings_profile') ?? '/vendor/settings/profile';
    }

    $this->logger->info('settings_hub_health uid=@uid tone=@tone attention=@n', [
      '@uid' => (string) ($vendor?->getOwnerId() ?? 0),
      '@tone' => $tone,
      '@n' => (string) count($attention),
    ]);

    return [
      'tone' => $tone,
      'headline' => $headline,
      'summary' => $summary,
      'next_step' => $next,
      'cta_label' => $cta_label,
      'cta_url' => $cta_url,
      'needs_attention' => $attention !== [],
      'items' => $items,
    ];
  }

  /**
   * Builds the profile completeness checklist item.
   *
   * @return array<string, mixed>
   *   Checklist item payload.
   */
  private function profileItem(?Vendor $vendor): array {
    $complete = FALSE;
    if ($vendor instanceof Vendor) {
      $name = trim((string) ($vendor->getName() ?? ''));
      $email = '';
      if ($vendor->hasField('field_vendor_email') && !$vendor->get('field_vendor_email')->isEmpty()) {
        $email = trim((string) $vendor->get('field_vendor_email')->value);
      }
      elseif ($vendor->hasField('field_contact_email') && !$vendor->get('field_contact_email')->isEmpty()) {
        $email = trim((string) $vendor->get('field_contact_email')->value);
      }
      $complete = $name !== '' && $email !== '';
    }

    return [
      'key' => 'profile',
      'label' => (string) $this->t('Profile complete'),
      'tone' => $complete ? 'success' : 'attention',
      'icon' => $complete ? 'success' : 'attention',
      'detail' => $complete
        ? (string) $this->t('Organisation name and contact email are set.')
        : (string) $this->t('Add your organisation name and contact email.'),
      'next_step' => (string) $this->t('Complete your organiser profile.'),
      'cta_label' => (string) $this->t('Edit profile'),
      'cta_url' => $this->safeRouteUrl('myeventlane_vendor.console.settings_profile') ?? '/vendor/settings/profile',
    ];
  }

  /**
   * Builds the Stripe connection checklist item.
   *
   * @param array<string, mixed> $payment
   *   Payment health payload.
   *
   * @return array<string, mixed>
   *   Checklist item payload.
   */
  private function stripeItem(array $payment): array {
    $ready = !empty($payment['connected'])
      && !empty($payment['charges_enabled'])
      && !empty($payment['payouts_enabled']);
    $attention = !empty($payment['needs_attention']);

    return [
      'key' => 'stripe',
      'label' => $ready
        ? (string) $this->t('Stripe connected')
        : (string) $this->t('Stripe connection'),
      'tone' => $ready ? 'success' : ($attention ? 'attention' : 'muted'),
      'icon' => $ready ? 'success' : 'attention',
      'detail' => $ready
        ? (string) $this->t('You can sell tickets and receive payouts.')
        : (string) ($payment['summary'] ?? $this->t('Connect Stripe to get paid.')),
      'next_step' => (string) ($payment['next_step'] ?? $this->t('Open Payments to connect Stripe.')),
      'cta_label' => (string) $this->t('Open Payments'),
      'cta_url' => $this->safeRouteUrl('myeventlane_vendor.console.payments') ?? '/vendor/payments',
    ];
  }

  /**
   * Builds the branding checklist item.
   *
   * Configured only when a logo or an explicit Messages from-name is set.
   * Organisation display name alone must not count — runtime mail may fall
   * back to getName(), but Health should not imply brand defaults are ready.
   *
   * @return array<string, mixed>
   *   Checklist item payload.
   */
  private function brandingItem(?Vendor $vendor): array {
    $configured = FALSE;
    if ($vendor instanceof Vendor) {
      $hasLogo = FALSE;
      foreach (['field_vendor_logo', 'field_msg_logo', 'field_logo_image'] as $logoField) {
        if ($vendor->hasField($logoField) && !$vendor->get($logoField)->isEmpty()) {
          $hasLogo = TRUE;
          break;
        }
      }
      $hasFromName = FALSE;
      if ($vendor->hasField('field_msg_from_name') && !$vendor->get('field_msg_from_name')->isEmpty()) {
        $hasFromName = trim((string) $vendor->get('field_msg_from_name')->value) !== '';
      }
      $configured = $hasLogo || $hasFromName;
    }

    $brandUrl = $this->safeRouteUrl('myeventlane_vendor.console.messaging_brand')
      ?? $this->safeRouteUrl('myeventlane_vendor.console.settings_profile', [], ['fragment' => 'visual-assets'])
      ?? '/vendor/settings/profile';

    return [
      'key' => 'branding',
      'label' => $configured
        ? (string) $this->t('Branding configured')
        : (string) $this->t('Branding'),
      'tone' => $configured ? 'success' : 'attention',
      'icon' => $configured ? 'success' : 'attention',
      'detail' => $configured
        ? (string) $this->t('Logo or email brand defaults are ready.')
        : (string) $this->t('Add a logo or Messages brand so guests recognise you.'),
      'next_step' => (string) $this->t('Set your logo and email brand defaults.'),
      'cta_label' => (string) $this->t('Edit brand'),
      'cta_url' => $brandUrl,
    ];
  }

  /**
   * Builds the notifications checklist item.
   *
   * @return array<string, mixed>
   *   Checklist item payload.
   */
  private function notificationsItem(?Vendor $vendor): array {
    $notificationsUrl = $this->safeRouteUrl('myeventlane_vendor.console.settings_profile', [], ['fragment' => 'notifications'])
      ?? '/vendor/settings/profile#notifications';

    // Profile form defaults (daily digest + booking emails on) must not read as
    // incomplete without a dedicated “reviewed” flag, or Health stays yellow.
    return [
      'key' => 'notifications',
      'label' => (string) $this->t('Notification preferences'),
      'tone' => 'success',
      'icon' => 'success',
      'detail' => $vendor instanceof Vendor
        ? (string) $this->t('Booking and digest emails use your current preferences.')
        : (string) $this->t('Set booking and digest emails when your organiser profile is ready.'),
      'next_step' => (string) $this->t('Adjust notifications anytime.'),
      'cta_label' => (string) $this->t('Open notifications'),
      'cta_url' => $notificationsUrl,
    ];
  }

  /**
   * Builds the policies checklist item.
   *
   * @return array<string, mixed>
   *   Checklist item payload.
   */
  private function policiesItem(): array {
    $url = $this->safeRouteUrl('myeventlane_help_centre.policies_index')
      ?? '/help/policies';

    return [
      'key' => 'policies',
      'label' => (string) $this->t('Policies available'),
      'tone' => 'success',
      'icon' => 'success',
      'detail' => (string) $this->t('Terms, privacy, and refund policy are ready to share.'),
      'next_step' => (string) $this->t('Open Policies when guests ask.'),
      'cta_label' => (string) $this->t('View policies'),
      'cta_url' => $url,
    ];
  }

  /**
   * Builds the help resources checklist item.
   *
   * @return array<string, mixed>
   *   Checklist item payload.
   */
  private function helpItem(): array {
    $helpReady = $this->moduleHandler->moduleExists('myeventlane_help_centre');
    $url = $helpReady
      ? ($this->safeRouteUrl('myeventlane_help_centre.home') ?? '/help')
      : ($this->safeRouteUrl('myeventlane_escalations_portal.vendor_list') ?? '/vendor/support');

    return [
      'key' => 'help',
      'label' => (string) $this->t('Help resources ready'),
      'tone' => $helpReady ? 'success' : 'muted',
      'icon' => $helpReady ? 'success' : 'muted',
      'detail' => $helpReady
        ? (string) $this->t('Help Centre articles and Support are available.')
        : (string) $this->t('Contact Support if you need a hand.'),
      'next_step' => (string) $this->t('Browse Help or contact Support anytime.'),
      'cta_label' => (string) $this->t('Open Help Centre'),
      'cta_url' => $url,
    ];
  }

  /**
   * Resolves a route to a URL string, or NULL if unavailable.
   *
   * @param string $route
   *   Route name.
   * @param array<string, mixed> $params
   *   Route parameters.
   * @param array<string, mixed> $options
   *   URL options.
   *
   * @return string|null
   *   Absolute or relative URL, or NULL.
   */
  private function safeRouteUrl(string $route, array $params = [], array $options = []): ?string {
    try {
      return Url::fromRoute($route, $params, $options)->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
