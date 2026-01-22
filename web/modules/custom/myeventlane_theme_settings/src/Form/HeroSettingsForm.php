<?php

declare(strict_types=1);

namespace Drupal\myeventlane_theme_settings\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for MyEventLane theme hero images.
 *
 * Stores fids in config. Registers file usage and marks files permanent.
 * Theme consumes via preprocess; no logic in templates.
 */
final class HeroSettingsForm extends ConfigFormBase {

  /**
   * Hero config keys (form element names and config keys).
   *
   * @var string[]
   */
  private const HERO_KEYS = [
    'hero_default',
    'hero_events',
    'hero_calendar',
    'hero_category',
    'hero_search',
  ];

  /**
   * The file usage service.
   */
  private FileUsageInterface $fileUsage;

  /**
   * The entity type manager.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->fileUsage = $container->get('file.usage');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_theme_settings_hero_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['myeventlane_theme_settings.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('myeventlane_theme_settings.settings');

    $form['hero_images'] = [
      '#type' => 'details',
      '#title' => $this->t('Hero images'),
      '#open' => TRUE,
      '#description' => $this->t('Set hero images for discovery pages. Fallback order: context-specific → default → theme assets.'),
    ];

    $form['hero_images']['hero_default'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Default hero'),
      '#description' => $this->t('Used when no context-specific or fallback applies.'),
      '#default_value' => $this->fidToDefaultValue($config->get('hero_default')),
      '#upload_location' => 'public://hero/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'png jpg jpeg gif webp'],
      ],
    ];

    $form['hero_images']['hero_events'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Events page hero'),
      '#description' => $this->t('Hero for /events.'),
      '#default_value' => $this->fidToDefaultValue($config->get('hero_events')),
      '#upload_location' => 'public://hero/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'png jpg jpeg gif webp'],
      ],
    ];

    $form['hero_images']['hero_calendar'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Calendar page hero'),
      '#description' => $this->t('Hero for /calendar.'),
      '#default_value' => $this->fidToDefaultValue($config->get('hero_calendar')),
      '#upload_location' => 'public://hero/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'png jpg jpeg gif webp'],
      ],
    ];

    $form['hero_images']['hero_category'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Category page hero'),
      '#description' => $this->t('Hero for /events/category/* when no category image is set.'),
      '#default_value' => $this->fidToDefaultValue($config->get('hero_category')),
      '#upload_location' => 'public://hero/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'png jpg jpeg gif webp'],
      ],
    ];

    $form['hero_images']['hero_search'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Search page hero'),
      '#description' => $this->t('Hero for /search.'),
      '#default_value' => $this->fidToDefaultValue($config->get('hero_search')),
      '#upload_location' => 'public://hero/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'png jpg jpeg gif webp'],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Converts a config fid to managed_file #default_value.
   */
  private function fidToDefaultValue($fid): array {
    $fid = (int) ($fid ?? 0);
    return $fid > 0 ? [$fid] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('myeventlane_theme_settings.settings');
    $storage = $this->entityTypeManager->getStorage('file');

    foreach (self::HERO_KEYS as $key) {
      $fids = $form_state->getValue($key);
      $new_fid = !empty($fids) && is_array($fids) ? (int) $fids[0] : 0;
      $old_fid = (int) ($config->get($key) ?? 0);

      if ($old_fid > 0 && $old_fid !== $new_fid) {
        $old_file = $storage->load($old_fid);
        if ($old_file instanceof FileInterface) {
          $this->fileUsage->delete($old_file, 'myeventlane_theme_settings', 'config', $key);
        }
      }

      if ($new_fid > 0) {
        $file = $storage->load($new_fid);
        if ($file instanceof FileInterface) {
          $file->setPermanent();
          $file->save();
          $this->fileUsage->add($file, 'myeventlane_theme_settings', 'config', $key);
        }
      }

      $config->set($key, $new_fid);
    }

    $config->save();
    parent::submitForm($form, $form_state);
  }

}
