<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\myeventlane_venue\Entity\Venue;
use Drupal\myeventlane_venue\Exception\DuplicateVenueException;
use Drupal\myeventlane_venue\Service\OverturePlaceRepository;
use Drupal\myeventlane_venue\Service\VenueDuplicateGuard;
use Drupal\myeventlane_venue\Service\VenueManager;
use Drupal\myeventlane_location\Service\LocationProviderManager;
use Drupal\Component\Datetime\TimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Quick create venue modal form.
 *
 * Used in Event Wizard (When/Where) and Vendor Settings → Venues.
 * Creates a venue with primary location in a single step via AJAX.
 */
class VenueQuickCreateForm extends FormBase {

  /**
   * The venue manager.
   */
  protected VenueManager $venueManager;

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs the form.
   */
  public function __construct(
    VenueManager $venue_manager,
    RequestStack $request_stack,
    LoggerInterface $logger,
    protected OverturePlaceRepository $overtureRepository,
    protected TimeInterface $time,
    protected LocationProviderManager $locationProviderManager,
    protected VenueDuplicateGuard $duplicateGuard,
  ) {
    $this->venueManager = $venue_manager;
    $this->setRequestStack($request_stack);
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_venue.manager'),
      $container->get('request_stack'),
      $container->get('logger.factory')->get('myeventlane_venue'),
      $container->get('myeventlane_venue.overture_repository'),
      $container->get('datetime.time'),
      $container->get('myeventlane_location.provider_manager'),
      $container->get('myeventlane_venue.duplicate_guard'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_venue_quick_create_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Get query parameters for pre-populating from the wizard.
    $request = $this->requestStack->getCurrentRequest();
    $prefillName = $request?->query->get('name', '') ?? '';
    $prefillAddress = $request?->query->get('address', '') ?? '';
    $prefillLat = $request?->query->get('lat', '') ?? '';
    $prefillLng = $request?->query->get('lng', '') ?? '';

    $form['#prefix'] = '<div id="venue-quick-create-form-wrapper" class="mel-venue-quick-create mel-venue-enrichment-form">';
    $form['#suffix'] = '</div>';

    // Attach libraries for dialog and location autocomplete.
    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
    $form['#attached']['library'][] = 'myeventlane_venue/quick_create';
    $form['#attached']['drupalSettings']['myeventlaneVenueEnrichment'] = [
      'suggestionsUrl' => Url::fromRoute('myeventlane_venue.suggestions')->toString(),
    ];

    // Attach the location module's configured Apple or Google autocomplete.
    $form['#attached']['library'][] = 'myeventlane_location/address_autocomplete';
    $form['#attached']['drupalSettings']['myeventlaneLocation'] = $this->locationProviderManager->getFrontendSettings();

    // Search field - integrates with myeventlane_location autocomplete.
    // User types venue/place name, system searches via Google/Apple Places.
    // Hidden if already pre-filled from wizard.
    $form['venue_lookup'] = [
      '#type' => 'container',
      '#attributes' => [
        'data-mel-address' => 'field_location',
      ],
    ];
    $form['venue_lookup']['venue_search'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search for venue'),
      '#maxlength' => 255,
      '#default_value' => $prefillName ?: '',
      '#attributes' => [
        'placeholder' => $this->t('Start typing venue name or address...'),
        'class' => [
          'mel-input',
          'myeventlane-location-address-search',
        ],
        'data-address-search' => 'true',
        'autocomplete' => 'off',
      ],
      '#description' => $this->t('Search for a venue or address. The details will be auto-filled below.'),
      '#access' => empty($prefillName) && empty($prefillAddress),
    ];

    // Venue name - auto-filled from search, but editable.
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Venue name'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#default_value' => $prefillName,
      '#attributes' => [
        'placeholder' => $this->t('e.g., Sydney Convention Centre'),
        'class' => ['mel-input', 'myeventlane-venue-name-field'],
      ],
    ];

    // Visibility.
    $form['visibility'] = [
      '#type' => 'radios',
      '#title' => $this->t('Visibility'),
      '#options' => [
        Venue::VISIBILITY_SHARED => $this->t('Shared by link'),
        Venue::VISIBILITY_PUBLIC => $this->t('Public directory'),
      ],
      '#default_value' => Venue::VISIBILITY_SHARED,
      '#description' => $this->t('Shared venues can only be accessed via a share link. Public venues appear in the venue directory.'),
    ];

    // Location section.
    $form['location'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Location details'),
      '#attributes' => [
        'class' => ['mel-venue-location-details'],
      ],
    ];

    $form['location']['location_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Location name'),
      '#description' => $this->t('Optional. Leave blank to use venue name.'),
      '#maxlength' => 255,
      '#attributes' => [
        'placeholder' => $this->t('e.g., Main Hall'),
        'class' => ['mel-input'],
      ],
    ];

