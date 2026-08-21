<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Builds the organiser Account settings hub view model.
 *
 * Deep-links existing profile, brand, venues, questions, payments, support.
 * Does not invent parallel Stripe, Commerce, or Drupal configuration UIs.
 */
final class VendorSettingsHubBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly CurrentVendorResolverInterface $vendorResolver,
    private readonly VendorWorkspaceHealthService $workspaceHealth,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Builds the Settings hub payload.
   *
   * @return array<string, mixed>
   *   Hub view model for Twig.
   */
  public function build(): array {
    $uid = (int) $this->currentUser->id();
    $vendor = $this->vendorResolver->resolveFromCurrentUser();
    $health = $this->workspaceHealth->buildForCurrentUser($vendor);

    $this->logger->info('settings_hub_opened uid=@uid tone=@tone', [
      '@uid' => (string) $uid,
      '@tone' => (string) ($health['tone'] ?? 'unknown'),
    ]);

    return [
      'title' => (string) $this->t('Account settings'),
      'lede' => (string) $this->t('Manage your organiser profile, brand, payment connections and reusable preferences. Event-specific choices stay inside each Event Studio.'),
      'health' => $health,
      'sections' => [
        $this->section(
          'profile',
          (string) $this->t('Profile'),
          (string) $this->t('Organisation name, logo, contact email, public profile, timezone, and team.'),
          (string) $this->t('Edit profile'),
          $this->safeRouteUrl('myeventlane_vendor.console.settings_profile') ?? '/vendor/settings/profile',
        ),
        $this->section(
          'brand',
          (string) $this->t('Brand'),
          (string) $this->t('Logo, email brand defaults, and how guests recognise your messages. Event look-and-feel stays in Event Workspace.'),
          (string) $this->t('Manage brand'),
          $this->safeRouteUrl('myeventlane_vendor.console.messaging_brand')
            ?? $this->safeRouteUrl('myeventlane_vendor.console.settings_profile', [], ['fragment' => 'visual-assets'])
            ?? '/vendor/settings/profile#visual-assets',
          [
            [
              'label' => (string) $this->t('Profile logo & colour'),
              'url' => $this->safeRouteUrl('myeventlane_vendor.console.settings_profile', [], ['fragment' => 'visual-assets'])
              ?? '/vendor/settings/profile#visual-assets',
            ],
            [
              'label' => (string) $this->t('Messages brand'),
              'url' => $this->safeRouteUrl('myeventlane_vendor.console.messaging_brand'),
            ],
            [
              'label' => (string) $this->t('Pro branding'),
              'url' => $this->moduleHandler->moduleExists('myeventlane_pro')
                ? $this->safeRouteUrl('myeventlane_pro.branding')
                : NULL,
            ],
          ],
          [
            'future' => [
              (string) $this->t('Brand colour themes beyond accent colour'),
              (string) $this->t('Social share preview templates'),
              (string) $this->t('Event defaults pack (shared ticket copy)'),
            ],
          ],
        ),
        $this->section(
          'payments',
          (string) $this->t('Payments'),
          (string) $this->t('Connected Stripe account, Stripe payouts, refunds, and tax exports — managed in Payments.'),
          (string) $this->t('Open Payments'),
          $this->safeRouteUrl('myeventlane_vendor.console.payments') ?? '/vendor/payments',
        ),
        $this->section(
          'notifications',
          (string) $this->t('Notifications'),
          (string) $this->t('Booking emails, RSVP alerts, and digests. Message delivery lives in Messages.'),
          (string) $this->t('Edit notifications'),
          $this->safeRouteUrl('myeventlane_vendor.console.settings_profile', [], ['fragment' => 'notifications'])
            ?? '/vendor/settings/profile#notifications',
          [
            [
              'label' => (string) $this->t('Inbox & alerts'),
              'url' => $this->moduleHandler->moduleExists('myeventlane_notifications')
                ? $this->safeRouteUrl('myeventlane_notifications.preferences')
                : NULL,
            ],
            [
              'label' => (string) $this->t('Messages'),
              'url' => $this->safeRouteUrl('myeventlane_vendor.console.messages'),
            ],
          ],
          [
            'missing' => [
              (string) $this->t('Per-channel refund notification toggles'),
              (string) $this->t('Quiet hours / digest quiet mode'),
              (string) $this->t('SMS preferences'),
            ],
          ],
        ),
        $this->section(
          'venues',
          (string) $this->t('Venues'),
          (string) $this->t('Saved venues you reuse when creating events.'),
          (string) $this->t('Manage venues'),
          $this->safeRouteUrl('myeventlane_venue.vendor_venues') ?? '/vendor/settings/venues',
        ),
        $this->section(
          'guest-questions',
          (string) $this->t('Guest questions'),
          (string) $this->t('Reusable questions for checkout and RSVP. Per-event questions stay in Event Workspace.'),
          (string) $this->t('Open question library'),
          $this->safeRouteUrl('myeventlane_questions.library') ?? '/vendor/questions',
        ),
        $this->section(
          'support',
          (string) $this->t('Support'),
          (string) $this->t('Open requests and a calm path to contact the MyEventLane team.'),
          (string) $this->t('Open Support'),
          $this->safeRouteUrl('myeventlane_escalations_portal.vendor_list') ?? '/vendor/support',
        ),
        $this->section(
          'help',
          (string) $this->t('Help Centre'),
          (string) $this->t('Search guides, organiser articles, and the Help Assistant.'),
          (string) $this->t('Browse Help'),
          $this->safeRouteUrl('myeventlane_help_centre.home') ?? '/help',
          [
            [
              'label' => (string) $this->t('Organiser help'),
              'url' => $this->safeRouteUrl('myeventlane_help_centre.organisers_index')
              ?? $this->safeRouteUrl('myeventlane_help_centre.vendors_index'),
            ],
            [
              'label' => (string) $this->t('Help Assistant'),
              'url' => $this->moduleHandler->moduleExists('myeventlane_help_assistant')
                ? $this->safeRouteUrl('myeventlane_help_assistant.page')
                : NULL,
            ],
          ],
        ),
        $this->section(
          'policies',
          (string) $this->t('Policies'),
          (string) $this->t('Terms, privacy, cookies, refund policy, and public trust.'),
          (string) $this->t('View policies'),
          $this->safeRouteUrl('myeventlane_help_centre.policies_index') ?? '/help/policies',
          [
            [
              'label' => (string) $this->t('Terms'),
              'url' => '/terms',
            ],
            [
              'label' => (string) $this->t('Privacy'),
              'url' => '/privacy',
            ],
            [
              'label' => (string) $this->t('Refund policy'),
              'url' => '/refund-policy',
            ],
            [
              'label' => (string) $this->t('Cookies'),
              'url' => $this->safeRouteUrl('myeventlane_legal.cookies') ?? '/cookies',
            ],
          ],
        ),
        $this->section(
          'danger',
          (string) $this->t('Danger Zone'),
          (string) $this->t('Subscription changes and irreversible account actions. Event archive lives in Event Settings.'),
          (string) $this->t('Manage Pro'),
          $this->safeRouteUrl('myeventlane_pro.manage')
            ?? $this->safeRouteUrl('myeventlane_pro.overview')
            ?? $this->safeRouteUrl('myeventlane_vendor.console.settings_profile')
            ?? '/vendor/settings/profile',
          [
            [
              'label' => (string) $this->t('Cancel Pro'),
              'url' => $this->moduleHandler->moduleExists('myeventlane_pro')
                ? $this->safeRouteUrl('myeventlane_pro.cancel_request')
                : NULL,
            ],
          ],
        ),
      ],
      'analytics' => [
        'settings_hub_opened' => TRUE,
      ],
    ];
  }

  /**
   * Builds one Settings hub section card.
   *
   * @param string $id
   *   Section anchor id.
   * @param string $title
   *   Section title.
   * @param string $body
   *   Section body copy.
   * @param string $cta_label
   *   Primary CTA label.
   * @param string|null $cta_url
   *   Primary CTA URL.
   * @param list<array{label: string, url: ?string}> $links
   *   Optional secondary links.
   * @param array{future?: list<string>, missing?: list<string>} $meta
   *   Optional future/missing notes.
   *
   * @return array<string, mixed>
   *   Section view model.
   */
  private function section(
    string $id,
    string $title,
    string $body,
    string $cta_label,
    ?string $cta_url,
    array $links = [],
    array $meta = [],
  ): array {
    $cleanLinks = [];
    foreach ($links as $link) {
      if (!empty($link['url'])) {
        $cleanLinks[] = [
          'label' => $link['label'],
          'url' => $link['url'],
        ];
      }
    }

    return [
      'id' => $id,
      'title' => $title,
      'body' => $body,
      'cta_label' => $cta_label,
      'cta_url' => $cta_url,
      'links' => $cleanLinks,
      'future' => $meta['future'] ?? [],
      'missing' => $meta['missing'] ?? [],
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
