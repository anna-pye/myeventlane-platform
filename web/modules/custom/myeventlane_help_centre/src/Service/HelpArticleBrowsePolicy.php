<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Core\Session\AccountInterface;

/**
 * Audience and status rules for Help Centre browse/search listings (not Assistant).
 *
 * Aligns with HelpRetriever audience splits where applicable, but applies stricter
 * vendor gating for authenticated users without vendor console permissions.
 */
final class HelpArticleBrowsePolicy {

  /**
   * Views that list help_article metadata (title, summary, teaser) on public hubs.
   */
  public const BROWSE_LISTING_VIEWS = [
    'mel_help_search',
    'mel_help_attendee_help',
    'mel_help_organiser_help',
    'mel_help_vendor_help',
    'mel_help_policies_help',
    'mel_help_featured_articles',
    'mel_help_faq',
    'mel_help_related_articles',
  ];

  /**
   * Whether a view display should apply browse listing policy.
   */
  public function isBrowseListingView(string $viewId): bool {
    return in_array($viewId, self::BROWSE_LISTING_VIEWS, TRUE);
  }

  /**
   * Hub-specific audience cap (intersected with account policy).
   *
   * @return list<string>|null
   *   Fixed scope, or NULL when only account policy applies.
   */
  public function hubAudienceScope(string $viewId): ?array {
    return match ($viewId) {
      'mel_help_attendee_help',
      'mel_help_organiser_help',
      'mel_help_policies_help' => ['public'],
      'mel_help_vendor_help' => ['vendor'],
      default => NULL,
    };
  }

  /**
   * Effective audience filter values for a browse listing view and account.
   *
   * @return list<string>
   */
  public function effectiveAudiencesForBrowseView(
    string $viewId,
    AccountInterface $account,
    ?string $requestedAudience = NULL,
  ): array {
    $policyAudiences = $viewId === 'mel_help_search'
      ? $this->effectiveAudienceFilter($account, $requestedAudience)
      : $this->allowedAudienceValues($account);
    $scope = $this->hubAudienceScope($viewId);
    if ($scope === NULL) {
      return $policyAudiences;
    }
    return array_values(array_intersect($policyAudiences, $scope));
  }

  /**
   * Canonical field_audience values allowed in browse/search for this account.
   *
   * @return list<string>
   */
  public function allowedAudienceValues(AccountInterface $account): array {
    if ($account->hasPermission('administer escalations')) {
      return ['public', 'vendor', 'staff'];
    }
    if ($account->hasPermission('access vendor console')
      || $account->hasPermission('view vendor help centre')) {
      return ['public', 'vendor'];
    }
    return ['public'];
  }

  /**
   * Help status values that may appear in public browse/search listings.
   *
   * @return list<string>
   */
  public function allowedHelpStatusesForBrowse(AccountInterface $account): array {
    if ($account->hasPermission('bypass node access')
      || $account->hasPermission('administer help articles')) {
      return ['published', 'approved', 'review', 'draft'];
    }
    return ['published', 'approved'];
  }

  /**
   * Resolves effective audience filter values for a Help search request.
   *
   * Intersects optional exposed ?audience= with account allowances so URL params
   * cannot widen access (e.g. anonymous ?audience=vendor).
   *
   * @return list<string>
   */
  public function effectiveAudienceFilter(AccountInterface $account, ?string $requestedAudience): array {
    $allowed = $this->allowedAudienceValues($account);
    if ($requestedAudience !== NULL && $requestedAudience !== '') {
      if (in_array($requestedAudience, ['public', 'vendor', 'staff'], TRUE)
        && in_array($requestedAudience, $allowed, TRUE)) {
        return [$requestedAudience];
      }
      return $allowed;
    }
    return $allowed;
  }

}
