<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_refunds\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Legacy Support · Refunds entry — converges into Payments.
 *
 * /vendor/support/refunds redirects to the Payments hub Refunds section.
 */
final class VendorRefundSummaryController extends ControllerBase {

  /**
   * Redirects organisers to Payments · Refunds (VX2-10 / D-H06).
   */
  public function summary(): RedirectResponse {
    $this->getLogger('myeventlane_escalations_refunds')->info(
      'support_refunds_redirect uid=@uid',
      ['@uid' => (string) $this->currentUser()->id()],
    );

    try {
      $url = Url::fromRoute('myeventlane_vendor.console.payments', [], [
        'fragment' => 'refunds',
      ])->toString();
    }
    catch (\Throwable) {
      $url = '/vendor/payments#refunds';
    }

    return new RedirectResponse($url, 302);
  }

}
