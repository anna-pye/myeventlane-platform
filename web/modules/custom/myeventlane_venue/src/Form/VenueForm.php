<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\media\MediaInterface;
use Drupal\myeventlane_location\Service\LocationProviderManager;
use Drupal\myeventlane_venue\Controller\VenueWebsiteMetadataController;
use Drupal\myeventlane_venue\Entity\Venue;
use Drupal\myeventlane_venue\Exception\DuplicateVenueException;
use Drupal\myeventlane_venue\Service\OverturePlaceRepository;
use Drupal\myeventlane_venue\Service\VenueDuplicateGuard;
use Drupal\myeventlane_venue\Service\VenueManager;
use Drupal\myeventlane_vendor\Service\OrganiserMediaAccess;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for the Venue entity add/edit forms.
 */
class VenueForm extends ContentEntityForm {

  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    protected OrganiserMediaAccess $organiserMediaAccess,
    protected VenueManager $venueManager,
    protected VenueDuplicateGuard $duplicateGuard,
    protected OverturePlaceRepository $overtureRepository,
    protected LocationProviderManager $locationProviderManager,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected CsrfTokenGenerator $csrfToken,
    protected AccountProxyInterface $account,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('myeventlane_vendor.organiser_media_access'),
      $container->get('myeventlane_venue.manager'),
      $container->get('myeventlane_venue.duplicate_guard'),
      $container->get('myeventlane_venue.overture_repository'),
      $container->get('myeventlane_location.provider_manager'),
      $container->get('tempstore.private'),
      $container->get('csrf_token'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $form['#attributes']['class'][] = 'mel-form';
    $form['#attributes']['class'][] = 'mel-venue-form';
    $form['#attributes']['class'][] = 'mel-venue-enrichment-form';
    $form['#attached']['library'][] = 'myeventlane_venue/quick_create';
    $form['#attached']['library'][] = 'myeventlane_location/address_autocomplete';
    $form['#attached']['drupalSettings']['myeventlaneVenueEnrichment'] = [
      'suggestionsUrl' => Url::fromRoute('myeventlane_venue.suggestions')->toString(),
      'currentVenueId' => $this->entity->isNew() ? NULL : (int) $this->entity->id(),
    ];
    if (!$this->entity->isNew()) {
      $form['#attached']['drupalSettings']['myeventlaneVenueWebsite'] = [
        'previewUrl' => Url::fromRoute('myeventlane_venue.website_metadata_preview', [
          'myeventlane_venue' => (int) $this->entity->id(),
        ])->toString(),
        'importImageUrl' => Url::fromRoute('myeventlane_venue.website_metadata_import_image', [
          'myeventlane_venue' => (int) $this->entity->id(),
        ])->toString(),
        'csrfToken' => $this->csrfToken->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY),
      ];
    }
    $form['#attached']['drupalSettings']['myeventlaneLocation'] = $this->locationProviderManager->getFrontendSettings();

    $form['venue_page_header'] = [
      '#type' => 'container',
      '#weight' => -40,
      '#attributes' => ['class' => ['mel-venue-form__header']],
      'eyebrow' => [
        '#markup' => '<p class="mel-venue-form__eyebrow">' . $this->t('Settings · Venues') . '</p>',
      ],
      'title' => [
        '#markup' => '<h1 class="mel-venue-form__title">' . ($this->entity->isNew() ? $this->t('Add venue') : $this->t('Edit venue')) . '</h1>',
      ],
      'intro' => [
        '#markup' => '<p class="mel-venue-form__intro">' . $this->t('Keep the venue listing accurate so event guests know where they are going.') . '</p>',
      ],
    ];

    $form['venue_lookup'] = [
      '#type' => 'container',
      '#weight' => -30,
      '#attributes' => ['data-mel-address' => 'primary_address'],
    ];
    $form['venue_lookup']['venue_search'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search address'),
      '#maxlength' => 255,
      '#attributes' => [
        'placeholder' => $this->t('Start typing a venue name or address...'),
        'class' => ['mel-input', 'myeventlane-location-address-search'],
        'data-address-search' => 'true',
        'autocomplete' => 'off',
      ],
      '#description' => $this->t('Choose a result to update the address and review any public details. Nothing else is added unless you choose Use.'),
    ];

