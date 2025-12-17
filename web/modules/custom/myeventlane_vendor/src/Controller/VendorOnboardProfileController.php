<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for vendor onboarding step 2: Profile setup.
 */
final class VendorOnboardProfileController extends ControllerBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static();
  }

  /**
   * Step 2: Vendor profile setup.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   Render array or redirect.
   */
  public function profile(): array|RedirectResponse {
    $currentUser = $this->currentUser();

    // Require authentication.
    if ($currentUser->isAnonymous()) {
      return new RedirectResponse(
        Url::fromRoute('myeventlane_vendor.onboard.account')->toString()
      );
    }

    // Check if user already has a vendor.
    $vendorIds = $this->getUserVendors((int) $currentUser->id());

    $vendor = NULL;
    if (!empty($vendorIds)) {
      // Load existing vendor.
      $vendor = $this->entityTypeManager()
        ->getStorage('myeventlane_vendor')
        ->load(reset($vendorIds));
    }
    else {
      // Create new vendor entity.
      $vendor = Vendor::create([
        'uid' => (int) $currentUser->id(),
      ]);
    }

    // Get the vendor form.
    $formObject = $this->entityTypeManager()
      ->getFormObject('myeventlane_vendor', $vendor->isNew() ? 'add' : 'edit')
      ->setEntity($vendor);

    $form = $this->formBuilder()->getForm($formObject);

    // Enhance form for onboarding context.
    $this->enhanceFormForOnboarding($form, $vendor);

    // Override submit redirect.
    $form['actions']['submit']['#submit'][] = [$this, 'onboardFormSubmit'];

    return [
      '#theme' => 'vendor_onboard_step',
      '#step_number' => 2,
      '#total_steps' => 5,
      '#step_title' => $this->t('Set up your organiser profile'),
      '#step_description' => $this->t('Tell people about your organisation. This information will appear on your public organiser page.'),
      '#content' => $form,
      '#attached' => [
        'library' => ['myeventlane_vendor/onboarding'],
      ],
    ];
  }

  /**
   * Enhances the vendor form for onboarding context.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The vendor entity.
   */
  private function enhanceFormForOnboarding(array &$form, Vendor $vendor): void {
    // Improve field labels and descriptions.
    if (isset($form['name'])) {
      $form['name']['widget'][0]['value']['#title'] = $this->t('Organiser name');
      $form['name']['widget'][0]['value']['#description'] = $this->t('The public name for your organisation (e.g., "Sydney Music Festival").');
      $form['name']['widget'][0]['value']['#attributes']['placeholder'] = $this->t('Enter your organiser name');
    }

    if (isset($form['field_vendor_logo'])) {
      $form['field_vendor_logo']['widget'][0]['#title'] = $this->t('Logo');
      $form['field_vendor_logo']['widget'][0]['#description'] = $this->t('Upload your organisation logo. This will appear on your public page and event listings.');
    }

    if (isset($form['field_vendor_bio'])) {
      $form['field_vendor_bio']['widget'][0]['#title'] = $this->t('About your organisation');
      $form['field_vendor_bio']['widget'][0]['value']['#description'] = $this->t('A brief description of your organisation. This helps attendees learn more about you.');
      $form['field_vendor_bio']['widget'][0]['value']['#attributes']['placeholder'] = $this->t('Tell people about your organisation...');
    }

    // Also handle field_description if it exists (alternative to field_vendor_bio).
    if (isset($form['field_description'])) {
      $form['field_description']['widget'][0]['#title'] = $this->t('About your organisation');
      $form['field_description']['widget'][0]['value']['#description'] = $this->t('A brief description of your organisation. This helps attendees learn more about you.');
      $form['field_description']['widget'][0]['value']['#attributes']['placeholder'] = $this->t('Tell people about your organisation...');
    }

    // Improve contact field labels and descriptions.
    if (isset($form['field_email'])) {
      $form['field_email']['widget'][0]['value']['#title'] = $this->t('Contact email');
      $form['field_email']['widget'][0]['value']['#description'] = $this->t('Your organisation\'s contact email address.');
      $form['field_email']['widget'][0]['value']['#attributes']['placeholder'] = $this->t('contact@example.com');
    }

    if (isset($form['field_phone'])) {
      $form['field_phone']['widget'][0]['value']['#title'] = $this->t('Phone number');
      $form['field_phone']['widget'][0]['value']['#description'] = $this->t('Your organisation\'s contact phone number.');
      $form['field_phone']['widget'][0]['value']['#attributes']['placeholder'] = $this->t('+61 2 1234 5678');
    }

    if (isset($form['field_website'])) {
      $form['field_website']['widget'][0]['uri']['#title'] = $this->t('Website');
      $form['field_website']['widget'][0]['uri']['#description'] = $this->t('Your organisation\'s website URL.');
      $form['field_website']['widget'][0]['uri']['#attributes']['placeholder'] = $this->t('https://example.com');
    }

    // Hide advanced fields during onboarding.
    if (isset($form['field_vendor_users'])) {
      $form['field_vendor_users']['#access'] = FALSE;
    }

    // Hide store field (handled separately).
    if (isset($form['field_vendor_store'])) {
      $form['field_vendor_store']['#access'] = FALSE;
    }

    // Improve visibility toggles.
    if (isset($form['field_public_show_email'])) {
      $form['field_public_show_email']['widget']['value']['#title'] = $this->t('Show email on public page');
    }
    if (isset($form['field_public_show_phone'])) {
      $form['field_public_show_phone']['widget']['value']['#title'] = $this->t('Show phone on public page');
    }
    if (isset($form['field_public_show_location'])) {
      $form['field_public_show_location']['widget']['value']['#title'] = $this->t('Show location on public page');
    }

    // Update submit button text.
    if (isset($form['actions']['submit'])) {
      $form['actions']['submit']['#value'] = $this->t('Continue to payment setup');
    }

    // Add wrapper classes for styling.
    $form['#attributes']['class'][] = 'mel-onboard-form';
    $form['#attributes']['class'][] = 'mel-onboard-profile-form';
  }

  /**
   * Submit handler for onboarding profile form.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function onboardFormSubmit(array $form, \Drupal\Core\Form\FormStateInterface $form_state): void {
    $vendor = $form_state->getFormObject()->getEntity();
    $currentUser = $this->currentUser();

    // Ensure the current user is associated with the vendor.
    if ($vendor->hasField('field_vendor_users')) {
      $currentUsers = $vendor->get('field_vendor_users')->getValue();
      $userIds = array_column($currentUsers, 'target_id');
      if (!in_array($currentUser->id(), $userIds, TRUE)) {
        $vendor->get('field_vendor_users')->appendItem(['target_id' => $currentUser->id()]);
      }
    }

    // Redirect to next step.
    $form_state->setRedirect('myeventlane_vendor.onboard.stripe');
  }

  /**
   * Gets vendor IDs associated with a user.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array
   *   Array of vendor IDs.
   */
  private function getUserVendors(int $uid): array {
    $storage = $this->entityTypeManager()->getStorage('myeventlane_vendor');

    $ownerIds = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->execute();

    $usersIds = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_vendor_users', $uid)
      ->execute();

    $allIds = array_merge($ownerIds ?: [], $usersIds ?: []);
    return array_values(array_unique(array_map('intval', $allIds)));
  }

}

















