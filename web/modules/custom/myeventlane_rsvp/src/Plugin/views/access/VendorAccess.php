<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\Plugin\views\access;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\views\Plugin\views\access\AccessPluginBase;
use Symfony\Component\Routing\Route;

/**
 * Provides vendor-level page access for RSVP Views.
 *
 * Page gate only: authenticated organisers (or staff). Row isolation is enforced
 * by {@see \Drupal\myeventlane_rsvp\Service\RsvpOrganiserViewScope} via the
 * organiser-owned filter and hook_views_query_alter — not by this plugin.
 *
 * @ViewsAccess(
 *   id = "myeventlane_rsvp_vendor_access",
 *   title = @Translation("Vendor RSVP Access")
 * )
 */
class VendorAccess extends AccessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account) {
    if ($account->hasPermission('administer nodes')
      || $account->hasPermission('administer rsvps')) {
      return TRUE;
    }

    if (!$account->isAuthenticated()) {
      return FALSE;
    }

    // Coarse organiser capability check. Cross-tenant PII is prevented by query
    // scoping (managed event IDs), not by this page-level gate.
    return $account->hasPermission('manage own event rsvps')
      || $account->hasPermission('create event content')
      || $account->hasPermission('edit own event content')
      || $account->hasPermission('access vendor console');
  }

  /**
   * {@inheritdoc}
   */
  public function summaryTitle() {
    return $this->t('Vendor RSVP Access (organiser-scoped rows)');
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    // No settings.
  }

  /**
   * {@inheritdoc}
   */
  public function validate() {}

  /**
   * {@inheritdoc}
   */
  public function alterRouteDefinition(Route $route) {
    // Access callback remains plugin-driven; query scope is separate.
  }

}
