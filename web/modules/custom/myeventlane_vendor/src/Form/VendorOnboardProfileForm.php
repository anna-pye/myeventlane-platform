<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\EventSubscriber\VendorStoreSubscriber;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Dedicated onboarding Step 2 form: organiser profile (name only).
 *
 * Replaces the Vendor entity edit form at /vendor/onboard/profile.
 * Uses default form rendering (no custom #theme) so form_build_id, form_token,
 * form_id and field name attributes render correctly.
 */
final class VendorOnboardProfileForm extends FormBase {

  /**
   * The onboarding manager.
   */
  private readonly OnboardingManager $onboardingManager;

  /**
   * The entity type manager.
   */
  private readonly EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current user.
   */
  private readonly AccountProxyInterface $currentUser;

  /**
   * The vendor store subscriber (ensures store exists for new vendors).
   */
  private readonly VendorStoreSubscriber $vendorStoreSubscriber;

  /**
   * Constructs the form.
   */
  public function __construct(
    OnboardingManager $onboarding_manager,
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    VendorStoreSubscriber $vendor_store_subscriber,
  ) {
    $this->onboardingManager = $onboarding_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->vendorStoreSubscriber = $vendor_store_subscriber;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_onboarding.manager'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('myeventlane_vendor.vendor_store_subscriber'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'vendor_onboard_profile_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    if ($this->currentUser->isAnonymous()) {
      return $form;
    }

    $account = $this->currentUser->getAccount();
    $vendor = $this->onboardingManager->ensureVendorExists($account);
    $form_state->set('vendor', $vendor);

    // Step metadata for form--vendor-onboard-profile-form.html.twig preprocess.
    $form['#step_number'] = 2;
    $form['#total_steps'] = 7;
    $form['#step_title'] = $this->t('Set up your organiser profile');
    $form['#step_description'] = $this->t('Tell people about your organisation. This information will appear on your public organiser page.');
    $form['#attached']['library'][] = 'myeventlane_vendor/onboarding';
    $form['#attributes']['class'][] = 'mel-onboard-form';
    $form['#attributes']['class'][] = 'mel-onboard-profile-form';

    $form['step_content'] = [
      '#type' => 'container',
    ];
    $form['step_content']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organiser name'),
      '#description' => $this->t('The public name for your organisation (e.g., "Sydney Music Festival").'),
      '#description_display' => 'after',
      '#required' => TRUE,
      '#default_value' => $vendor->getName(),
      '#maxlength' => 255,
    ];
    $form['step_content']['name']['#attributes']['placeholder'] = $this->t('Enter your organiser name');
    $form['step_content']['actions'] = [
      '#type' => 'actions',
    ];
    $form['step_content']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Continue'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $name = trim((string) ($form_state->getValue('name') ?? ''));
    if ($name === '') {
      $form_state->setErrorByName('name', $this->t('Organiser name is required.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\myeventlane_vendor\Entity\Vendor|null $vendor */
    $vendor = $form_state->get('vendor');
    if (!$vendor instanceof Vendor) {
      return;
    }

    $name = trim((string) ($form_state->getValue('name') ?? ''));
    $vendor->setName($name);

    try {
      if ($vendor->hasField('field_vendor_store') && $vendor->get('field_vendor_store')->isEmpty()) {
        $this->vendorStoreSubscriber->onVendorInsertFromHook($vendor);
      }
    }
    catch (\Throwable $e) {
      $this->getLogger('myeventlane_vendor')->error('onboarding step2: failed ensuring store for vendor @vid: @m', [
        '@vid' => $vendor->id() ?: '0',
        '@m' => $e->getMessage(),
      ]);
    }

    if ($vendor->hasField('field_vendor_users')) {
      $current_users = $vendor->get('field_vendor_users')->getValue();
      $user_ids = array_column($current_users, 'target_id');
      $uid = (int) $this->currentUser->id();
      if ($uid > 0 && !in_array($uid, $user_ids, TRUE)) {
        $vendor->get('field_vendor_users')->appendItem(['target_id' => $uid]);
      }
    }

    $vendor->save();

    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return;
    }

    $account = $this->currentUser->getAccount();

    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      return;
    }

    $state = $this->onboardingManager->loadVendorStateByUid($uid);
    if ($state === NULL) {
      $state = $this->onboardingManager->createVendorStateForUid($uid);
    }
    if ($state->getVendorId() !== (int) $vendor->id()) {
      $state->setVendorId((int) $vendor->id());
      $state->save();
    }
    $this->onboardingManager->advanceStage($state, 'listen');

    $next = $this->onboardingManager->getNextAction($state);
    $next_route = !empty($next['route_name']) ? $next['route_name'] : 'myeventlane_vendor.onboard.stripe';

    $this->getLogger('myeventlane_vendor')->info('onboard step2 submit ok uid=@uid vendor_id=@vid next_route=@route', [
      '@uid' => $uid,
      '@vid' => $vendor->id(),
      '@route' => $next_route,
    ]);

    $this->messenger()->addStatus($this->t('Saved. Next: Connect payouts.'));
    $form_state->setRedirect($next_route);
  }

}
