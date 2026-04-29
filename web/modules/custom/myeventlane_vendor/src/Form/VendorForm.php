<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\EventSubscriber\VendorStoreSubscriber;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for the Vendor entity forms.
 *
 * Vendor console profile/settings uses form ID organiser_profile_settings
 * (see getFormId()) so theme and alter hooks can target the organiser UI.
 */
class VendorForm extends ContentEntityForm {

  /**
   * Ensures a Commerce store exists and syncs tax/store metadata from vendor.
   */
  protected VendorStoreSubscriber $vendorStoreSubscriber;

  protected LoggerInterface $melDebugLogger;

  protected LoggerInterface $vendorLogger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    /** @var static $instance */
    $instance = parent::create($container);
    $instance->vendorStoreSubscriber = $container->get('myeventlane_vendor.vendor_store_subscriber');
    $instance->melDebugLogger = $container->get('logger.channel.mel_debug');
    $instance->vendorLogger = $container->get('logger.channel.myeventlane_vendor');
    $instance->setRequestStack($container->get('request_stack'));
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'organiser_profile_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $this->melDebugLogger->notice('ORGANISER FORM BUILD');
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);
    $this->melDebugLogger->notice('ORGANISER FORM SUBMIT');
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $entity = $this->getEntity();
    $currentUser = $this->currentUser();

    // ENFORCE 1:1 RELATIONSHIP: Prevent duplicate Vendor creation at form level.
    // This validation runs before preSave(), providing early feedback.
    if ($entity->isNew()) {
      $ownerId = $entity->getOwnerId();
      if ($ownerId === NULL) {
        $ownerId = (int) $currentUser->id();
      }

      if ($ownerId > 0) {
        $storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
        $existingVendorIds = $storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('uid', $ownerId)
          ->execute();

        if (!empty($existingVendorIds)) {
          $message = $this->t('A vendor entity already exists for this user. Each user can only have one vendor entity.');
          $form_state->setErrorByName('name', $message);
          $this->vendorLogger->warning('Form validation blocked duplicate Vendor creation for user @uid', [
            '@uid' => $ownerId,
          ]);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $entity = $this->getEntity();

    $route_name = $this->getRouteMatch()->getRouteName();
    if ($route_name === 'myeventlane_vendor.console.settings') {
      $form['#attached']['library'][] = 'myeventlane_vendor_theme/global-styling';
      $form['#attached']['library'][] = 'myeventlane_vendor/vendor_settings';
      $form['#attributes']['class'][] = 'vendor-settings-form';
      $request = $this->getRequest();
      if ($request) {
        $form['#action'] = $request->getRequestUri();
      }
      $form['#method'] = 'post';
    }

    // Group fields into logical sections.
    $form['basic_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Basic information'),
      '#weight' => -20,
    ];

    // Move name field into basic_info section.
    if (isset($form['name'])) {
      $form['name']['#group'] = 'basic_info';
    }

    // Move logo into basic_info section.
    if (isset($form['field_vendor_logo'])) {
      $form['field_vendor_logo']['#group'] = 'basic_info';
    }

    // Move bio/description into basic_info section.
    if (isset($form['field_vendor_bio'])) {
      $form['field_vendor_bio']['#group'] = 'basic_info';
    }
    if (isset($form['field_description'])) {
      $form['field_description']['#group'] = 'basic_info';
    }

    // Legal / invoicing (shown when fields are enabled on form display).
    if (isset($form['field_business_name'])) {
      $form['field_business_name']['#group'] = 'basic_info';
    }
    if (isset($form['field_abn'])) {
      $form['field_abn']['#group'] = 'basic_info';
    }

    // Contact and visibility section.
    $form['contact_visibility'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Contact details and visibility'),
      '#weight' => -10,
    ];

    // Contact information fields.
    if (isset($form['field_email'])) {
      $form['field_email']['#group'] = 'contact_visibility';
    }
    if (isset($form['field_phone'])) {
      $form['field_phone']['#group'] = 'contact_visibility';
    }
    if (isset($form['field_website'])) {
      $form['field_website']['#group'] = 'contact_visibility';
    }
    if (isset($form['field_address'])) {
      $form['field_address']['#group'] = 'contact_visibility';
    }
    if (isset($form['field_social_links'])) {
      $form['field_social_links']['#group'] = 'contact_visibility';
    }

    // Visibility toggles.
    if (isset($form['field_public_show_email'])) {
      $form['field_public_show_email']['#group'] = 'contact_visibility';
    }
    if (isset($form['field_public_show_phone'])) {
      $form['field_public_show_phone']['#group'] = 'contact_visibility';
    }
    if (isset($form['field_public_show_location'])) {
      $form['field_public_show_location']['#group'] = 'contact_visibility';
    }

    // Users section.
    if (isset($form['field_vendor_users'])) {
      $form['field_vendor_users']['#group'] = 'contact_visibility';
    }

    // AI assistant and tips (vendor preferences).
    $form['preferences'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('AI assistant and tips'),
      '#weight' => 95,
    ];
    if (isset($form['ai_enabled'])) {
      $form['ai_enabled']['#group'] = 'preferences';
    }
    if (isset($form['nudges_enabled'])) {
      $form['nudges_enabled']['#group'] = 'preferences';
    }

    // Store and Stripe section (admin-only).
    $account = $this->currentUser();
    if ($account->hasPermission('administer myeventlane vendor')) {
      $form['store_stripe'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Store and Stripe integration'),
        '#weight' => 10,
        '#collapsible' => TRUE,
        '#collapsed' => $entity->isNew(),
      ];

      if (isset($form['field_vendor_store'])) {
        $form['field_vendor_store']['#group'] = 'store_stripe';
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $entity = $this->getEntity();
    if ($entity instanceof Vendor) {
      $this->normalizeAbnField($entity);
      $this->syncCommerceStoreFromOrganiserProfile($entity);
    }

    $result = parent::save($form, $form_state);

    $entity = $this->getEntity();
    $message_arguments = ['%label' => $entity->toLink()->toString()];
    $logger_arguments = [
      '%label' => $entity->label(),
      'link' => $entity->toLink($this->t('View'))->toString(),
    ];

    $route_name = $this->getRouteMatch()->getRouteName();
    $console_settings = ($route_name === 'myeventlane_vendor.console.settings');

    switch ($result) {
      case SAVED_NEW:
        $this->messenger()->addStatus($this->t('New vendor %label has been created.', $message_arguments));
        $this->vendorLogger->notice('Created new vendor %label', $logger_arguments);
        if ($console_settings) {
          $form_state->setRedirect('myeventlane_vendor.console.settings');
        }
        else {
          $form_state->setRedirect('entity.myeventlane_vendor.canonical', ['myeventlane_vendor' => $entity->id()]);
        }
        break;

      case SAVED_UPDATED:
        $this->messenger()->addStatus($this->t('The vendor %label has been updated.', $message_arguments));
        $this->vendorLogger->notice('Updated vendor %label.', $logger_arguments);
        if ($console_settings) {
          $form_state->setRedirect('myeventlane_vendor.console.settings');
        }
        else {
          $form_state->setRedirect('entity.myeventlane_vendor.canonical', ['myeventlane_vendor' => $entity->id()]);
        }
        break;

      default:
        $form_state->setRedirect('entity.myeventlane_vendor.collection');
    }

    return $result;
  }

  /**
   * Normalises ABN whitespace before entity persistence.
   */
  protected function normalizeAbnField(Vendor $vendor): void {
    if (!$vendor->hasField('field_abn')) {
      return;
    }
    if ($vendor->get('field_abn')->isEmpty()) {
      return;
    }
    $raw = trim((string) $vendor->get('field_abn')->value);
    $normalized = $raw !== '' ? (string) preg_replace('/\s+/', '', $raw) : '';
    $vendor->set('field_abn', $normalized !== '' ? $normalized : NULL);
  }

  /**
   * Ensures a linked Commerce store exists and syncs ABN / business name.
   *
   * Mirrors logic previously on VendorProfileSettingsForm::submitForm().
   */
  protected function syncCommerceStoreFromOrganiserProfile(Vendor $vendor): void {
    $had_vendor_store_link = $vendor->hasField('field_vendor_store')
      && !$vendor->get('field_vendor_store')->isEmpty();

    $abn = '';
    if ($vendor->hasField('field_abn') && !$vendor->get('field_abn')->isEmpty()) {
      $abn = trim((string) $vendor->get('field_abn')->value);
      $abn = $abn !== '' ? (string) preg_replace('/\s+/', '', $abn) : '';
    }

    $business_name = '';
    if ($vendor->hasField('field_business_name') && !$vendor->get('field_business_name')->isEmpty()) {
      $business_name = trim((string) $vendor->get('field_business_name')->value);
    }

    $vendor_storage = $this->entityTypeManager->getStorage('myeventlane_vendor');

    $store = NULL;
    if ($vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $candidate = $vendor->get('field_vendor_store')->entity;
      if ($candidate instanceof StoreInterface) {
        $store = $candidate;
      }
    }

    if (!$store) {
      $store = $this->vendorStoreSubscriber->ensureStoreForVendor($vendor);
    }

    if (!$store && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $candidate = $vendor->get('field_vendor_store')->entity;
      if ($candidate instanceof StoreInterface) {
        $store = $candidate;
      }
    }

    if (!$had_vendor_store_link && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $vendor_storage->resetCache([(int) $vendor->id()]);
      $reloaded_vendor = $vendor_storage->load($vendor->id());
      if ($reloaded_vendor instanceof Vendor
        && !$reloaded_vendor->get('field_vendor_store')->isEmpty()) {
        $vendor->set('field_vendor_store', $reloaded_vendor->get('field_vendor_store')->getValue());
      }
      $candidate = $vendor->get('field_vendor_store')->entity ?? NULL;
      if ($candidate instanceof StoreInterface) {
        $store = $candidate;
      }
    }

    if (!$store) {
      $this->vendorLogger->error(
        'Organiser profile save: no linked store after ensureStoreForVendor for vendor @id',
        ['@id' => (string) $vendor->id()]
      );
      $this->messenger()->addWarning($this->t('Your profile changes could not be linked to a Commerce store yet. Payment and tax settings may be incomplete; contact support if this persists.'));
      return;
    }

    if ($store->hasField('field_abn')) {
      $store->set('field_abn', $abn !== '' ? $abn : NULL);
    }
    if ($business_name !== '') {
      $store->setName($business_name);
    }
    try {
      $store->save();
    }
    catch (\Throwable $e) {
      $this->vendorLogger->error(
        'Organiser profile: could not sync Commerce store: @message',
        ['@message' => $e->getMessage()]
      );
    }
  }

}
