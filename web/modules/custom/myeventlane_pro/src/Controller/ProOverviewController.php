<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\myeventlane_pro\Form\ProSubscribeForm;
use Drupal\myeventlane_pro\Service\ProActiveResolver;
use Drupal\myeventlane_pro\Service\ProProductResolver;
use Drupal\myeventlane_pro\Service\ProSubscriptionStatusService;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for vendor-facing Pro subscription pages.
 */
final class ProOverviewController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ProProductResolver $productResolver,
    private readonly FormBuilderInterface $formBuilder,
    private readonly ProActiveResolver $proActiveResolver,
    private readonly ProSubscriptionStatusService $statusService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('myeventlane_pro.product_resolver'),
      $container->get('form_builder'),
      $container->get('myeventlane_pro.active_resolver'),
      $container->get('myeventlane_pro.subscription_status'),
    );
  }

  /**
   * Renders the /vendor/pro overview page.
   */
  public function overview(): array {
    $user = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
    $status = $user instanceof UserInterface
      ? $this->statusService->getStatusForUser($user)
      : $this->statusService->getStatusForCurrentUser();

    $isPro = $status['is_pro'] ?? FALSE;
    $proPrice = $this->configFactory->get('myeventlane_pro.settings')->get('pro_price') ?? 49;
    $formattedPrice = $this->productResolver->getFormattedPrice() ?? ('$' . $proPrice);

    $manageUrl = NULL;
    $cancelUrl = NULL;
    $supportUrl = trim((string) ($this->configFactory->get('myeventlane_pro.settings')->get('billing_support_url') ?? ''));
    $subscribeForm = [];

    if ($isPro) {
      $manageUrl = Url::fromRoute('myeventlane_pro.manage')->toString();
      if ($status['can_cancel'] ?? FALSE) {
        $cancelUrl = Url::fromRoute('myeventlane_pro.cancel_request')->toString();
      }
    }
    else {
      $subscribeForm = $this->formBuilder->getForm(ProSubscribeForm::class, $formattedPrice);
    }

    $statusCard = [
      '#theme' => 'mel_pro_status_card',
      '#status' => $status,
      '#manage_url' => $manageUrl,
      '#cancel_url' => $cancelUrl,
      '#support_url' => $supportUrl !== '' ? $supportUrl : NULL,
      '#upgrade_url' => !$isPro ? Url::fromRoute('myeventlane_pro.overview')->toString() : NULL,
    ];

    return [
      '#theme' => 'vendor_pro_overview',
      '#is_pro' => $isPro,
      '#pro_price' => $formattedPrice,
      '#subscribe_form' => $subscribeForm,
      '#manage_url' => $manageUrl,
      '#pro_status' => $status,
      '#status_card' => $statusCard,
      '#attached' => [
        'library' => [
          'myeventlane_pro/pro',
        ],
      ],
      '#cache' => [
        'contexts' => ['user.roles'],
        'tags' => $this->getProCacheTags(),
      ],
    ];
  }

  /**
   * Renders the /vendor/pro/success page.
   */
  public function success(): RedirectResponse|array {
    $user = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
    if (!$user instanceof UserInterface || !$this->proActiveResolver->isUserProActive($user)) {
      return new RedirectResponse(Url::fromRoute('myeventlane_pro.overview')->toString());
    }

    return [
      '#theme' => 'vendor_pro_success',
      '#features' => [
        $this->t('Remove MyEventLane branding from your events'),
        $this->t('Custom email footers and branded ticket PDFs'),
        $this->t('Advanced analytics and conversion insights'),
        $this->t('Abandoned cart recovery'),
        $this->t('Event cloning for faster setup'),
        $this->t('Automated refund processing'),
        $this->t('Bulk attendee exports'),
        $this->t('Priority support'),
      ],
      '#actions' => [
        'dashboard' => ['url' => Url::fromRoute('myeventlane_vendor.console.dashboard'), 'title' => $this->t('Go to Dashboard')],
        'manage' => ['url' => Url::fromRoute('myeventlane_pro.manage'), 'title' => $this->t('Manage Subscription')],
        'analytics' => ['url' => Url::fromRoute('myeventlane_analytics.dashboard'), 'title' => $this->t('View Analytics')],
        'branding' => ['url' => Url::fromRoute('myeventlane_pro.branding'), 'title' => $this->t('Branding settings')],
      ],
      '#attached' => [
        'library' => ['myeventlane_pro/pro'],
      ],
      '#cache' => [
        'contexts' => ['user.roles'],
        'tags' => $this->getProCacheTags(),
      ],
    ];
  }

  /**
   * Custom access check: requires both vendor and mel_pro roles.
   *
   * Used by manage, cancel, and billing portal routes.
   */
  public function accessProVendor(AccountInterface $account): AccessResultInterface {
    $hasVendor = in_array('vendor', $account->getRoles(), TRUE);
    $user = $this->entityTypeManager->getStorage('user')->load((int) $account->id());
    $hasPro = $user instanceof UserInterface ? $this->proActiveResolver->isUserProActive($user) : FALSE;

    return AccessResult::allowedIf($hasVendor && $hasPro)
      ->addCacheContexts(['user.roles']);
  }

  /**
   * Builds cache tags for Pro pages.
   *
   * @return string[]
   *   Cache tag list.
   */
  private function getProCacheTags(): array {
    $tags = ['user:' . $this->currentUser->id()];
    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return $tags;
    }

    $vendorIds = $this->entityTypeManager->getStorage('myeventlane_vendor')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute();
    if ($vendorIds === []) {
      return $tags;
    }

    $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load((int) reset($vendorIds));
    if ($vendor instanceof Vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $store = $vendor->get('field_vendor_store')->entity;
      if ($store && $store->id() !== NULL) {
        $storeId = (int) $store->id();
        $tags[] = 'pro_subscription:' . $storeId;
        $tags[] = 'commerce_store:' . $storeId;
      }
    }

    return $tags;
  }

}
