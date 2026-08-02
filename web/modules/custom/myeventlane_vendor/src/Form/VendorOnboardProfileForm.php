<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Entity\OnboardingStateInterface;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_legal\Service\LegalSettingsService;
use Drupal\myeventlane_legal\Service\LegalGatekeeper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Dedicated onboarding Step 2 form: organiser profile (name only).
 *
 * Organiser name and legal acceptance are stored in onboarding state `flags`
 * (aligned with myeventlane_legal VendorTermsForm). The gateway skips
 * LegalGatekeeper when those flags record terms. Store creation (if any) is
 * unchanged.
 */
final class VendorOnboardProfileForm extends FormBase {

  /**
   * The onboarding manager.
   */
  private readonly OnboardingManager $onboardingManager;

  /**
   * The current user.
   */
  private readonly AccountProxyInterface $currentUser;

  /**
   * The request time.
   */
  private readonly TimeInterface $time;

  /**
   * Legal policy versions and URLs.
   */
  private readonly LegalSettingsService $legalSettings;

  /**
   * Constructs the form.
   */
  public function __construct(
    OnboardingManager $onboarding_manager,
    AccountProxyInterface $current_user,
    TimeInterface $time,
    LegalSettingsService $legal_settings,
    private readonly LegalGatekeeper $legalGatekeeper,
  ) {
    $this->onboardingManager = $onboarding_manager;
    $this->currentUser = $current_user;
    $this->time = $time;
    $this->legalSettings = $legal_settings;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_onboarding.manager'),
      $container->get('current_user'),
      $container->get('datetime.time'),
      $container->get('myeventlane_legal.settings'),
      $container->get('myeventlane_legal.gatekeeper'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'organiser_onboard_profile_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    if ($this->currentUser->isAnonymous()) {
      return $form;
    }

    $uid = (int) $this->currentUser->id();
    $state = $this->onboardingManager->createVendorStateForUid($uid);
    $flags = $state->getFlags();
    $flags = is_array($flags) ? $flags : [];
    $name_default = isset($flags['organiser_name']) ? (string) $flags['organiser_name'] : '';
    if ($name_default === '') {
      $name_default = (string) $this->currentUser->getAccount()->getDisplayName();
    }
    // #tree only on step_content so form_id / form_build_id / form_token stay at root
    // and Form API submit processing is reliable (root #tree breaks POST rebuild).
    // Step metadata for form--organiser-onboard-profile-form.html.twig preprocess.
    $form['#step_number'] = 2;
    $form['#total_steps'] = 3;
    $form['#step_title'] = $this->t('Let’s get your organiser set up 👋');
    $form['#step_description'] = $this->t('This helps people trust your events.');
    $form['#attributes']['class'][] = 'mel-onboard-form';
    $form['#attributes']['class'][] = 'mel-onboard-form-root';
    $form['#attributes']['class'][] = 'mel-onboard-profile-form';
    $form['#attributes']['novalidate'] = 'novalidate';

    $form['step_content'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => [
        'class' => ['mel-onboard-step-fields'],
      ],
    ];
    $form['step_content']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organiser name'),
      '#description' => $this->t('The public name for your organisation (e.g., "Sydney Music Festival").'),
      '#description_display' => 'after',
      '#required' => TRUE,
      '#default_value' => $name_default,
      '#maxlength' => 255,
    ];
    $form['step_content']['name']['#attributes']['placeholder'] = $this->t('Enter your organiser name');
    $form['step_content']['name']['#attributes']['autofocus'] = 'autofocus';

