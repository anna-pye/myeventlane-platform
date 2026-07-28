<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_pro\Entity\ProVendorComms;
use Drupal\myeventlane_pro\Service\ProActiveResolver;
use Drupal\myeventlane_pro\Service\VendorCommsPlaceholderRenderer;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolver;
use Drupal\Component\Utility\Xss;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Vendor-facing settings form for Pro communication overrides.
 */
final class ProVendorCommsForm extends FormBase {

  /**
   * Rehydrated lazily because Drupal serialises form objects between submits.
   */
  private ?CurrentVendorResolver $currentVendorResolver = NULL;
  private ?ProActiveResolver $proActiveResolver = NULL;
  private ?EntityTypeManagerInterface $entityTypeManager = NULL;
  private ?TimeInterface $time = NULL;
  private ?VendorCommsPlaceholderRenderer $placeholderRenderer = NULL;

  public function __construct(
    CurrentVendorResolver $currentVendorResolver,
    ProActiveResolver $proActiveResolver,
    EntityTypeManagerInterface $entityTypeManager,
    TimeInterface $time,
    VendorCommsPlaceholderRenderer $placeholderRenderer,
  ) {
    $this->currentVendorResolver = $currentVendorResolver;
    $this->proActiveResolver = $proActiveResolver;
    $this->entityTypeManager = $entityTypeManager;
    $this->time = $time;
    $this->placeholderRenderer = $placeholderRenderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_vendor.current_vendor_resolver'),
      $container->get('myeventlane_pro.active_resolver'),
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
      $container->get('myeventlane_pro.vendor_comms_placeholder_renderer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_pro_vendor_comms_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $store = $this->resolveCurrentStore();
    if (!$this->proActiveResolver()->isStoreProActive($store)) {
      throw new AccessDeniedHttpException('Active Pro subscription required.');
    }

    $comms = $this->loadOrCreate($store);
    $customStatus = fn(string $value): string => trim($value) === ''
      ? (string) $this->t('Standard')
      : (string) $this->t('Custom');

    $form['#attributes']['class'][] = 'mel-pro-email-wording';
    $form['#attached']['library'][] = 'myeventlane_vendor_theme/pro_email_wording';

    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-pro-email-wording__intro', 'mel-card']],
      'eyebrow' => [
        '#markup' => '<p class="mel-pro-email-wording__eyebrow">' . $this->t('MEL Pro · Automatic emails') . '</p>',
      ],
      'title' => [
        '#markup' => '<h1 class="mel-pro-email-wording__title">' . $this->t('Make automatic emails sound like you') . '</h1>',
      ],
      'body' => [
        '#markup' => '<p class="mel-pro-email-wording__lead">' . $this->t('Choose the automatic emails you want to personalise. Standard MyEventLane wording remains in place everywhere else.') . '</p>',
      ],
      'links' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-pro-email-wording__links']],
        'messages' => [
          '#type' => 'link',
          '#title' => $this->t('← Back to Messages'),
          '#url' => Url::fromRoute('myeventlane_vendor.console.messages'),
          '#attributes' => ['class' => ['mel-button', 'mel-button--secondary']],
        ],
        'brand' => [
          '#type' => 'link',
          '#title' => $this->t('Messages brand'),
          '#url' => Url::fromRoute('myeventlane_vendor.console.messaging_brand'),
          '#attributes' => ['class' => ['mel-pro-email-wording__text-link']],
        ],
      ],
    ];

