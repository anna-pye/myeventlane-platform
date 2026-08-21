<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Psr\Log\LoggerInterface;

/**
 * Builds the organiser Support hub view model.
 *
 * Calm entry to Help, policies, and open support requests.
 * Does not invent a new ticketing system.
 */
final class VendorSupportHubBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly CurrentVendorResolverInterface $vendorResolver,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Builds Support hub chrome around existing open requests.
   *
   * @param list<array<string, mixed>> $request_rows
   *   Pre-built request rows from the portal (already access-scoped).
   *
   * @return array<string, mixed>
   *   Hub view model for Twig.
   */
  public function build(array $request_rows = []): array {
    $vendor = $this->vendorResolver->resolveFromCurrentUser();
    // Count only non-terminal statuses. Callers may pass mixed history rows.
    $openRows = array_values(array_filter(
      $request_rows,
      static function (array $row): bool {
        $status = (string) ($row['status'] ?? '');
        return !in_array($status, ['resolved', 'closed'], TRUE);
      },
    ));
    $openCount = count($openRows);
    $needsAttention = $openCount > 0;

    $this->logger->info('support_hub_opened uid=@uid open=@open', [
      '@uid' => (string) $this->currentUser->id(),
      '@open' => (string) $openCount,
    ]);

    $tone = $needsAttention ? 'attention' : 'success';
    if (!$vendor instanceof Vendor) {
      $tone = 'muted';
    }

    return [
      'title' => (string) $this->t('Support'),
      'lede' => (string) $this->t('We’re here when you need a hand — calmly, clearly, and without jargon.'),
      'health' => [
        'tone' => $tone,
        'headline' => $needsAttention
          ? (string) $this->t('You have open support requests')
          : (string) $this->t('Nothing waiting right now'),
        'summary' => $needsAttention
          ? (string) $this->t('Review open requests below, or browse Help while you wait for a reply.')
          : (string) $this->t('Browse Help for guides, or contact us if something feels stuck.'),
        'next_step' => $needsAttention
          ? (string) $this->t('Open the newest request and reply if we are waiting on you.')
          : (string) $this->t('Search Help first — many organisers find the answer in under a minute.'),
        'cta_label' => (string) $this->t('Browse Help Centre'),
        'cta_url' => $this->safeRouteUrl('myeventlane_help_centre.home') ?? '/help',
      ],
      'search' => [
        'title' => (string) $this->t('Search Help'),
        'body' => (string) $this->t('Find guides for tickets, Stripe payouts, Messages, and more.'),
        'cta_label' => (string) $this->t('Search Help'),
        'cta_url' => $this->safeRouteUrl('myeventlane_help_centre.search')
        ?? $this->safeRouteUrl('myeventlane_help_centre.home')
        ?? '/help',
      ],
      'articles' => [
        'title' => (string) $this->t('Help articles'),
        'body' => (string) $this->t('Organiser guides written for real event days — not admin manuals.'),
        'cta_label' => (string) $this->t('Organiser help'),
        'cta_url' => $this->safeRouteUrl('myeventlane_help_centre.organisers_index')
        ?? $this->safeRouteUrl('myeventlane_help_centre.vendors_index')
        ?? '/help/organisers',
        'assistant_label' => (string) $this->t('Ask the Help Assistant'),
        'assistant_url' => $this->moduleHandler->moduleExists('myeventlane_help_assistant')
          ? $this->safeRouteUrl('myeventlane_help_assistant.page')
          : NULL,
      ],
      'contact' => [
        'title' => (string) $this->t('Contact Support'),
        'body' => (string) $this->t('Start a request when you need a human. We’ll keep the tone warm and the next step clear.'),
        'cta_label' => (string) $this->t('Contact Support'),
        // Organiser create path stamps vendor_id so the case appears in Open requests.
        'cta_url' => $this->safeRouteUrl('myeventlane_escalations_portal.vendor_add')
        ?? '/vendor/support/add',
      ],
      'requests' => [
        'title' => (string) $this->t('Open requests'),
        'body' => (string) $this->t('Requests for your organiser account — including ones you start here.'),
        'empty_title' => (string) $this->t('No open requests'),
        'empty_body' => (string) $this->t('When you contact us, or a guest needs you, it will show up here.'),
        'count' => $openCount,
        'rows' => $openRows,
      ],
      'policies' => [
        'title' => (string) $this->t('Policies'),
        'body' => (string) $this->t('Share clear trust pages with your guests.'),
        'links' => array_values(array_filter([
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
            'label' => (string) $this->t('All policies'),
            'url' => $this->safeRouteUrl('myeventlane_help_centre.policies_index') ?? '/help/policies',
          ],
        ])),
      ],
      'payments_note' => [
        'title' => (string) $this->t('Refunds and Stripe payouts'),
        'body' => (string) $this->t('Money questions belong in Payments — not a second refund desk here.'),
        'cta_label' => (string) $this->t('Open Payments'),
        'cta_url' => $this->safeRouteUrl('myeventlane_vendor.console.payments') ?? '/vendor/payments',
      ],
      'status_placeholder' => [
        'title' => (string) $this->t('System status'),
        'body' => (string) $this->t('A live status page is on the roadmap. If something seems down platform-wide, Contact Support and we’ll confirm.'),
        'future' => TRUE,
      ],
      'settings_link' => [
        'label' => (string) $this->t('Account settings'),
        'url' => $this->safeRouteUrl('myeventlane_vendor.console.settings') ?? '/vendor/settings',
      ],
      'analytics' => [
        'support_hub_opened' => TRUE,
      ],
    ];
  }

  /**
   * Counts open escalations for health reuse (optional).
   */
  public function countOpenRequests(?Vendor $vendor = NULL): int {
    $vendor = $vendor ?? $this->vendorResolver->resolveFromCurrentUser();
    if (!$vendor instanceof Vendor) {
      return 0;
    }
    try {
      $ids = $this->entityTypeManager->getStorage('escalation')
        ->getQuery()
        ->condition('vendor_id', $vendor->id())
        ->condition('status', ['resolved', 'closed'], 'NOT IN')
        ->accessCheck(FALSE)
        ->range(0, 50)
        ->execute();
      return count($ids);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Support hub could not count open requests: @message', [
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Resolves a route to a URL string, or NULL if unavailable.
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