    $this->addWidgetClass($form, 'name', 'myeventlane-venue-name-field');
    $this->addWidgetClass($form, 'primary_address', 'mel-venue-address-field');
    foreach (['website', 'phone', 'email', 'facebook', 'instagram', 'twitter', 'linkedin', 'youtube', 'tiktok'] as $field_name) {
      $this->addWidgetClass($form, $field_name, 'mel-input');
      $this->addWidgetAttribute($form, $field_name, 'data-enrichment-field', $field_name);
    }
    $this->addWidgetClass($form, 'name', 'mel-input');
    $this->addWidgetClass($form, 'primary_address', 'mel-input');

    if (isset($form['uid'])) {
      $form['uid']['#access'] = $this->account->hasPermission('administer myeventlane venues');
    }
    if (isset($form['image_media'])) {
      $form['venue_lookup']['#prefix'] = $this->sectionOpen(
        'venue-details',
        $this->t('Venue details'),
        $this->t('Set the public name, visibility, description and main image.'),
      );
      $form['image_media']['#suffix'] = ($form['image_media']['#suffix'] ?? '') . '</div></section>';
    }
    if (isset($form['primary_address'], $form['email'])) {
      $form['primary_address']['#prefix'] = $this->sectionOpen(
        'location-contact',
        $this->t('Location and contact'),
        $this->t('Add the address and the best official ways for guests to contact the venue.'),
      );
      $form['email']['#suffix'] = ($form['email']['#suffix'] ?? '') . '</div></section>';
    }
    if (isset($form['facebook'], $form['tiktok'])) {
      $form['facebook']['#prefix'] = $this->sectionOpen(
        'social-links',
        $this->t('Social links'),
        $this->t('Optional public profiles that help guests learn more about the venue.'),
      );
      $form['tiktok']['#suffix'] = ($form['tiktok']['#suffix'] ?? '') . '</div></section>';
    }

    $primary_location = $this->entity instanceof Venue && !$this->entity->isNew()
      ? $this->venueManager->getPrimaryLocation($this->entity)
      : NULL;
    if ($this->entity->hasField('primary_address')
      && $this->entity->get('primary_address')->isEmpty()
      && $primary_location !== NULL
      && isset($form['primary_address']['widget'][0]['value'])) {
      $form['primary_address']['widget'][0]['value']['#default_value'] = $primary_location->getAddressText();
    }

