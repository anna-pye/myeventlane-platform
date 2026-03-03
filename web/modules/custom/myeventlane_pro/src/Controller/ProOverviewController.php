<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Controller;

use Drupal\commerce_recurring\Entity\SubscriptionInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\myeventlane_pro\Form\ProSubscribeForm;
use Drupal\myeventlane_pro\Service\ProActiveResolver;
use Drupal\myeventlane_pro\Service\ProProductResolver;
use Drupal\myeventlane_pro\Service\ProRecoveryAnalyticsService;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for vendor-facing Pro subscription pages.
 */
final class ProOverviewController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  private const BILLING_SCHEDULE = 'mel_pro_monthly';

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ProProductResolver $productResolver,
    private readonly FormBuilderInterface $formBuilder,
    private readonly MessengerInterface $messenger,
    private readonly LoggerChannelInterface $logger,
    private readonly ProRecoveryAnalyticsService $recoveryAnalyticsService,
    private readonly ProActiveResolver $proActiveResolver,
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
      $container->get('messenger'),
      $container->get('logger.channel.myeventlane_pro'),
      $container->get('myeventlane_pro.recovery_analytics'),
      $container->get('myeventlane_pro.active_resolver'),
    );
  }

  /**
   * Renders the /vendor/pro overview page.
   */
  public function overview(): array {
    $user = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
    $isPro = $user instanceof UserInterface ? $this->proActiveResolver->isUserProActive($user) : FALSE;
    $proPrice = $this->configFactory->get('myeventlane_pro.settings')->get('pro_price') ?? 49;
    $formattedPrice = $this->productResolver->getFormattedPrice() ?? ('$' . $proPrice);

    $manageUrl = NULL;
    $subscribeForm = [];

    if ($isPro) {
      $manageUrl = Url::fromRoute('myeventlane_pro.manage')->toString();
    }
    else {
      $subscribeForm = $this->formBuilder->getForm(ProSubscribeForm::class, $formattedPrice);
    }

    return [
      '#theme' => 'vendor_pro_overview',
      '#is_pro' => $isPro,
      '#pro_price' => $formattedPrice,
      '#subscribe_form' => $subscribeForm,
      '#manage_url' => $manageUrl,
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
        'Remove MyEventLane branding from your events',
        'Custom email footers and branded ticket PDFs',
        'Advanced analytics and conversion insights',
        'Abandoned cart recovery',
        'Event cloning for faster setup',
        'Automated refund processing',
        'Bulk attendee exports',
        'Priority support',
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
   * Renders the /vendor/pro/manage page.
   */
  public function manage(): RedirectResponse|array {
    $overviewUrl = Url::fromRoute('myeventlane_pro.overview')->toString();

    $subscription = $this->loadLatestSubscription();
    if (!$subscription) {
      $this->messenger->addWarning($this->t('No Pro subscription found.'));
      return new RedirectResponse($overviewUrl);
    }

    $state = $subscription->getState();
    $statusLabel = ucfirst($state->getLabel() ?? $state->getId());

    $nextBilling = NULL;
    $renewalInDays = NULL;
    if (method_exists($subscription, 'getNextRenewalTime')) {
      $timestamp = $subscription->getNextRenewalTime();
      if ($timestamp) {
        $renewalTimestamp = (int) $timestamp;
        $nextBilling = date('F j, Y', $renewalTimestamp);
        $delta = $renewalTimestamp - time();
        if ($delta > 0) {
          $renewalInDays = (int) ceil($delta / 86400);
        }
      }
    }

    $startedDate = NULL;
    if (method_exists($subscription, 'getStartDate')) {
      $start = $subscription->getStartDate();
      if ($start) {
        $startedDate = $start->format('F j, Y');
      }
    }

    $cancelUrl = Url::fromRoute('myeventlane_pro.cancel')->toString();
    $roiSummary = $this->buildRoiSummary();
    $graceDaysRemaining = $this->resolveGraceDaysRemaining();

    return [
      '#theme' => 'vendor_pro_manage',
      '#subscription_status' => $statusLabel,
      '#started_date' => $startedDate,
      '#next_billing_date' => $nextBilling,
      '#renewal_in_days' => $renewalInDays,
      '#grace_days_remaining' => $graceDaysRemaining,
      '#cancel_url' => $cancelUrl,
      '#roi_summary' => $roiSummary,
      '#attached' => [
        'library' => ['myeventlane_pro/pro'],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => $this->getProCacheTags(),
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Custom access check: requires both vendor and pro_organiser roles.
   *
   * Used by manage and cancel routes.
   */
  public function accessProVendor(AccountInterface $account): AccessResultInterface {
    $hasVendor = in_array('vendor', $account->getRoles(), TRUE);
    $user = $this->entityTypeManager->getStorage('user')->load((int) $account->id());
    $hasPro = $user instanceof UserInterface ? $this->proActiveResolver->isUserProActive($user) : FALSE;

    return AccessResult::allowedIf($hasVendor && $hasPro)
      ->addCacheContexts(['user.roles']);
  }

  /**
   * Loads the current user's active Pro subscription.
   */
  private function loadLatestSubscription(): ?SubscriptionInterface {
    $ids = $this->entityTypeManager->getStorage('commerce_subscription')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $this->currentUser->id())
      ->condition('billing_schedule', self::BILLING_SCHEDULE)
      ->sort('subscription_id', 'DESC')
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    $subscription = $this->entityTypeManager
      ->getStorage('commerce_subscription')
      ->load(reset($ids));

    return $subscription instanceof SubscriptionInterface ? $subscription : NULL;
  }

  /**
   * Resolves days remaining in grace period for current user.
   */
  private function resolveGraceDaysRemaining(): ?int {
    $user = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
    if (!$user instanceof UserInterface || !$user->hasField('field_pro_grace_expires')) {
      return NULL;
    }

    $expiry = (int) ($user->get('field_pro_grace_expires')->value ?? 0);
    if ($expiry <= 0) {
      return NULL;
    }

    $remaining = $expiry - time();
    if ($remaining <= 0) {
      return 0;
    }

    return (int) ceil($remaining / 86400);
  }

  /**
   * Builds the ROI summary for the current Pro vendor.
   *
   * @return array{pro_cost: float, recovered_revenue: float, roi_multiple: float}|null
   *   ROI summary payload, or NULL when no vendor store is resolvable.
   */
  private function buildRoiSummary(): ?array {
    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return NULL;
    }

    $vendorStorage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $vendorIds = $vendorStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute();

    if ($vendorIds === []) {
      return NULL;
    }

    $vendor = $vendorStorage->load((int) reset($vendorIds));
    if (!$vendor instanceof Vendor || !$vendor->hasField('field_vendor_store') || $vendor->get('field_vendor_store')->isEmpty()) {
      return NULL;
    }

    $store = $vendor->get('field_vendor_store')->entity;
    if (!$store || $store->id() === NULL) {
      return NULL;
    }

    return $this->recoveryAnalyticsService->estimateProROI((int) $store->id(), 1);
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