    $form['step_content']['actions'] = [
      '#type' => 'actions',
      '#attributes' => [
        'class' => ['mel-onboard-footer', 'mel-onboard-footer--split'],
      ],
    ];
    $form['step_content']['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back'),
      '#url' => Url::fromRoute('myeventlane_vendor.onboard.account'),
      '#weight' => -10,
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn--secondary', 'mel-btn-secondary', 'mel-onboard-footer__back'],
      ],
    ];

    $accepted_at = $this->legalGatekeeper->getVendorTermsAcceptedAt();
    $vendor_terms_url = $this->legalSettings->getVendorTermsUrl();
    $form['terms_accepted'] = [
      '#type' => 'checkbox',
      '#title' => $vendor_terms_url !== ''
        ? $this->t('I agree to the <a href=":url" target="_blank" rel="noopener">Vendor Terms of Service</a>', [':url' => $vendor_terms_url])
        : $this->t('I agree to the Vendor Terms of Service'),
      '#default_value' => $accepted_at !== NULL
        || !empty($flags['terms_accepted'])
        || (!empty($flags['vendor_terms_accepted_at']) && (int) $flags['vendor_terms_accepted_at'] > 0),
      '#required' => TRUE,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Continue',
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $name = trim((string) ($form_state->getValue(['step_content', 'name']) ?? ''));
    if ($name === '') {
      $form_state->setError($form['step_content']['name'], $this->t('Organiser name is required.'));
    }
    if (!(bool) $form_state->getValue('terms_accepted')) {
      $form_state->setError($form['terms_accepted'], $this->t('You must agree to the Vendor Terms of Service.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $name = trim((string) ($form_state->getValue(['step_content', 'name']) ?? ''));
    if ($name === '') {
      return;
    }

    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return;
    }

    $state = $this->onboardingManager->createVendorStateForUid($uid);
    $values = $form_state->getValues();

    $flags = $state->getFlags();
    $flags = is_array($flags) ? $flags : [];

    $flags['organiser_name'] = $name;

    // Save checkbox value from the posted form (root-level key, not under #tree).
    $flags['terms_accepted'] = !empty($values['terms_accepted']);

    if ($flags['terms_accepted']) {
      // The vendor entity is the legal source of truth. Preserve an existing
      // acceptance timestamp instead of recording a misleading re-acceptance
      // when a returning organiser submits this form.
      $flags['vendor_terms_accepted_at'] = $this->legalGatekeeper->getVendorTermsAcceptedAt()
        ?? (int) $this->time->getRequestTime();
      $flags['vendor_terms_version'] = $this->legalSettings->getVendorTermsVersion();
      if ($this->legalSettings->storeVendorIpUa()) {
        $request = $this->getRequest();
        if ($request !== NULL) {
          $flags['vendor_terms_accepted_ip'] = $request->getClientIp();
          $flags['vendor_terms_accepted_ua'] = (string) $request->headers->get('User-Agent', '');
        }
      }
    }

    $state->setFlags($flags);
    $this->onboardingManager->persistOnboardingState($state);

    $this->getLogger('myeventlane_vendor')->notice(
      'MEL: terms checkbox saved uid=@uid value=@value',
      [
        '@uid' => (string) $this->currentUser->id(),
        '@value' => $flags['terms_accepted'] ? '1' : '0',
      ],
    );

    $account = $this->currentUser;
    $vendor = $this->onboardingManager->ensureVendorExists($account);
    $this->getLogger('myeventlane_vendor')->notice('Vendor created during onboarding uid=@uid vendor_id=@vid', [
      '@uid' => (string) $uid,
      '@vid' => (string) $vendor->id(),
    ]);
    $vendor->setName($name);
    if (!empty($flags['vendor_terms_accepted_at'])) {
      $this->onboardingManager->applyVendorLegalFieldsFromStateFlags($vendor, $flags);
      $this->getLogger('myeventlane_vendor')->notice(
        'MEL: vendor terms synced to vendor entity uid=@uid vendor=@vid',
        [
          '@uid' => (string) $this->currentUser->id(),
          '@vid' => (string) $vendor->id(),
        ],
      );
    }
    $vendor->save();
    $this->onboardingManager->ensureVendorAccess($account);
    if ((int) ($state->getVendorId() ?? 0) !== (int) $vendor->id()) {
      $state->setVendorId((int) $vendor->id());
      $this->onboardingManager->persistOnboardingState($state);
    }

    // Mark onboarding complete (profile + terms) once vendor is linked. Must run
    // after setVendorId: myeventlane_onboarding_state preSave requires vendor_id
    // when stage/complete is final (see OnboardingState::preSave()).
    if (!empty($flags['terms_accepted']) && $name !== '' && (int) ($state->getVendorId() ?? 0) > 0) {
      $state->setStage('complete');
      $state->setCompleted(TRUE);
      $this->onboardingManager->persistOnboardingState($state);
      $this->getLogger('myeventlane_vendor')->notice(
        'MEL: onboarding marked complete from profile uid=@uid',
        ['@uid' => (string) $this->currentUser->id()],
      );
    }
    else {
      $order = OnboardingStateInterface::STAGE_ORDER;
      $current_idx = array_search($state->getStage(), $order, TRUE);
      $ask_idx = array_search('ask', $order, TRUE);
      if ($current_idx !== FALSE && $ask_idx !== FALSE && $current_idx < $ask_idx) {
        $this->onboardingManager->advanceStage($state, 'ask');
      }
    }

    $state = $this->onboardingManager->loadVendorStateByUid($uid);
    if ($state === NULL) {
      $form_state->setRedirect('myeventlane_vendor.onboard.profile');
      return;
    }

    $this->messenger()->addStatus($this->t('Saved. Continue to the next organiser step.'));

    $request = $this->getRequest();
    if ($request !== NULL) {
      $dest_raw = $request->query->get('destination');
      if (is_string($dest_raw) && trim($dest_raw) !== '') {
        $destination = trim($dest_raw);
        try {
          $form_state->setRedirectUrl(Url::fromUserInput($destination));
        }
        catch (\InvalidArgumentException) {
          $form_state->setRedirect('myeventlane_event_studio.create', [], [
            'query' => ['mel_first_event' => '1'],
          ]);
        }
        $this->getLogger('myeventlane_vendor')->notice(
          'VendorOnboardProfileForm: post-submit redirect destination uid=@uid',
          ['@uid' => (string) $uid],
        );
        return;
      }
    }

    $form_state->setRedirect('myeventlane_event_studio.create', [], [
      'query' => ['mel_first_event' => '1'],
    ]);
    $this->getLogger('myeventlane_vendor')->notice(
      'VendorOnboardProfileForm: post-submit redirect to create-event gateway uid=@uid',
      ['@uid' => (string) $uid],
    );
  }

}
