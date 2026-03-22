<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_pro\Form\ProDiagnosticsForm;
use Drupal\myeventlane_pro\Service\ProEntitlementReconciler;
use Drupal\myeventlane_pro\Service\ProSubscriptionStateResolver;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin diagnostics for Pro entitlement sync.
 */
final class ProDiagnosticsController extends ControllerBase {

  private const BILLING_SCHEDULE = 'mel_pro_monthly';
  private const MANAGED_FIELD = 'field_pro_subscription_managed';
  private const GRACE_FIELD = 'field_pro_grace_expires';

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
    private readonly ProEntitlementReconciler $reconciler,
    private readonly ProSubscriptionStateResolver $stateResolver,
    private readonly FormBuilderInterface $formBuilderService,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_pro.entitlement_reconciler'),
      $container->get('myeventlane_pro.subscription_state_resolver'),
      $container->get('form_builder'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Renders the Pro status diagnostics page.
   */
  public function diagnostics(): array {
    $rows = $this->buildDiagnosticsRows();
    $form = $this->formBuilderService->getForm(ProDiagnosticsForm::class);

    return [
      '#theme' => 'pro_diagnostics',
      '#rows' => $rows,
      '#actions_form' => $form,
      '#attached' => [
        'library' => ['myeventlane_pro/admin_report'],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Reconcile a single user. Redirects back to diagnostics.
   */
  public function reconcileUser(Request $request, int $uid): \Symfony\Component\HttpFoundation\RedirectResponse {
    $user = $this->etm->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      $this->messenger()->addError($this->t('User not found.'));
      return $this->redirect('myeventlane_pro.diagnostics');
    }

    $this->reconciler->reconcileUser($user);
    $this->messenger()->addStatus($this->t('Reconciled user @uid.', ['@uid' => $uid]));

    return $this->redirect('myeventlane_pro.diagnostics');
  }

  /**
   * Builds diagnostics rows for Pro users and vendors.
   *
   * @return array<int, array<string, mixed>>
   */
  private function buildDiagnosticsRows(): array {
    $userStorage = $this->etm->getStorage('user');
    $vendorStorage = $this->etm->getStorage('myeventlane_vendor');
    $subscriptionStorage = $this->etm->getStorage('commerce_subscription');

    $uids = $userStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('roles', 'mel_pro')
      ->execute();

    $rows = [];
    foreach ($uids as $uid) {
      $user = $userStorage->load((int) $uid);
      if (!$user instanceof UserInterface) {
        continue;
      }

      $hasRole = $user->hasRole('mel_pro');
      $isManaged = $user->hasField(self::MANAGED_FIELD)
        && (bool) ($user->get(self::MANAGED_FIELD)->value ?? FALSE);
      $graceExpires = $user->hasField(self::GRACE_FIELD)
        ? (int) ($user->get(self::GRACE_FIELD)->value ?? 0)
        : 0;

      $subscriptionIds = $subscriptionStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', (int) $uid)
        ->condition('billing_schedule', self::BILLING_SCHEDULE)
        ->execute();

      $hasActiveSubscription = FALSE;
      if (!empty($subscriptionIds)) {
        foreach ($subscriptionStorage->loadMultiple($subscriptionIds) as $sub) {
          if ($this->stateResolver->isActive($sub)) {
            $hasActiveSubscription = TRUE;
            break;
          }
        }
      }

      $vendorIds = $vendorStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', (int) $uid)
        ->execute();

      $vendorIsPro = FALSE;
      foreach ($vendorStorage->loadMultiple($vendorIds) as $vendor) {
        if ($vendor instanceof Vendor && $vendor->hasField('is_pro') && !$vendor->get('is_pro')->isEmpty()) {
          $vendorIsPro = (bool) $vendor->get('is_pro')->value;
          break;
        }
      }

      $expectedPro = $hasActiveSubscription || ($graceExpires > 0 && $graceExpires >= $this->time->getRequestTime()) || (!$isManaged && $hasRole);
      $roleOk = $expectedPro ? $hasRole : !$hasRole;
      $vendorOk = $expectedPro ? $vendorIsPro : !$vendorIsPro;
      $aligned = $roleOk && ($vendorOk || empty($vendorIds));

      $rows[] = [
        'uid' => (int) $uid,
        'email' => $user->getEmail(),
        'role_has_mel_pro' => $hasRole,
        'field_pro_subscription_managed' => $isManaged,
        'has_active_subscription' => $hasActiveSubscription,
        'grace_expires' => $graceExpires > 0 ? $graceExpires : NULL,
        'vendor_is_pro' => $vendorIsPro,
        'aligned' => $aligned,
      ];
    }

    return $rows;
  }

}
