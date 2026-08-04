<?php

declare(strict_types=1);

namespace Drupal\myeventlane_location\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\myeventlane_location\Service\LocationProviderManager;
use Drupal\myeventlane_location\Service\MapKitTokenGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for MyEventLane location settings.
 */
final class LocationSettingsForm extends ConfigFormBase {

  /**
   * Constructs a LocationSettingsForm.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly MapKitTokenGenerator $tokenGenerator,
    private readonly LocationProviderManager $providerManager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('myeventlane_location.mapkit_token_generator'),
      $container->get('myeventlane_location.provider_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_location_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['myeventlane_location.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('myeventlane_location.settings');

    $form['status'] = [
      '#type' => 'details',
      '#title' => $this->t('Integration status'),
      '#open' => TRUE,
      '#weight' => -10,
    ];

    $form['status']['summary'] = [
      '#theme' => 'item_list',
      '#items' => $this->buildStatusItems(),
    ];

    $form['provider'] = [
      '#type' => 'details',
      '#title' => $this->t('Map provider'),
      '#open' => TRUE,
    ];

    $form['provider']['default_provider'] = [
      '#type' => 'radios',
      '#title' => $this->t('Default map provider'),
      '#description' => $this->t('Choose the default map provider for address autocomplete and map rendering.'),
      '#options' => [
        'google_maps' => $this->t('Google Maps'),
        'apple_maps' => $this->t('Apple Maps (MapKit JS)'),
      ],
      '#default_value' => $config->get('default_provider') ?? 'google_maps',
      '#required' => TRUE,
    ];

    $form['google_maps'] = [
      '#type' => 'details',
      '#title' => $this->t('Google Maps configuration'),
      '#open' => TRUE,
    ];

    $form['google_maps']['google_maps_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Google Maps API key'),
      '#description' => $this->t('Your Google Maps API key. Get one at <a href="https://console.cloud.google.com/google/maps-apis" target="_blank">Google Cloud Console</a>. Required for Places API autocomplete and map rendering.'),
      '#default_value' => $config->get('google_maps_api_key') ?? '',
      '#maxlength' => 255,
    ];

    $form['apple_maps'] = [
      '#type' => 'details',
      '#title' => $this->t('Apple Maps (MapKit JS) configuration'),
      '#open' => TRUE,
    ];

    $form['apple_maps']['apple_maps_team_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Apple Team ID'),
      '#description' => $this->t('Your Apple Developer Team ID (10-character identifier).'),
      '#default_value' => $config->get('apple_maps_team_id') ?? '',
      '#maxlength' => 10,
    ];

    $form['apple_maps']['apple_maps_key_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('MapKit JS Key ID'),
      '#description' => $this->t('The Key ID from your MapKit JS key (created in Apple Developer portal).'),
      '#default_value' => $config->get('apple_maps_key_id') ?? '',
      '#maxlength' => 255,
    ];

    $form['apple_maps']['apple_maps_private_key'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Private key (PEM format)'),
      '#description' => $this->t('The private key file content in PEM format (-----BEGIN PRIVATE KEY----- …). For security, prefer settings.php constants: MYEVENTLANE_APPLE_MAPS_TEAM_ID, MYEVENTLANE_APPLE_MAPS_KEY_ID, MYEVENTLANE_APPLE_MAPS_PRIVATE_KEY, and optionally MYEVENTLANE_APPLE_MAPS_ORIGIN.'),
      '#default_value' => $config->get('apple_maps_private_key') ?? '',
      '#rows' => 10,
    ];

    $form['security_note'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning"><p><strong>' . $this->t('Security note:') . '</strong> ' . $this->t('API keys and private keys should ideally be stored in settings.php or environment variables, not in the database.') . '</p></div>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('myeventlane_location.settings')
      ->set('default_provider', $form_state->getValue('default_provider'))
      ->set('google_maps_api_key', $form_state->getValue('google_maps_api_key'))
      ->set('apple_maps_team_id', $form_state->getValue('apple_maps_team_id'))
      ->set('apple_maps_key_id', $form_state->getValue('apple_maps_key_id'))
      ->set('apple_maps_private_key', $form_state->getValue('apple_maps_private_key'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Builds human-readable status lines for the settings form.
   *
   * @return array<int, string|\Drupal\Component\Render\MarkupInterface>
   *   Status list items.
   */
  private function buildStatusItems(): array {
    $items = [];
    $provider = $this->providerManager->getDefaultProvider();
    $items[] = $this->t('Default provider: @provider', [
      '@provider' => $provider === 'apple_maps' ? 'Apple Maps (MapKit JS)' : 'Google Maps',
    ]);

    if ($provider === 'google_maps') {
      $items[] = $this->providerManager->isProviderConfigured('google_maps')
        ? $this->t('Google Maps API key: configured')
        : $this->t('Google Maps API key: missing');
      return $items;
    }

    if (!$this->tokenGenerator->isConfigured()) {
      $items[] = $this->t('Apple Maps credentials: incomplete (need Team ID, Key ID, and private key)');
      return $items;
    }

    $token = $this->tokenGenerator->generateToken();
    if ($token === '') {
      $items[] = $this->t('Apple Maps credentials: present, but JWT signing failed. Check watchdog (myeventlane_location) and PEM key format.');
      return $items;
    }

    $parts = explode('.', $token);
    $items[] = $this->t('Apple Maps MapKit JWT: generated successfully (@segments segments, @length chars)', [
      '@segments' => (string) count($parts),
      '@length' => (string) strlen($token),
    ]);

    return $items;
  }

}
