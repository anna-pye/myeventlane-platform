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
use Drupal\myeventlane_vendor\Service\OrganiserTaxProfileManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Dedicated onboarding Step 2 form: organiser profile and tax declaration.
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
    private readonly OrganiserTaxProfileManager $organiserTaxProfile,
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
      $container->get('myeventlane_vendor.organiser_tax_profile'),
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

    $form['step_content']['tax'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Legal and tax details'),
      '#description' => $this->t('We use this information to apply GST correctly and prepare invoices and receipts.'),
    ];
    $form['step_content']['tax']['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Organisation type'),
      '#options' => [
        '' => $this->t('- Select -'),
        'individual' => $this->t('Individual / sole trader'),
        'company' => $this->t('Company'),
        'incorporated_association' => $this->t('Incorporated association'),
        'unincorporated_association' => $this->t('Unincorporated association'),
        'trust' => $this->t('Trust'),
        'charity_or_nfp' => $this->t('Charity or not-for-profit'),
        'government' => $this->t('Government entity'),
        'other' => $this->t('Other'),
      ],
      '#default_value' => (string) ($flags['tax_entity_type'] ?? ''),
      '#required' => TRUE,
    ];
    $form['step_content']['tax']['gst_status'] = [
      '#type' => 'radios',
      '#title' => $this->t('Are you registered for GST?'),
      '#options' => [
        OrganiserTaxProfileManager::STATUS_REGISTERED => $this->t('Yes, registered for GST'),
        OrganiserTaxProfileManager::STATUS_NOT_REGISTERED => $this->t('No, not registered for GST'),
      ],
      '#default_value' => (string) ($flags['gst_registration_status'] ?? ''),
      '#description' => $this->t('Not-for-profit or charity status does not automatically exempt an organisation from GST.'),
      '#required' => TRUE,
    ];
    $form['step_content']['tax']['abn'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ABN'),
      '#default_value' => (string) ($flags['abn'] ?? ''),
      '#maxlength' => 14,
      '#description' => $this->t('Required if you are registered for GST.'),
      '#states' => [
        'required' => [':input[name="step_content[tax][gst_status]"]' => ['value' => OrganiserTaxProfileManager::STATUS_REGISTERED]],
      ],
    ];
    $form['step_content']['tax']['gst_effective_date'] = [
      '#type' => 'date',
      '#title' => $this->t('GST registration effective date'),
      '#default_value' => (string) ($flags['gst_effective_date'] ?? ''),
      '#states' => [
        'visible' => [':input[name="step_content[tax][gst_status]"]' => ['value' => OrganiserTaxProfileManager::STATUS_REGISTERED]],
        'required' => [':input[name="step_content[tax][gst_status]"]' => ['value' => OrganiserTaxProfileManager::STATUS_REGISTERED]],
      ],
    ];
    $form['step_content']['tax']['acnc_status'] = [
      '#type' => 'select',
      '#title' => $this->t('Charity status'),
      '#options' => [
        'not_applicable' => $this->t('Not applicable'),
        'not_registered' => $this->t('Not ACNC registered'),
        'registered' => $this->t('ACNC registered charity'),
      ],
      '#default_value' => (string) ($flags['acnc_status'] ?? 'not_applicable'),
    ];
    $form['step_content']['tax']['dgr_status'] = [
      '#type' => 'select',
      '#title' => $this->t('DGR endorsement'),
      '#options' => [
        'not_endorsed' => $this->t('Not DGR endorsed'),
        'endorsed' => $this->t('DGR endorsed'),
      ],
      '#default_value' => (string) ($flags['dgr_status'] ?? 'not_endorsed'),
    ];
    $form['step_content']['tax']['declaration'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I confirm these legal and tax details are current and accurate.'),
      '#default_value' => !empty($flags['tax_declaration_at']),
      '#required' => TRUE,
    ];

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
    $tax = $form_state->getValue(['step_content', 'tax']) ?? [];
    $gstStatus = (string) ($tax['gst_status'] ?? '');
    if ($gstStatus === OrganiserTaxProfileManager::STATUS_REGISTERED) {
      if (!$this->organiserTaxProfile->isValidAbn((string) ($tax['abn'] ?? ''))) {
        $form_state->setError($form['step_content']['tax']['abn'], $this->t('Enter a valid 11-digit ABN for a GST-registered organiser.'));
      }
      if (empty($tax['gst_effective_date'])) {
        $form_state->setError($form['step_content']['tax']['gst_effective_date'], $this->t('Enter the date your GST registration took effect.'));
      }
    }
    if (empty($tax['declaration'])) {
      $form_state->setError($form['step_content']['tax']['declaration'], $this->t('You must confirm that your legal and tax details are accurate.'));
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
    $tax = $form_state->getValue(['step_content', 'tax']) ?? [];
    $flags['tax_entity_type'] = trim((string) ($tax['entity_type'] ?? ''));
    $flags['gst_registration_status'] = trim((string) ($tax['gst_status'] ?? ''));
    $flags['abn'] = preg_replace('/\D+/', '', (string) ($tax['abn'] ?? '')) ?? '';
    $flags['gst_effective_date'] = trim((string) ($tax['gst_effective_date'] ?? ''));
    $flags['acnc_status'] = trim((string) ($tax['acnc_status'] ?? 'not_applicable'));
    $flags['dgr_status'] = trim((string) ($tax['dgr_status'] ?? 'not_endorsed'));
    $flags['tax_declaration_at'] = !empty($tax['declaration'])
      ? (int) $this->time->getRequestTime()
      : 0;

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
    $taxFieldMap = [
      'field_tax_entity_type' => $flags['tax_entity_type'],
      'field_gst_registration_status' => $flags['gst_registration_status'],
      'field_gst_effective_date' => $flags['gst_registration_status'] === OrganiserTaxProfileManager::STATUS_REGISTERED
        ? $flags['gst_effective_date']
        : NULL,
      'field_acnc_status' => $flags['acnc_status'],
      'field_dgr_status' => $flags['dgr_status'],
      'field_tax_declaration_at' => $flags['tax_declaration_at'],
      'field_abn' => $flags['abn'] !== '' ? $flags['abn'] : NULL,
    ];
    foreach ($taxFieldMap as $fieldName => $value) {
      if ($vendor->hasField($fieldName)) {
        $vendor->set($fieldName, $value);
      }
    }
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
    if (!empty($flags['terms_accepted'])
      && !empty($flags['tax_declaration_at'])
      && $flags['tax_entity_type'] !== ''
      && in_array($flags['gst_registration_status'], [OrganiserTaxProfileManager::STATUS_REGISTERED, OrganiserTaxProfileManager::STATUS_NOT_REGISTERED], TRUE)
      && $name !== ''
      && (int) ($state->getVendorId() ?? 0) > 0) {
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