    // Address field - auto-filled from search, but editable.
    $form['location']['address_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Address'),
      '#required' => TRUE,
      '#maxlength' => 500,
      '#default_value' => $prefillAddress,
      '#attributes' => [
        'placeholder' => $this->t('Full street address'),
        'class' => ['mel-input', 'mel-venue-address-field'],
      ],
    ];

    // Hidden fields for coordinates (populated by JS from location search).
    $form['location']['lat'] = [
      '#type' => 'hidden',
      '#default_value' => $prefillLat,
      '#attributes' => [
        'class' => ['myeventlane-location-latitude-field'],
      ],
    ];

    $form['location']['lng'] = [
      '#type' => 'hidden',
      '#default_value' => $prefillLng,
      '#attributes' => [
        'class' => ['myeventlane-location-longitude-field'],
      ],
    ];

    $form['venue_suggestions'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-venue-suggestions'],
        'data-venue-suggestions' => 'true',
        'aria-live' => 'polite',
      ],
    ];
    $form['venue_suggestions']['prompt'] = [
      '#markup' => '<p class="mel-venue-suggestions__prompt">' . $this->t('Choose a search result to check for an existing venue and any public details you can review.') . '</p>',
    ];

    $form['details'] = [
      '#type' => 'details',
      '#title' => $this->t('Venue contact details'),
      '#description' => $this->t('Optional. Review any suggested information before creating the venue.'),
      '#open' => FALSE,
      '#attributes' => ['class' => ['mel-venue-contact-details']],
    ];
    $form['details']['website'] = $this->optionalUrlField($this->t('Website'));
    $form['details']['phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Public phone'),
      '#maxlength' => 50,
      '#attributes' => ['class' => ['mel-input'], 'data-enrichment-field' => 'phone'],
    ];
    $form['details']['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Public email'),
      '#maxlength' => 255,
      '#attributes' => ['class' => ['mel-input'], 'data-enrichment-field' => 'email'],
    ];
    foreach ([
      'facebook' => $this->t('Facebook'),
      'instagram' => $this->t('Instagram'),
      'twitter' => $this->t('X (Twitter)'),
      'linkedin' => $this->t('LinkedIn'),
      'youtube' => $this->t('YouTube'),
      'tiktok' => $this->t('TikTok'),
    ] as $field_name => $label) {
      $form['details'][$field_name] = $this->optionalUrlField($label, $field_name);
    }

    $form['overture_source_id'] = [
      '#type' => 'hidden',
      '#attributes' => ['data-overture-source-id' => 'true'],
    ];
    $form['overture_accepted_fields'] = [
      '#type' => 'hidden',
      '#attributes' => ['data-overture-accepted-fields' => 'true'],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    // Submit button uses #ajax only — do NOT add 'use-ajax-submit' class.
    // Combining both creates duplicate AJAX submissions where the
    // use-ajax-submit mechanism submits to the form action URL without
    // _wrapper_format, causing a parsererror from a full HTML response.
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create venue'),
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn--primary'],
      ],
      '#ajax' => [
        'callback' => '::ajaxSubmit',
        'wrapper' => 'venue-quick-create-form-wrapper',
      ],
    ];

    $form['actions']['cancel'] = [
      '#type' => 'button',
      '#value' => $this->t('Cancel'),
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn--secondary', 'dialog-cancel'],
      ],
      '#ajax' => [
        'callback' => '::ajaxCancel',
      ],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $name = trim((string) $form_state->getValue('name'));
    if (empty($name)) {
      $form_state->setErrorByName('name', $this->t('Venue name is required.'));
    }

    $address = trim((string) $form_state->getValue('address_text'));
    if (empty($address)) {
      $form_state->setErrorByName('address_text', $this->t('Address is required.'));
    }

    $source_id = trim((string) $form_state->getValue('overture_source_id'));
    $accepted_fields = $this->acceptedFields((string) $form_state->getValue('overture_accepted_fields'));
    if ($source_id !== '' && $accepted_fields !== [] && $this->overtureRepository->load($source_id) === NULL) {
      $form_state->setErrorByName('overture_source_id', $this->t('Those venue suggestions are no longer available. Search again or enter the details manually.'));
    }
    $duplicate = $this->duplicateGuard->findDuplicate(
      $name,
      $address,
      $this->coordinate($form_state->getValue('lat'), -90.0, 90.0),
      $this->coordinate($form_state->getValue('lng'), -180.0, 180.0),
      NULL,
      $source_id,
    );
    if ($duplicate instanceof Venue) {
      $form_state->setErrorByName('name', $this->t(
        'This venue already exists as “@venue”. Choose Use existing venue instead.',
        ['@venue' => $duplicate->getName()],
      ));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Handled by ajaxSubmit.
  }

  /**
   * AJAX submit callback.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   AJAX response.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    if ($form_state->hasAnyErrors()) {
      // Re-render the form with validation errors inside the wrapper.
      $response->addCommand(new ReplaceCommand(
        '#venue-quick-create-form-wrapper',
        $form,
      ));
      return $response;
    }

    $venueData = [
      'name' => trim((string) $form_state->getValue('name')),
      'visibility' => $form_state->getValue('visibility'),
      'website' => trim((string) $form_state->getValue('website')),
      'phone' => trim((string) $form_state->getValue('phone')),
      'email' => trim((string) $form_state->getValue('email')),
      'facebook' => trim((string) $form_state->getValue('facebook')),
      'instagram' => trim((string) $form_state->getValue('instagram')),
      'twitter' => trim((string) $form_state->getValue('twitter')),
      'linkedin' => trim((string) $form_state->getValue('linkedin')),
      'youtube' => trim((string) $form_state->getValue('youtube')),
      'tiktok' => trim((string) $form_state->getValue('tiktok')),
    ];

    $source_id = trim((string) $form_state->getValue('overture_source_id'));
    $accepted_fields = $this->verifiedAcceptedFields($form_state, $source_id);
    if ($accepted_fields !== [] && $source_id !== '') {
      $venueData += [
        'enrichment_source' => 'overture',
        'enrichment_source_id' => $source_id,
        'enrichment_checked' => $this->time->getRequestTime(),
        'enrichment_accepted_fields' => json_encode($accepted_fields, JSON_THROW_ON_ERROR),
        'organiser_verified' => $this->time->getRequestTime(),
      ];
    }

    $locationData = [
      'title' => trim((string) $form_state->getValue('location_title')) ?: $venueData['name'],
      'address_text' => trim((string) $form_state->getValue('address_text')),
      'lat' => $form_state->getValue('lat') ?: NULL,
      'lng' => $form_state->getValue('lng') ?: NULL,
    ];

    try {
      $venue = $this->venueManager->createVenueWithLocation(
        $venueData,
        $locationData,
        (int) $this->currentUser()->id()
      );

      // Get the primary location that was just created.
      $locations = $this->venueManager->getLocations($venue);
      $primaryLocation = NULL;
      $locationId = NULL;

      foreach ($locations as $location) {
        if ($location->isPrimary()) {
          $primaryLocation = $location;
          $locationId = $location->id();
          break;
        }
      }

      // If no primary, take the first location.
      if (!$primaryLocation && !empty($locations)) {
        $primaryLocation = reset($locations);
        $locationId = $primaryLocation->id();
      }

      // Build address data from the location's address_text for JS to
      // populate field_location. VenueLocation stores address as a plain
      // text string, so we parse it into components.
      $addressData = $this->parseAddressForJs(
        $locationData['address_text'],
        $primaryLocation,
      );

      // Close modal.
      $response->addCommand(new CloseDialogCommand());

      // Success message.
      $response->addCommand(new MessageCommand(
        $this->t('Venue "@name" created successfully.', ['@name' => $venue->getName()]),
        NULL,
        ['type' => 'status']
      ));

      // Dispatch custom event with venue data for parent forms.
      $response->addCommand(new InvokeCommand(
        'body',
        'trigger',
        [
          'venueCreated',
          [
            'venue_id' => $venue->id(),
            'venue_name' => $venue->getName(),
            'location_id' => $locationId,
            'address' => $addressData,
            'latitude' => $primaryLocation?->getLatitude(),
            'longitude' => $primaryLocation?->getLongitude(),
          ],
        ]
      ));

      $this->logger->notice('Quick-created venue @name (ID: @id) with location @loc_id', [
        '@name' => $venue->getName(),
        '@id' => $venue->id(),
        '@loc_id' => $locationId,
      ]);
    }
    catch (DuplicateVenueException $e) {
      $duplicate = $e->getDuplicateVenue();
      $response->addCommand(new MessageCommand(
        $this->t('This venue already exists as “@venue”. Choose the existing venue instead.', [
          '@venue' => $duplicate->getName(),
        ]),
        NULL,
        ['type' => 'warning']
      ));
    }
    catch (\Exception $e) {
      $this->logger->error('Quick-create venue failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      $response->addCommand(new MessageCommand(
        $this->t('Error creating venue: @message', ['@message' => $e->getMessage()]),
        NULL,
        ['type' => 'error']
      ));
    }

    return $response;
  }

  /**
   * AJAX cancel callback.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   AJAX response.
   */
  public function ajaxCancel(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new CloseDialogCommand());
    return $response;
  }

  /**
   * Builds a reusable optional URL field.
   */
  private function optionalUrlField(TranslatableMarkup $label, string $field_name = 'website'): array {
    return [
      '#type' => 'url',
      '#title' => $label,
      '#maxlength' => 255,
      '#attributes' => [
        'class' => ['mel-input'],
        'data-enrichment-field' => $field_name,
      ],
    ];
  }

  /**
   * Returns only fields which the review interface is allowed to attribute.
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
   * Keeps provenance only while the submitted value still matches its source.
   *
   * Organisers can edit every field, but an edited value must no longer be
   * recorded as an exact Overture suggestion.
   *
   * @return string[]
   *   Accepted fields whose submitted values still match the source.
   */
  private function verifiedAcceptedFields(FormStateInterface $form_state, string $source_id): array {
    if ($source_id === '') {
      return [];
    }
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

    $verified = [];
    foreach ($this->acceptedFields((string) $form_state->getValue('overture_accepted_fields')) as $field_name) {
      $form_field = $field_name === 'address' ? 'address_text' : $field_name;
      if (trim((string) $form_state->getValue($form_field)) === trim((string) ($source_values[$field_name] ?? ''))) {
        $verified[] = $field_name;
      }
    }
    return $verified;
  }

  /**
   * Parses the address text into structured data for JavaScript.
   *
   * VenueLocation stores address as a simple text string (address_text),
   * not a composite address field. This method parses it into the structure
   * that the event wizard JS expects to populate field_location.
   *
   * @param string $address_text
   *   The raw address text.
   * @param \Drupal\myeventlane_venue\Entity\VenueLocation|null $location
   *   The venue location entity (for additional data).
   *
   * @return array
   *   Structured address data with keys: country_code, address_line1,
   *   address_line2, locality, administrative_area, postal_code.
   */
  private function parseAddressForJs(string $address_text, $location): array {
    $addressData = [
      'country_code' => 'AU',
      'address_line1' => $address_text,
      'address_line2' => '',
      'locality' => '',
      'administrative_area' => '',
      'postal_code' => '',
    ];

    if (empty($address_text)) {
      return $addressData;
    }

    // Try to parse Australian address format:
    // "Street, Suburb State Postcode, Australia"
    // Or "Street, Suburb State Postcode".
    $parts = array_map('trim', explode(',', $address_text));

    if (count($parts) >= 1) {
      $addressData['address_line1'] = $parts[0];
    }

    // Check if the last part is a country name and remove it.
    $last = trim(end($parts));
    if (preg_match('/^(Australia|AU)$/i', $last)) {
      array_pop($parts);
    }

    if (count($parts) >= 2) {
      // Second-to-last part usually contains "Suburb STATE Postcode".
      $locationPart = trim(end($parts));

      // Try to match "Suburb STATE Postcode" pattern.
      if (preg_match('/^(.+?)\s+([A-Z]{2,3})\s+(\d{4})$/', $locationPart, $matches)) {
        $addressData['locality'] = trim($matches[1]);
        $addressData['administrative_area'] = $matches[2];
        $addressData['postal_code'] = $matches[3];
      }
      elseif (preg_match('/^([A-Z]{2,3})\s+(\d{4})$/', $locationPart, $matches)) {
        // Just "STATE Postcode" — locality might be in a prior part.
        $addressData['administrative_area'] = $matches[1];
        $addressData['postal_code'] = $matches[2];
        if (count($parts) >= 3) {
          $addressData['locality'] = trim($parts[count($parts) - 2]);
        }
      }
      elseif (count($parts) === 2) {
        // Just two parts: "Street, Suburb" — treat second part as locality.
        $addressData['locality'] = trim($parts[1]);
      }
    }

    return $addressData;
  }

  /**
   * Returns a valid submitted coordinate.
   */
  private function coordinate(mixed $value, float $minimum, float $maximum): ?float {
    if (!is_numeric($value)) {
      return NULL;
    }
    $coordinate = (float) $value;
    return $coordinate >= $minimum && $coordinate <= $maximum ? $coordinate : NULL;
  }

}
