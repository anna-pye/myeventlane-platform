<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_donations\Service\VendorContributionInvoiceService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin: MEL vendor % billing overview and manual invoice generation.
 */
final class VendorMelBillingAdminController extends ControllerBase {

  public function __construct(
    private readonly VendorContributionInvoiceService $invoiceService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_donations.vendor_contribution_invoice'),
    );
  }

  /**
   * KPIs + generate form.
   */
  public function overview(): array {
    $overview = $this->invoiceService->getAdminBillingOverview();

    return [
      '#theme' => 'myeventlane_mel_billing_admin',
      '#overview' => $overview,
      '#generate_form' => $this->formBuilder()->getForm('Drupal\myeventlane_donations\Form\GenerateMelVendorInvoicesForm'),
      '#donation_report_url' => Url::fromRoute('myeventlane_donations.admin_report')->toString(),
      '#attached' => [
        'library' => ['myeventlane_donations/mel-vendor-billing'],
      ],
    ];
  }

}
