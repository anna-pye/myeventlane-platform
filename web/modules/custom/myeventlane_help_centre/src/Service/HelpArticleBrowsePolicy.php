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
