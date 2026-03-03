<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_pro\Entity\ProVendorComms;
use Drupal\myeventlane_pro\Service\ProActiveResolver;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolver;
use Drupal\Component\Utility\Xss;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Vendor-facing settings form for Pro communication overrides.
 */
final class ProVendorCommsForm extends FormBase {

  public function __construct(
    private readonly CurrentVendorResolver $currentVendorResolver,
    private readonly ProActiveResolver $proActiveResolver,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_vendor.current_vendor_resolver'),
      $container->get('myeventlane_pro.active_resolver'),
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
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
    if (!$this->proActiveResolver->isStoreProActive($store)) {
      throw new AccessDeniedHttpException('Active Pro subscription required.');
    }

    $comms = $this->loadOrCreate($store);
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable custom communication overrides'),
      '#default_value' => (bool) $comms->get('enabled'),
    ];

    $form['tokens_help'] = [
      '#type' => 'item',
      '#markup' => '<p>Allowed tokens: [event:title], [event:date], [event:location], [customer:first_name], [order:total], [ticket:type]</p>',
    ];

    $form['rsvp_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('RSVP email body'),
      '#default_value' => (string) $comms->get('rsvp_body'),
    ];
    $form['ticket_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Ticket/order receipt body'),
      '#default_value' => (string) $comms->get('ticket_body'),
    ];
    $form['reminder_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reminder body'),
      '#default_value' => (string) $comms->get('reminder_body'),
    ];
    $form['abandoned_cart_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Abandoned cart body'),
      '#default_value' => (string) $comms->get('abandoned_cart_body'),
    ];
    $form['brand_signature'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Brand signature'),
      '#default_value' => (string) $comms->get('brand_signature'),
    ];

    $form['actions']['preview'] = [
      '#type' => 'submit',
      '#value' => $this->t('Preview'),
      '#submit' => ['::previewSubmit'],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save settings'),
    ];

    $preview = $form_state->get('preview_markup');
    if (is_string($preview) && $preview !== '') {
      $form['preview'] = [
        '#type' => 'details',
        '#title' => $this->t('Preview'),
        '#open' => TRUE,
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
    $template = (string) $form_state->getValue('ticket_body');
    $signature = (string) $form_state->getValue('brand_signature');
    $replacements = [
      '[event:title]' => 'Sample Event',
      '[event:date]' => 'March 31, 2026 7:00 PM',
      '[event:location]' => 'London',
      '[customer:first_name]' => 'Alex',
      '[order:total]' => '49.00',
      '[ticket:type]' => 'General Admission',
    ];
    $preview = strtr($template, $replacements);
    $safeBody = Xss::filter($preview, ['p', 'strong', 'em', 'br', 'ul', 'li', 'a']);
    $safeSignature = Xss::filter($signature, ['p', 'strong', 'em', 'br', 'ul', 'li', 'a']);
    $footer = '<hr /><p>' . $safeSignature . '</p><p>You are receiving this message because of your activity on MyEventLane.</p>';
    $form_state->set('preview_markup', trim($safeBody) . $footer);
    $form_state->setRebuild(TRUE);
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
    $comms->set('updated', $this->time->getRequestTime());
    $comms->save();

    $this->messenger()->addStatus($this->t('Pro communication overrides saved.'));
  }

  /**
   * Resolves current vendor store for the current account.
   */
  private function resolveCurrentStore(): StoreInterface {
    $vendor = $this->currentVendorResolver->resolveFromCurrentUser();
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
    $storage = $this->entityTypeManager->getStorage('myeventlane_pro_vendor_comms');
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
      'updated' => $this->time->getRequestTime(),
    ]);
  }

}
