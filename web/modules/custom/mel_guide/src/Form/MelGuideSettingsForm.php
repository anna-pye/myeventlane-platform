<?php

declare(strict_types=1);

namespace Drupal\mel_guide\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for MEL Guide settings.
 */
final class MelGuideSettingsForm extends ConfigFormBase {

  /**
   * Character asset keys (state machine names).
   *
   * @var list<string>
   */
  private const ASSET_KEYS = [
    'wave',
    'think',
    'celebrate',
  ];

  /**
   * Constructs MelGuideSettingsForm.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected FileUsageInterface $fileUsage,
    protected EntityTypeManagerInterface $entityTypeManager,
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
      $container->get('file.usage'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mel_guide_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['mel_guide.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('mel_guide.settings');

    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General'),
      '#open' => TRUE,
    ];

    $form['general']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable MEL Guide'),
      '#default_value' => $config->get('enabled'),
    ];

    $form['general']['debug_force_display'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Debug: force display'),
      '#description' => $this->t('Bypass route exclusions for testing. Does not bypass audience or device settings.'),
      '#default_value' => $config->get('debug_force_display'),
    ];

    $form['general']['debug_context'] = [
      '#type' => 'select',
      '#title' => $this->t('Debug context override'),
      '#description' => $this->t('Override the guide context for QA screenshots and demonstrations.'),
      '#options' => [
        '' => $this->t('None (use current route)'),
        'homepage' => $this->t('Homepage'),
        'event' => $this->t('Event page'),
        'complete' => $this->t('Checkout complete'),
      ],
      '#default_value' => $config->get('debug_context') ?? '',
      '#states' => [
        'visible' => [
          ':input[name="debug_force_display"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['devices'] = [
      '#type' => 'details',
      '#title' => $this->t('Devices'),
      '#open' => TRUE,
    ];

    $form['devices']['enabled_mobile'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show on mobile'),
      '#default_value' => $config->get('enabled_mobile'),
    ];

    $form['devices']['enabled_desktop'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show on desktop'),
      '#default_value' => $config->get('enabled_desktop'),
    ];

    $form['audience'] = [
      '#type' => 'details',
      '#title' => $this->t('Audience'),
      '#open' => TRUE,
    ];

    $form['audience']['show_for_anonymous'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show for anonymous visitors'),
      '#default_value' => $config->get('show_for_anonymous'),
    ];

    $form['audience']['show_for_authenticated'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show for authenticated users'),
      '#default_value' => $config->get('show_for_authenticated'),
    ];

    $form['audience']['show_for_customers'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show for customers'),
      '#default_value' => $config->get('show_for_customers'),
    ];

    $form['audience']['show_for_organisers'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show for organisers'),
      '#description' => $this->t('Organisers use the vendor and MEL Pro roles in Drupal.'),
      '#default_value' => $config->get('show_for_organisers'),
    ];

    $form['behaviour'] = [
      '#type' => 'details',
      '#title' => $this->t('Behaviour'),
      '#open' => TRUE,
    ];

    $form['behaviour']['appearance_delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Appearance delay'),
      '#description' => $this->t('Milliseconds before the guide appears.'),
      '#default_value' => $config->get('appearance_delay'),
      '#min' => 0,
      '#step' => 100,
      '#required' => TRUE,
    ];

    $form['behaviour']['max_messages_per_session'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum appearances per session'),
      '#default_value' => $config->get('max_messages_per_session'),
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['behaviour']['hide_days_after_dismiss'] = [
      '#type' => 'number',
      '#title' => $this->t('Hide days after dismiss'),
      '#description' => $this->t('Days to keep MEL hidden after a visitor chooses “Hide MEL”. Use 0 to hide for the current browser session only.'),
      '#default_value' => $config->get('hide_days_after_dismiss'),
      '#min' => 0,
      '#required' => TRUE,
    ];

    $form['behaviour']['position'] = [
      '#type' => 'radios',
      '#title' => $this->t('Position'),
      '#description' => $this->t('Screen corner for the guide companion.'),
      '#options' => [
        'bottom_right' => $this->t('Bottom right'),
        'bottom_left' => $this->t('Bottom left'),
      ],
      '#default_value' => $config->get('position') ?? 'bottom_right',
      '#required' => TRUE,
    ];

    $messages = $config->get('messages') ?? [];
    $assets = $config->get('assets') ?? [];
    $asset_fids = $config->get('asset_fids') ?? [];

    $form['guide_states'] = [
      '#type' => 'details',
      '#title' => $this->t('Guide states'),
      '#description' => $this->t('Message copy and character image for each guide moment. Uploaded images take precedence over fallback paths.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['guide_states']['wave'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Wave (homepage welcome)'),
    ];
    $form['guide_states']['wave']['message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Message'),
      '#default_value' => $messages['welcome'] ?? '',
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['guide_states']['wave']['upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Character image'),
      '#upload_location' => 'public://mel-guide/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'png jpg jpeg gif webp svg'],
        'FileSizeLimit' => ['fileLimit' => 2 * 1024 * 1024],
      ],
      '#default_value' => $this->fidToDefaultValue($asset_fids['wave'] ?? 0),
      '#description' => $this->t('Square PNG or WebP recommended. Desktop display is 80–96px; mobile is 64–72px.'),
    ];
    $form['guide_states']['wave']['path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Fallback path'),
      '#description' => $this->t('Used when no image is uploaded. Path relative to the Drupal web root.'),
      '#default_value' => $assets['wave'] ?? '',
      '#maxlength' => 512,
    ];

    $form['guide_states']['think'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Think (event and browse)'),
    ];
    $form['guide_states']['think']['discover_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Discover message (event page)'),
      '#default_value' => $messages['discover'] ?? '',
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['guide_states']['think']['thinking_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Thinking message (general browsing)'),
      '#default_value' => $messages['thinking'] ?? '',
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['guide_states']['think']['upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Character image'),
      '#upload_location' => 'public://mel-guide/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'png jpg jpeg gif webp svg'],
        'FileSizeLimit' => ['fileLimit' => 2 * 1024 * 1024],
      ],
      '#default_value' => $this->fidToDefaultValue($asset_fids['think'] ?? 0),
      '#description' => $this->t('Square PNG or WebP recommended. Desktop display is 80–96px; mobile is 64–72px.'),
    ];
    $form['guide_states']['think']['path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Fallback path'),
      '#description' => $this->t('Used when no image is uploaded. Path relative to the Drupal web root.'),
      '#default_value' => $assets['think'] ?? '',
      '#maxlength' => 512,
    ];

    $form['guide_states']['celebrate'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Celebrate (order complete)'),
    ];
    $form['guide_states']['celebrate']['message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Message'),
      '#default_value' => $messages['celebrate'] ?? '',
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['guide_states']['celebrate']['upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Character image'),
      '#upload_location' => 'public://mel-guide/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'png jpg jpeg gif webp svg'],
        'FileSizeLimit' => ['fileLimit' => 2 * 1024 * 1024],
      ],
      '#default_value' => $this->fidToDefaultValue($asset_fids['celebrate'] ?? 0),
      '#description' => $this->t('Square PNG or WebP recommended. Desktop display is 80–96px; mobile is 64–72px.'),
    ];
    $form['guide_states']['celebrate']['path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Fallback path'),
      '#description' => $this->t('Used when no image is uploaded. Path relative to the Drupal web root.'),
      '#default_value' => $assets['celebrate'] ?? '',
      '#maxlength' => 512,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $debug_context = (string) $form_state->getValue('debug_context');
    if (!in_array($debug_context, ['', 'homepage', 'event', 'complete'], TRUE)) {
      $form_state->setErrorByName('debug_context', $this->t('Invalid debug context override.'));
    }

    $position = (string) $form_state->getValue('position');
    if (!in_array($position, ['bottom_right', 'bottom_left'], TRUE)) {
      $form_state->setErrorByName('position', $this->t('Invalid guide position.'));
    }

    $guide_states = $form_state->getValue('guide_states');
    if (!is_array($guide_states)) {
      parent::validateForm($form, $form_state);
      return;
    }

    foreach (self::ASSET_KEYS as $key) {
      $upload = $guide_states[$key]['upload'] ?? [];
      $has_upload = is_array($upload) && !empty($upload[0]);
      $path = trim((string) ($guide_states[$key]['path'] ?? ''));
      if (!$has_upload && $path === '') {
        $form_state->setErrorByName('guide_states][' . $key . '][path', $this->t('Upload an image or provide a fallback path for @state.', [
          '@state' => $key,
        ]));
      }
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('mel_guide.settings');
    $storage = $this->entityTypeManager->getStorage('file');
    $states_input = $form_state->getValue('guide_states');
    if (!is_array($states_input)) {
      $states_input = [];
    }
    $asset_fids = [];
    $asset_paths = [];

    if (is_array($states_input)) {
      foreach (self::ASSET_KEYS as $key) {
        $upload = $states_input[$key]['upload'] ?? [];
        $new_fid = is_array($upload) && !empty($upload[0]) ? (int) $upload[0] : 0;
        $old_fid = (int) (($config->get('asset_fids') ?? [])[$key] ?? 0);

        if ($old_fid > 0 && $old_fid !== $new_fid) {
          $old_file = $storage->load($old_fid);
          if ($old_file instanceof FileInterface) {
            $this->fileUsage->delete($old_file, 'mel_guide', 'config', $key);
          }
        }

        if ($new_fid > 0) {
          $file = $storage->load($new_fid);
          if ($file instanceof FileInterface) {
            $file->setPermanent();
            $file->save();
            $this->fileUsage->add($file, 'mel_guide', 'config', $key);
          }
        }

        $asset_fids[$key] = $new_fid;
        $asset_paths[$key] = trim((string) ($states_input[$key]['path'] ?? ''));
      }
    }

    $this->config('mel_guide.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('enabled_mobile', (bool) $form_state->getValue('enabled_mobile'))
      ->set('enabled_desktop', (bool) $form_state->getValue('enabled_desktop'))
      ->set('show_for_anonymous', (bool) $form_state->getValue('show_for_anonymous'))
      ->set('show_for_authenticated', (bool) $form_state->getValue('show_for_authenticated'))
      ->set('show_for_customers', (bool) $form_state->getValue('show_for_customers'))
      ->set('show_for_organisers', (bool) $form_state->getValue('show_for_organisers'))
      ->set('appearance_delay', (int) $form_state->getValue('appearance_delay'))
      ->set('max_messages_per_session', (int) $form_state->getValue('max_messages_per_session'))
      ->set('hide_days_after_dismiss', (int) $form_state->getValue('hide_days_after_dismiss'))
      ->set('debug_force_display', (bool) $form_state->getValue('debug_force_display'))
      ->set('debug_context', (string) $form_state->getValue('debug_context'))
      ->set('position', (string) $form_state->getValue('position'))
      ->set('asset_fids', $asset_fids)
      ->set('messages', [
        'welcome' => trim((string) ($states_input['wave']['message'] ?? '')),
        'discover' => trim((string) ($states_input['think']['discover_message'] ?? '')),
        'thinking' => trim((string) ($states_input['think']['thinking_message'] ?? '')),
        'celebrate' => trim((string) ($states_input['celebrate']['message'] ?? '')),
      ])
      ->set('assets', $asset_paths)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Converts a config file ID to a managed_file default value.
   *
   * @param mixed $fid
   *   Stored file ID.
   *
   * @return list<int>
   */
  private function fidToDefaultValue(mixed $fid): array {
    $fid = (int) ($fid ?? 0);
    return $fid > 0 ? [$fid] : [];
  }

}
