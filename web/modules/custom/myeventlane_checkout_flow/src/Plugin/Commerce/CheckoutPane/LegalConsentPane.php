<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\myeventlane_legal\Service\LegalSettingsService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides legal consent pane with required checkbox and versioning.
 *
 * @CommerceCheckoutPane(
 *   id = "mel_legal_consent",
 *   label = @Translation("Terms and conditions"),
 *   default_step = "checkout",
 *   wrapper_element = "fieldset",
 * )
 */
final class LegalConsentPane extends CheckoutPaneBase {

  /**
   * The legal settings service.
   */
  private readonly LegalSettingsService $legalSettings;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?CheckoutFlowInterface $checkout_flow = NULL) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition, $checkout_flow);
    $instance->legalSettings = $container->get('myeventlane_legal.settings');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form): array {
    $order = $this->order;

    $consent_given = FALSE;
    $consent_timestamp = NULL;
    if ($order->hasField('field_legal_consent_given') && !$order->get('field_legal_consent_given')->isEmpty()) {
      $consent_given = (bool) $order->get('field_legal_consent_given')->value;
    }
    if ($order->hasField('field_legal_consent_timestamp') && !$order->get('field_legal_consent_timestamp')->isEmpty()) {
      $consent_timestamp = (int) $order->get('field_legal_consent_timestamp')->value;
    }

    $collection_notice = $this->legalSettings->getCollectionNoticeCheckout();

    if ($collection_notice !== '') {
      $pane_form['collection_notice'] = [
        '#type' => 'markup',
        '#markup' => '<p class="mel-collection-notice">' . nl2br(htmlspecialchars($collection_notice)) . '</p>',
        '#weight' => -10,
      ];
    }

    // Link::toString() returns MarkupInterface so @ placeholders in t() do not
    // escape the anchors (plain HTML strings were being escaped previously).
    $pane_form['consent_text'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-consent-text']],
      'message' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('By proceeding, you agree to our @terms, @privacy, and @refund.', [
          '@terms' => $this->buildPolicyLinkMarkup(
            $this->legalSettings->getCustomerTermsUrl(),
            $this->t('Terms of Service'),
          ),
          '@privacy' => $this->buildPolicyLinkMarkup(
            $this->legalSettings->getPrivacyUrl(),
            $this->t('Privacy Policy'),
          ),
          '@refund' => $this->buildPolicyLinkMarkup(
            $this->legalSettings->getRefundPolicyUrl(),
            $this->t('Refund Policy'),
          ),
        ]),
      ],
    ];

    $pane_form['consent_checkbox'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I agree to the Terms of Service, Privacy Policy, and Refund Policy'),
      // Enforce consent in validatePaneForm(), not core #required (same pattern
      // as RSVP: core "field is required" runs before pane validators).
      '#required' => FALSE,
      '#default_value' => $consent_given,
      '#attributes' => [
        'class' => ['mel-consent-checkbox'],
        'aria-required' => 'true',
      ],
    ];

    $pane_form['consent_timestamp'] = [
      '#type' => 'hidden',
      '#value' => $consent_timestamp ?? time(),
    ];

    return $pane_form;
  }

  /**
   * Builds a policy link safe for @ placeholders (MarkupInterface, XSS-safe).
   */
  private function buildPolicyLinkMarkup(string $url, TranslatableMarkup $title): MarkupInterface|TranslatableMarkup {
    $url = trim($url);
    if ($url === '') {
      return $title;
    }

    try {
      $url_object = str_starts_with($url, '/')
        ? Url::fromUserInput($url)
        : Url::fromUri($url);
    }
    catch (\InvalidArgumentException) {
      return $title;
    }

    $url_object->setOption('attributes', [
      'target' => '_blank',
      'rel' => 'noopener',
    ]);

    return Link::fromTextAndUrl($title, $url_object)->toString();
  }

  /**
   * {@inheritdoc}
   */
  public function validatePaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    $values = $form_state->getValue($pane_form['#parents']);
    $consent_given = (bool) ($values['consent_checkbox'] ?? FALSE);

    if (!$consent_given) {
      $form_state->setError($pane_form['consent_checkbox'], $this->t('You must agree to the Terms of Service, Privacy Policy, and Refund Policy to continue.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    $values = $form_state->getValue($pane_form['#parents']);
    $order = $this->order;

    $consent_given = (bool) ($values['consent_checkbox'] ?? FALSE);
    $consent_timestamp = (int) ($values['consent_timestamp'] ?? time());
    $customerTermsVersion = $this->legalSettings->getCustomerTermsVersion();
    $privacyVersion = $this->legalSettings->getPrivacyVersion();

    if ($order->hasField('field_legal_consent_given')) {
      $order->set('field_legal_consent_given', $consent_given);
    }
    if ($order->hasField('field_legal_consent_timestamp')) {
      $order->set('field_legal_consent_timestamp', $consent_timestamp);
    }
    if ($order->hasField('field_customer_terms_version')) {
      $order->set('field_customer_terms_version', $customerTermsVersion);
    }
    if ($order->hasField('field_customer_terms_accepted_at')) {
      $order->set('field_customer_terms_accepted_at', $consent_timestamp);
    }
    if ($order->hasField('field_privacy_version')) {
      $order->set('field_privacy_version', $privacyVersion);
    }
    if ($order->hasField('field_privacy_accepted_at')) {
      $order->set('field_privacy_accepted_at', $consent_timestamp);
    }

    if (!$order->hasField('field_legal_consent_given')) {
      $order->setData('legal_consent_given', $consent_given);
      $order->setData('legal_consent_timestamp', $consent_timestamp);
    }

    $order->save();
  }

}