    $form['venue_lat'] = [
      '#type' => 'hidden',
      '#default_value' => $primary_location?->getLatitude(),
      '#attributes' => ['class' => ['myeventlane-location-latitude-field']],
    ];
    $form['venue_lng'] = [
      '#type' => 'hidden',
      '#default_value' => $primary_location?->getLongitude(),
      '#attributes' => ['class' => ['myeventlane-location-longitude-field']],
    ];
    $form['venue_suggestions'] = [
      '#type' => 'container',
      '#weight' => 8,
      '#attributes' => [
        'class' => ['mel-venue-suggestions'],
        'data-venue-suggestions' => 'true',
        'aria-live' => 'polite',
      ],
    ];
    $form['venue_suggestions']['prompt'] = [
      '#markup' => '<p class="mel-venue-suggestions__prompt">' . $this->t('Search for this venue to check for public details you can review.') . '</p>',
    ];
    if (!$this->entity->isNew()) {
      $form['website_metadata_review'] = [
        '#type' => 'container',
        '#weight' => 10.5,
        '#attributes' => [
          'class' => ['mel-venue-website-review'],
          'data-venue-website-review' => 'true',
        ],
        'title' => [
          '#markup' => '<h3 class="mel-venue-website-review__title">' . $this->t('Website details') . '</h3>',
        ],
        'help' => [
          '#markup' => '<p class="mel-venue-website-review__help">' . $this->t('Preview the official website’s description and image. Nothing is copied until you choose what to use.') . '</p>',
        ],
        'preview' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => $this->t('Preview website details'),
          '#attributes' => [
            'type' => 'button',
            'class' => ['mel-btn', 'mel-btn--secondary'],
            'data-venue-website-preview' => 'true',
          ],
        ],
        'status' => [
          '#markup' => '<div class="mel-venue-website-review__status" data-venue-website-status aria-live="polite"></div>',
        ],
        'candidate' => [
          '#markup' => '<div class="mel-venue-website-review__candidate" data-venue-website-candidate hidden></div>',
        ],
      ];
      $form['website_metadata_accept_description'] = [
        '#type' => 'hidden',
        '#default_value' => '0',
        '#attributes' => ['data-website-metadata-accept-description' => 'true'],
      ];
    }
    $form['overture_source_id'] = [
      '#type' => 'hidden',
      '#attributes' => ['data-overture-source-id' => 'true'],
    ];
    $form['overture_accepted_fields'] = [
      '#type' => 'hidden',
      '#attributes' => ['data-overture-accepted-fields' => 'true'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $original_media_id = $this->entity->hasField('image_media') && !$this->entity->get('image_media')->isEmpty()
      ? (int) $this->entity->get('image_media')->target_id
      : 0;
    $entity = parent::validateForm($form, $form_state);
    $source_id = trim((string) $form_state->getValue('overture_source_id'));
    $accepted_fields = $this->acceptedFields((string) $form_state->getValue('overture_accepted_fields'));
    if ($source_id !== '' && $accepted_fields !== [] && $this->overtureRepository->load($source_id) === NULL) {
      $form_state->setErrorByName('overture_source_id', $this->t('Those venue suggestions are no longer available. Search again or enter the details manually.'));
    }
    $address = $entity->hasField('primary_address')
      ? trim((string) $entity->get('primary_address')->value)
      : '';
    $duplicate = $this->duplicateGuard->findDuplicate(
      $entity instanceof Venue ? $entity->getName() : '',
      $address,
      $this->coordinate($form_state->getValue('venue_lat'), -90.0, 90.0),
      $this->coordinate($form_state->getValue('venue_lng'), -180.0, 180.0),
      $entity->isNew() ? NULL : (int) $entity->id(),
      $source_id,
    );
    if ($duplicate instanceof Venue) {
      $form_state->setErrorByName('name', $this->t(
        'This venue already exists as “@venue”. Use the existing venue instead of creating another.',
        ['@venue' => $duplicate->getName()],
      ));
    }
    if (!$entity->hasField('image_media') || $entity->get('image_media')->isEmpty()) {
      return $entity;
    }

    $media = $entity->get('image_media')->entity;
    $selected_media_id = $media instanceof MediaInterface ? (int) $media->id() : 0;
    $is_unchanged_selection = $selected_media_id > 0 && $selected_media_id === $original_media_id;
    if (!$is_unchanged_selection
      && (!$media instanceof MediaInterface || !$this->organiserMediaAccess->canSelect($media))) {
      if (isset($form['image_media'])) {
        $form_state->setError(
          $form['image_media'],
          $this->t('Choose a venue image uploaded by your organiser account.'),
        );
      }
      else {
        $form_state->setErrorByName(
          'image_media',
          $this->t('Choose a venue image uploaded by your organiser account.'),
        );
      }
    }

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);

    $actions['submit']['#attributes']['class'][] = 'mel-btn';
    $actions['submit']['#attributes']['class'][] = 'mel-btn--primary';
    $actions['#attributes']['class'][] = 'mel-venue-form__actions';

    // Add cancel link - use entity collection as safe fallback.
    $cancel_url = $this->getCancelUrl();
    $actions['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $cancel_url,
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn--secondary'],
      ],
      '#weight' => 10,
    ];

    return $actions;
  }

  /**
   * Gets the cancel URL, with fallback for route availability.
   *
   * @return \Drupal\Core\Url
   *   The cancel URL.
   */
  protected function getCancelUrl(): Url {
    try {
      return Url::fromRoute('myeventlane_venue.vendor_venues');
    }
    catch (\Exception $e) {
      // Fallback to entity collection if vendor route not available.
      return Url::fromRoute('entity.myeventlane_venue.collection');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $entity = $this->entity;
    assert($entity instanceof Venue);
    $this->applyEnrichmentProvenance($entity, $form_state);
    $this->applyWebsiteDescriptionProvenance($entity, $form_state);
    if ($entity->isNew()) {
      $address = trim((string) ($entity->get('primary_address')->value ?? ''));
      try {
        $status = $this->venueManager->guardVenueCreation(
          [
            'name' => $entity->getName(),
            'enrichment_source_id' => (string) ($entity->get('enrichment_source_id')->value ?? ''),
          ],
          [
            'address_text' => $address,
            'lat' => $form_state->getValue('venue_lat'),
            'lng' => $form_state->getValue('venue_lng'),
          ],
          (int) $entity->getOwnerId(),
          fn (): int => parent::save($form, $form_state),
        );
      }
      catch (DuplicateVenueException $e) {
        $this->messenger()->addWarning($this->t(
          'Venue “@venue” already exists. No duplicate was created.',
          ['@venue' => $e->getDuplicateVenue()->getName()],
        ));
        $form_state->setRedirectUrl($this->getCancelUrl());
        return SAVED_UPDATED;
      }
      catch (\RuntimeException) {
        $this->messenger()->addError($this->t(
          'This venue is already being created in another request. Wait a moment, then check your venue list.',
        ));
        $form_state->setRedirectUrl($this->getCancelUrl());
        return SAVED_UPDATED;
      }
    }
    else {
      $status = parent::save($form, $form_state);
    }

    $address = trim((string) ($entity->get('primary_address')->value ?? ''));
    $this->venueManager->syncPrimaryLocation(
      $entity,
      $address,
      $this->coordinate($form_state->getValue('venue_lat'), -90.0, 90.0),
      $this->coordinate($form_state->getValue('venue_lng'), -180.0, 180.0),
    );

    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Venue "@name" has been created.', [
        '@name' => $entity->label(),
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('Venue "@name" has been updated.', [
        '@name' => $entity->label(),
      ]));
    }

    // Redirect with fallback for route availability.
    $form_state->setRedirectUrl($this->getCancelUrl());

    return $status;
  }

  /**
   * Adds a CSS class to a base-field text widget when it is available.
   */
  private function addWidgetClass(array &$form, string $field_name, string $class): void {
    if (isset($form[$field_name]['widget'][0]['value'])) {
      $form[$field_name]['widget'][0]['value']['#attributes']['class'][] = $class;
    }
  }

  /**
   * Adds a data attribute to a base-field text widget when it is available.
   */
  private function addWidgetAttribute(array &$form, string $field_name, string $attribute, string $value): void {
    if (isset($form[$field_name]['widget'][0]['value'])) {
      $form[$field_name]['widget'][0]['value']['#attributes'][$attribute] = $value;
    }
  }

  /**
   * Opens a visual form card without changing Drupal field value parents.
   */
  private function sectionOpen(string $modifier, mixed $title, mixed $description): string {
    return sprintf(
      '<section class="mel-venue-form__section mel-venue-form__section--%s"><header class="mel-venue-form__section-header"><h2 class="mel-venue-form__section-title">%s</h2><p class="mel-venue-form__section-intro">%s</p></header><div class="mel-venue-form__section-body">',
      $modifier,
      $title,
      $description,
    );
  }

  /**
   * Records description provenance only when the approved preview is unchanged.
   */
  private function applyWebsiteDescriptionProvenance(Venue $venue, FormStateInterface $form_state): void {
    if ((string) $form_state->getValue('website_metadata_accept_description') !== '1'
      || $venue->isNew()
      || !$venue->hasField('website_metadata_accepted_fields')) {
      return;
    }

    $candidate = $this->tempStoreFactory
      ->get(VenueWebsiteMetadataController::TEMPSTORE_COLLECTION)
      ->get('venue:' . (int) $venue->id());
    if (!is_array($candidate)) {
      return;
    }
    $fetched = (int) ($candidate['fetched_at'] ?? 0);
    $website = trim((string) $venue->get('website')->value);
    $candidate_website = trim((string) ($candidate['website'] ?? ''));
    $candidate_description = $this->normaliseDescription((string) ($candidate['description'] ?? ''));
    $venue_description = $this->normaliseDescription((string) ($venue->get('description')->value ?? ''));
    if ($fetched <= 0
      || ($this->time->getRequestTime() - $fetched) > VenueWebsiteMetadataController::PREVIEW_TTL
      || $website === ''
      || !hash_equals($website, $candidate_website)
      || $candidate_description === ''
      || !hash_equals($candidate_description, $venue_description)) {
      return;
    }

    $accepted = json_decode((string) $venue->get('website_metadata_accepted_fields')->value, TRUE);
    $accepted = is_array($accepted) ? $accepted : [];
    $accepted['description'] = [
      'source' => (string) ($candidate['source_url'] ?? $website),
      'hash' => hash('sha256', $candidate_description),
      'accepted' => $this->time->getRequestTime(),
    ];
    $venue->set('website_metadata_source_url', (string) ($candidate['source_url'] ?? $website));
    $venue->set('website_metadata_checked', $fetched);
    $venue->set('website_metadata_accepted_fields', json_encode($accepted, JSON_THROW_ON_ERROR));
  }

  /**
   * Normalises rich-text storage for comparison with plain website metadata.
   */
  private function normaliseDescription(string $value): string {
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', $value));
  }

  /**
   * Stores provenance only for fields the organiser used unchanged.
   */
  private function applyEnrichmentProvenance(Venue $venue, FormStateInterface $form_state): void {
    $source_id = trim((string) $form_state->getValue('overture_source_id'));
    if ($source_id === '') {
      return;
    }

    $accepted_fields = $this->verifiedAcceptedFields(
      $venue,
      $source_id,
      (string) $form_state->getValue('overture_accepted_fields'),
    );
    if ($accepted_fields === []) {
      $provenance_fields = [
        'enrichment_source',
        'enrichment_source_id',
        'enrichment_checked',
        'enrichment_accepted_fields',
        'organiser_verified',
      ];
      foreach ($provenance_fields as $field_name) {
        $venue->set($field_name, NULL);
      }
      return;
    }

    $request_time = $this->time->getRequestTime();
    $venue->set('enrichment_source', 'overture');
    $venue->set('enrichment_source_id', $source_id);
    $venue->set('enrichment_checked', $request_time);
    $venue->set('enrichment_accepted_fields', json_encode($accepted_fields, JSON_THROW_ON_ERROR));
    $venue->set('organiser_verified', $request_time);
  }

  /**
   * Returns the allow-listed enrichment fields from the review control.
   *
   * @return string[]
   *   Accepted enrichment field names.
   */
  private function acceptedFields(string $json): array {
    $values = json_decode($json, TRUE);
    if (!is_array($values)) {
      return [];
    }
    $allowed = [
      'name',
      'address',
      'website',
      'phone',
      'email',
      'facebook',
      'instagram',
      'twitter',
      'linkedin',
      'youtube',
      'tiktok',
    ];
    return array_values(array_unique(array_intersect($allowed, array_filter($values, 'is_string'))));
  }

  /**
   * Verifies that accepted values still match the local Overture source.
   *
   * @return string[]
   *   Verified field names.
   */
  private function verifiedAcceptedFields(Venue $venue, string $source_id, string $accepted_json): array {
    $candidate = $this->overtureRepository->load($source_id);
    if ($candidate === NULL) {
      return [];
    }
    $source_values = [
      'name' => $candidate['name'] ?? '',
      'address' => $candidate['address'] ?? '',
      'website' => $candidate['website'] ?? '',
      'phone' => $candidate['phone'] ?? '',
      'email' => $candidate['email'] ?? '',
    ] + ($candidate['socials'] ?? []);
    $venue_values = [
      'name' => $venue->getName(),
      'address' => (string) ($venue->get('primary_address')->value ?? ''),
      'website' => (string) ($venue->get('website')->value ?? ''),
      'phone' => (string) ($venue->get('phone')->value ?? ''),
      'email' => (string) ($venue->get('email')->value ?? ''),
      'facebook' => (string) ($venue->get('facebook')->value ?? ''),
      'instagram' => (string) ($venue->get('instagram')->value ?? ''),
      'twitter' => (string) ($venue->get('twitter')->value ?? ''),
      'linkedin' => (string) ($venue->get('linkedin')->value ?? ''),
      'youtube' => (string) ($venue->get('youtube')->value ?? ''),
      'tiktok' => (string) ($venue->get('tiktok')->value ?? ''),
    ];

    $verified = [];
    foreach ($this->acceptedFields($accepted_json) as $field_name) {
      if (trim($venue_values[$field_name]) === trim((string) ($source_values[$field_name] ?? ''))) {
        $verified[] = $field_name;
      }
    }
    return $verified;
  }

  /**
   * Returns a valid coordinate submitted by the configured map provider.
   */
  private function coordinate(mixed $value, float $minimum, float $maximum): ?float {
    if (!is_numeric($value)) {
      return NULL;
    }
    $coordinate = (float) $value;
    return $coordinate >= $minimum && $coordinate <= $maximum ? $coordinate : NULL;
  }

}
