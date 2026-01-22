<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_donations\Service\PlatformDonationService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for platform donation flow.
 */
final class DonationPlatformController extends ControllerBase {

  /**
   * Constructs a DonationPlatformController.
   *
   * @param \Drupal\myeventlane_donations\Service\PlatformDonationService $donationService
   *   The platform donation service.
   */
  public function __construct(
    private readonly PlatformDonationService $donationService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_donations.platform'),
    );
  }

  /**
   * Renders the donation form.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   A render array or redirect.
   */
  public function donate(): array|RedirectResponse {
    $form = $this->formBuilder()->getForm('Drupal\myeventlane_donations\Form\DonationPlatformForm');

    return [
      '#theme' => 'myeventlane_donation_platform',
      '#form' => $form,
      '#attached' => [
        'library' => ['myeventlane_donations/donation-platform'],
      ],
    ];
  }

  /**
   * Handles successful donation payment.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   A render array or redirect.
   */
  public function success(Request $request): array {
    $orderId = $request->query->get('order');
    if ($orderId) {
      $this->messenger()->addStatus($this->t('Thank you for your donation to MyEventLane! Your support helps us continue building tools for event creators.'));
    }

    return [
      '#markup' => '<div class="mel-donation-success"><h1>' . $this->t('Thank you for your donation!') . '</h1><p>' . $this->t('Your donation has been processed successfully.') . '</p></div>',
    ];
  }

  /**
   * Handles cancelled donation payment.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return array
   *   A render array.
   */
  public function cancel(Request $request): array {
    $this->messenger()->addWarning($this->t('Your donation was cancelled. You can try again anytime.'));

    return [
      '#markup' => '<div class="mel-donation-cancel"><h1>' . $this->t('Donation cancelled') . '</h1><p>' . $this->t('Your donation was not processed.') . '</p></div>',
    ];
  }

}
