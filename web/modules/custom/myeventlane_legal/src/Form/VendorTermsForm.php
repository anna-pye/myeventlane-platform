<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_legal\Service\LegalSettingsService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Vendor terms acceptance form.
 */
final class VendorTermsForm extends FormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    private readonly LegalSettingsService $legalSettings,
    private readonly AccountProxyInterface $currentUser,
    private readonly OnboardingManager $onboardingManager,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $myeventlaneVendorLogger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_legal.settings'),
      $container->get('current_user'),
      $container->get('myeventlane_onboarding.manager'),
      $container->get('datetime.time'),
      $container->get('logger.factory')->get('myeventlane_vendor'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'vendor_terms_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    if ((int) $this->currentUser->id() > 0) {
      $vendor = $this->onboardingManager->ensureVendorExists($this->currentUser);
      if ($vendor->hasField('field_vendor_terms_accepted_at') && !$vendor->get('field_vendor_terms_accepted_at')->isEmpty()) {
        $url = Url::fromRoute('myeventlane_event_studio.create', [], [
          'absolute' => FALSE,
        ]);
        throw new EnforcedResponseException(
          new RedirectResponse($url->toString(), 302),
        );
      }
    }

    $vendorUrl = $this->legalSettings->getVendorTermsUrl();
    $privacyUrl = $this->legalSettings->getPrivacyUrl();

    $form['#attached']['library'][] = 'myeventlane_vendor/onboarding';

    $form['hero'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-onboard-terms-hero']],
    ];
    $form['hero']['headline'] = [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('Welcome to hosting on MyEventLane 🎉'),
      '#attributes' => ['class' => ['mel-onboard-terms-hero__headline']],
    ];
    $form['hero']['lead'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Before you start, just a couple of quick things:'),
      '#attributes' => ['class' => ['mel-onboard-terms-hero__lead']],
    ];
    $form['hero']['bullets'] = [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('Be respectful'),
        $this->t('Follow local laws'),
        $this->t('Keep your community safe'),
      ],
      '#attributes' => ['class' => ['mel-onboard-terms-hero__bullets']],
    ];

    $form['legal'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Legal agreements'),
      '#attributes' => ['class' => ['mel-vendor-terms']],
    ];
    $form['legal']['vendor_terms_agreed'] = [
      '#type' => 'checkbox',
      '#title' => $vendorUrl
        ? $this->t('I agree to the <a href=":url" target="_blank" rel="noopener">Vendor Terms of Service</a>', [':url' => $vendorUrl])
        : $this->t('I agree to the Vendor Terms of Service'),
      '#required' => TRUE,
      '#default_value' => FALSE,
      '#attributes' => ['aria-required' => 'true'],
    ];
    $form['legal']['privacy_acknowledged'] = [
      '#type' => 'checkbox',
      '#title' => $privacyUrl
        ? $this->t('I have read the <a href=":url" target="_blank" rel="noopener">Privacy Policy</a>', [':url' => $privacyUrl])
        : $this->t('I have read the Privacy Policy'),
      '#required' => TRUE,
      '#default_value' => FALSE,
      '#attributes' => ['aria-required' => 'true'],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Agree & Continue'),
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Form root has #tree = FALSE by default, so values are flat (not under 'legal').
    $vendorAgreed = (bool) $form_state->getValue('vendor_terms_agreed');
    $privacyAck = (bool) $form_state->getValue('privacy_acknowledged');
    if (!$vendorAgreed) {
      $form_state->setError($form['legal']['vendor_terms_agreed'], $this->t('You must agree to the Vendor Terms of Service.'));
    }
    if (!$privacyAck) {
      $form_state->setError($form['legal']['privacy_acknowledged'], $this->t('You must confirm you have read the Privacy Policy.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser->id();
    if ($uid === 0) {
      return;
    }

    $state = $this->onboardingManager->createVendorStateForUid($uid);

    $timestamp = $this->time->getRequestTime();
    $flags = $state->getFlags();
    $flags = is_array($flags) ? $flags : [];
    $flags['vendor_terms_version'] = $this->legalSettings->getVendorTermsVersion();
    $flags['vendor_terms_accepted_at'] = (int) $timestamp;
    if ($this->legalSettings->storeVendorIpUa()) {
      $request = $this->getRequest();
      if ($request) {
        $flags['vendor_terms_accepted_ip'] = $request->getClientIp();
        $flags['vendor_terms_accepted_ua'] = (string) $request->headers->get('User-Agent', '');
      }
    }
    $state->setFlags($flags);
    $this->onboardingManager->persistOnboardingState($state);

    $vendor = $this->onboardingManager->ensureVendorExists($this->currentUser);
    $this->onboardingManager->applyVendorLegalFieldsFromStateFlags($vendor, $flags);
    $vendor->save();

    $this->myeventlaneVendorLogger->notice(
      'MEL: vendor terms synced to vendor entity (terms form) uid=@uid vendor=@vid',
      [
        '@uid' => (string) $this->currentUser->id(),
        '@vid' => (string) $vendor->id(),
      ],
    );

    $this->messenger()->addStatus($this->t('Thank you. You can now create events.'));
    $form_state->setRedirectUrl(Url::fromRoute('myeventlane_event_studio.create'));
  }

}
