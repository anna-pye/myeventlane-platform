<?php

namespace Drupal\myeventlane_rsvp\Plugin\views\access;

use Drupal\views\Plugin\views\access\AccessPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Provides vendor-level access for RSVP Views.
 *
 * @ViewsAccess(
 *   id = "myeventlane_rsvp_vendor_access",
 *   title = @Translation("Vendor RSVP Access")
 * )
 */
class VendorAccess extends AccessPluginBase {

  /**
   * Core access check.
   */
  public function access(AccountInterface $account) {
    if ($account->hasPermission('administer nodes')) {
      return TRUE;
    }
    if ($account->isAuthenticated() &&
        ($account->hasPermission('create event content') ||
         $account->hasPermission('edit own event content'))) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Optional summary text in UI.
   */
  public function summaryTitle() {
    return $this->t('Vendor RSVP Access');
  }

  /**
   * Options form (none needed).
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    // No settings.
  }

  /**
   * Required by AccessPluginBase.
   */
  public function validate() {}

  /**
   * REQUIRED signature to satisfy AccessPluginBase.
   */
  public function alterRouteDefinition(Route $route) {
    // No alteration needed.
  }

}