    $form['activation'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-pro-email-wording__activation', 'mel-card']],
    ];
    $form['activation']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use my custom wording'),
      '#default_value' => (bool) $comms->get('enabled'),
      '#description' => $this->t('When switched on, only the completed email sections below replace MyEventLane’s standard wording. Leave a section blank to keep the standard email.'),
    ];

    $form['guidance'] = [
      '#type' => 'details',
      '#title' => $this->t('How personalised details work'),
      '#attributes' => ['class' => ['mel-pro-email-wording__guidance', 'mel-card']],
      'intro' => [
        '#markup' => '<p>' . $this->t('Add any of these placeholders and MyEventLane will replace it with the correct guest or event detail when the email is sent.') . '</p>',
      ],
      'tokens' => [
        '#markup' => '<div class="mel-pro-email-wording__tokens" aria-label="' . $this->t('Available personalised details') . '">'
          . '<code>[event:title]</code><code>[event:date]</code><code>[event:location]</code>'
          . '<code>[customer:first_name]</code><code>[order:total]</code><code>[ticket:type]</code>'
          . '</div><p class="mel-pro-email-wording__example"><strong>' . $this->t('Example:') . '</strong> '
          . $this->t('Hi [customer:first_name], your place at [event:title] is confirmed.') . '</p>',
      ],
    ];

    $form['workspace'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-pro-email-wording__workspace']],
    ];
    $form['workspace']['wording'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-pro-email-wording__wording']],
      'heading' => [
        '#markup' => '<div class="mel-pro-email-wording__section-heading"><p class="mel-pro-email-wording__eyebrow">'
          . $this->t('Email wording') . '</p><h2>' . $this->t('Choose what you want to customise') . '</h2><p>'
          . $this->t('Open an email type, add your wording, then preview it before saving.') . '</p></div>',
      ],
    ];

    $form['workspace']['wording']['rsvp'] = [
      '#type' => 'details',
      '#title' => $this->t('RSVP confirmation — @status', [
        '@status' => $customStatus((string) $comms->get('rsvp_body')),
      ]),
      '#description' => $this->t('Sent immediately after someone completes a free RSVP.'),
      '#attributes' => ['class' => ['mel-pro-email-wording__email', 'mel-card']],
    ];
    $form['workspace']['wording']['rsvp']['rsvp_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom RSVP wording'),
      '#default_value' => (string) $comms->get('rsvp_body'),
      '#description' => $this->t('Welcome the guest and confirm their place. Leave blank to use MyEventLane’s standard RSVP confirmation.'),
      '#rows' => 7,
    ];

    $form['workspace']['wording']['ticket'] = [
      '#type' => 'details',
      '#title' => $this->t('Ticket receipt — @status', [
        '@status' => $customStatus((string) $comms->get('ticket_body')),
      ]),
      '#description' => $this->t('Sent after a paid booking, including ticket and order information.'),
      '#attributes' => ['class' => ['mel-pro-email-wording__email', 'mel-card']],
    ];
    $form['workspace']['wording']['ticket']['ticket_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom ticket receipt wording'),
      '#default_value' => (string) $comms->get('ticket_body'),
      '#description' => $this->t('Thank the buyer and explain anything they should know before attending. Ticket details remain part of the email.'),
      '#rows' => 7,
    ];

    $form['workspace']['wording']['reminder'] = [
      '#type' => 'details',
      '#title' => $this->t('Event reminders — @status', [
        '@status' => $customStatus((string) $comms->get('reminder_body')),
      ]),
      '#description' => $this->t('Sent automatically before the event when a reminder is scheduled.'),
      '#attributes' => ['class' => ['mel-pro-email-wording__email', 'mel-card']],
    ];
    $form['workspace']['wording']['reminder']['reminder_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom reminder wording'),
      '#default_value' => (string) $comms->get('reminder_body'),
      '#description' => $this->t('Reassure guests with the start time, location and practical arrival information.'),
      '#rows' => 7,
    ];

    $form['workspace']['wording']['cart'] = [
      '#type' => 'details',
      '#title' => $this->t('Incomplete booking — @status', [
        '@status' => $customStatus((string) $comms->get('abandoned_cart_body')),
      ]),
      '#description' => $this->t('Sent by the Pro abandoned-cart service when someone starts but does not finish a paid booking.'),
      '#attributes' => ['class' => ['mel-pro-email-wording__email', 'mel-card']],
    ];
    $form['workspace']['wording']['cart']['abandoned_cart_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom incomplete-booking wording'),
      '#default_value' => (string) $comms->get('abandoned_cart_body'),
      '#description' => $this->t('Offer a warm, helpful invitation to complete the booking—without pressure.'),
      '#rows' => 7,
    ];

    $form['workspace']['wording']['signature'] = [
      '#type' => 'details',
      '#title' => $this->t('Email sign-off — @status', [
        '@status' => $customStatus((string) $comms->get('brand_signature')),
      ]),
      '#description' => $this->t('Added after any custom automatic-email wording above.'),
      '#attributes' => ['class' => ['mel-pro-email-wording__email', 'mel-card']],
    ];
    $form['workspace']['wording']['signature']['brand_signature'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Your sign-off'),
      '#default_value' => (string) $comms->get('brand_signature'),
      '#description' => $this->t('For example: “See you there, The MyEventLane team”. MyEventLane’s required delivery information is always retained.'),
      '#rows' => 4,
    ];

    $form['workspace']['preview_rail'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-pro-email-wording__preview-rail']],
    ];
    $form['workspace']['preview_rail']['preview_controls'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-pro-email-wording__preview-controls', 'mel-card']],
      'heading' => [
        '#markup' => '<h2>' . $this->t('Preview your wording') . '</h2><p>'
          . $this->t('Choose an email type to see sample guest and event details in place. Previewing does not save or send anything.') . '</p>',
      ],
    ];
    $form['workspace']['preview_rail']['preview_controls']['preview_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Email to preview'),
      '#options' => [
        'rsvp_body' => $this->t('RSVP confirmation'),
        'ticket_body' => $this->t('Ticket receipt and order confirmation'),
        'reminder_body' => $this->t('Event reminder'),
        'abandoned_cart_body' => $this->t('Incomplete booking reminder'),
      ],
      '#default_value' => (string) ($form_state->getValue('preview_type') ?: 'ticket_body'),
    ];

    $form['workspace']['preview_rail']['preview_controls']['preview'] = [
      '#type' => 'submit',
      '#value' => $this->t('Preview'),
      '#submit' => ['::previewSubmit'],
      '#attributes' => ['class' => ['mel-pro-email-wording__preview-button']],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save settings'),
    ];

    $preview = $form_state->get('preview_markup');
    if (is_string($preview) && $preview !== '') {
      $form['workspace']['preview_rail']['preview'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-pro-email-wording__preview', 'mel-card']],
        'heading' => [
          '#markup' => '<p class="mel-pro-email-wording__eyebrow">' . $this->t('Sample email') . '</p><h2>'
            . $this->t('Preview') . '</h2>',
        ],
        'body' => [
          '#markup' => $preview,
        ],
      ];
    }

    $form['#cache']['tags'][] = 'pro_subscription:' . (int) $store->id();
    $form['#cache']['tags'][] = 'commerce_store:' . (int) $store->id();
    return $form;
  }

  /**
   * Builds preview content without saving.
   */
  public function previewSubmit(array &$form, FormStateInterface $form_state): void {
    $previewType = (string) $form_state->getValue('preview_type');
    $allowedPreviewTypes = [
      'rsvp_body',
      'ticket_body',
      'reminder_body',
      'abandoned_cart_body',
    ];
    if (!in_array($previewType, $allowedPreviewTypes, TRUE)) {
      $previewType = 'ticket_body';
    }
    $template = (string) $form_state->getValue($previewType);
    $signature = (string) $form_state->getValue('brand_signature');
    $placeholderRenderer = $this->placeholderRenderer();
    $safeBody = $placeholderRenderer->render(
      $template,
      $placeholderRenderer->sampleContext(),
    );
    $safeSignature = Xss::filter($signature, ['p', 'strong', 'em', 'br', 'ul', 'li', 'a']);
    $footer = '<hr /><p>' . $safeSignature . '</p><p>You are receiving this message because of your activity on MyEventLane.</p>';
    $previewBody = trim($safeBody);
    if ($previewBody === '') {
      $previewBody = '<p>' . $this->t('This section is blank, so guests will receive MyEventLane’s standard wording.') . '</p>';
    }
    $form_state->set('preview_markup', $previewBody . $footer);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Prevents an enabled configuration that changes no email wording.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    if (!(bool) $form_state->getValue('enabled')) {
      return;
    }

    $customValues = [
      'rsvp_body',
      'ticket_body',
      'reminder_body',
      'abandoned_cart_body',
      'brand_signature',
    ];
    foreach ($customValues as $key) {
      if (trim((string) $form_state->getValue($key)) !== '') {
        return;
      }
    }

    $form_state->setErrorByName(
      'enabled',
      $this->t('Add wording to at least one email before turning on custom wording.'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $store = $this->resolveCurrentStore();
    $comms = $this->loadOrCreate($store);
    $comms->set('enabled', (bool) $form_state->getValue('enabled'));
    $comms->set('rsvp_body', (string) $form_state->getValue('rsvp_body'));
    $comms->set('ticket_body', (string) $form_state->getValue('ticket_body'));
    $comms->set('reminder_body', (string) $form_state->getValue('reminder_body'));
    $comms->set('abandoned_cart_body', (string) $form_state->getValue('abandoned_cart_body'));
    $comms->set('brand_signature', (string) $form_state->getValue('brand_signature'));
    $comms->set('updated', $this->time()->getRequestTime());
    $comms->save();

    $this->messenger()->addStatus($this->t('Pro communication overrides saved.'));
  }

  /**
   * Resolves current vendor store for the current account.
   */
  private function resolveCurrentStore(): StoreInterface {
    $vendor = $this->currentVendorResolver()->resolveFromCurrentUser();
    if (!$vendor || !$vendor->hasField('field_vendor_store') || $vendor->get('field_vendor_store')->isEmpty()) {
      throw new AccessDeniedHttpException('No vendor store found.');
    }
    $store = $vendor->get('field_vendor_store')->entity;
    if (!$store instanceof StoreInterface) {
      throw new AccessDeniedHttpException('No vendor store found.');
    }
    return $store;
  }

  /**
   * Loads existing config entity for store, or creates one in-memory.
   */
  private function loadOrCreate(StoreInterface $store): ProVendorComms {
    $storage = $this->entityTypeManager()->getStorage('myeventlane_pro_vendor_comms');
    $items = $storage->loadByProperties(['store_id' => (int) $store->id()]);
    $existing = $items === [] ? NULL : reset($items);
    if ($existing instanceof ProVendorComms) {
      return $existing;
    }

    return $storage->create([
      'id' => 'store_' . (int) $store->id(),
      'label' => 'Store ' . (int) $store->id() . ' comms',
      'store_id' => (int) $store->id(),
      'enabled' => FALSE,
      'updated' => $this->time()->getRequestTime(),
    ]);
  }

  private function placeholderRenderer(): VendorCommsPlaceholderRenderer {
    if (!$this->placeholderRenderer instanceof VendorCommsPlaceholderRenderer) {
      $renderer = \Drupal::service('myeventlane_pro.vendor_comms_placeholder_renderer');
      if (!$renderer instanceof VendorCommsPlaceholderRenderer) {
        throw new \RuntimeException('Pro email placeholder renderer is unavailable.');
      }
      $this->placeholderRenderer = $renderer;
    }
    return $this->placeholderRenderer;
  }

  private function currentVendorResolver(): CurrentVendorResolver {
    if (!$this->currentVendorResolver instanceof CurrentVendorResolver) {
      $this->currentVendorResolver = \Drupal::service('myeventlane_vendor.current_vendor_resolver');
    }
    return $this->currentVendorResolver;
  }

  private function proActiveResolver(): ProActiveResolver {
    if (!$this->proActiveResolver instanceof ProActiveResolver) {
      $this->proActiveResolver = \Drupal::service('myeventlane_pro.active_resolver');
    }
    return $this->proActiveResolver;
  }

  private function entityTypeManager(): EntityTypeManagerInterface {
    if (!$this->entityTypeManager instanceof EntityTypeManagerInterface) {
      $this->entityTypeManager = \Drupal::entityTypeManager();
    }
    return $this->entityTypeManager;
  }

  private function time(): TimeInterface {
    if (!$this->time instanceof TimeInterface) {
      $this->time = \Drupal::service('datetime.time');
    }
    return $this->time;
  }

}
