<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_messaging\Form\VendorBrandConfigForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Vendor console: messaging brand config page.
 *
 * Route: /vendor/dashboard/messaging/brand.
 * Access: VendorConsoleAccess::access + getCurrentVendorOrNull().
 */
final class VendorDashboardMessagingBrandController extends VendorConsoleBaseController implements ContainerInjectionInterface {

  /**
   * The entity type manager (used by parent getCurrentVendorOrNull).
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs the controller.
   *
   * @param \Drupal\myeventlane_core\Service\DomainDetector $domain_detector
   *   The domain detector.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\Core\Form\FormBuilderInterface $formBuilder
   *   The form builder.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager (for getCurrentVendorOrNull).
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly FormBuilderInterface $formBuilder,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('form_builder'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Displays the messaging brand config form.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   Render array or redirect when vendor is missing.
   */
  public function brand(): array|RedirectResponse {
    $this->assertVendorAccess();

    $vendor = $this->getCurrentVendorOrNull();
    if (!$vendor) {
      $this->getMessenger()->addWarning($this->t('Complete your vendor profile to manage messaging brand.'));
      $url = Url::fromRoute('myeventlane_vendor.console.settings');
      return new RedirectResponse($url->toString());
    }

    $form = $this->formBuilder->getForm(VendorBrandConfigForm::class, $vendor);

    // Build settings tabs for navigation (same as VendorSettingsController).
    $tabs = [
      [
        'label' => $this->t('Profile'),
        'url' => Url::fromRoute('myeventlane_vendor.console.settings')->toString(),
        'active' => FALSE,
      ],
      [
        'label' => $this->t('Messaging Brand'),
        'url' => Url::fromRoute('myeventlane_vendor.console.messaging_brand')->toString(),
        'active' => TRUE,
      ],
    ];

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => $this->t('Messaging brand'),
      'tabs' => $tabs,
      'body' => $form,
    ]);
  }

}
