<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for event-level messaging brand override.
 *
 * Edits myeventlane_messaging.brand.event.{nid}.
 * Used from vendor comms at /vendor/events/{node}/comms/branding.
 */
final class EventBrandOverrideForm extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static(
      $container->get('entity_type.manager'),
      $container->get('file_url_generator'),
    );
    $instance->setConfigFactory($container->get('config.factory'));
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_messaging_event_brand_override_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\node\NodeInterface|null $event
   *   The event node; passed from route parameter.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $event = NULL): array {
    $node = $event;
    if (!$node instanceof NodeInterface || $node->getType() !== 'event') {
      $form['error'] = [
        '#markup' => $this->t('Event not found.'),
      ];
      return $form;
    }

    $nid = (int) $node->id();
    $config_name = "myeventlane_messaging.brand.event.{$nid}";
    $config = $this->configFactory()->getEditable($config_name);

    $form['#node'] = $node;
    $form['#nid'] = $nid;
    $form['#config_name'] = $config_name;
    $form['#attributes']['class'][] = 'mel-event-studio-companion-form';
    $form['#attributes']['class'][] = 'mel-event-studio-companion-form--branding';

    $form['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to Event Messages'),
      '#url' => Url::fromRoute('myeventlane_event_studio.workspace_messaging', [
        'node' => $nid,
      ]),
      '#attributes' => [
        'class' => ['mel-event-branding__back-link'],
      ],
      '#weight' => -20,
    ];

    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-studio-companion-form__header']],
      'eyebrow' => [
        '#markup' => '<p class="mel-event-studio-companion-form__eyebrow">' . $this->t('Event messages') . '</p>',
      ],
      'title' => [
        '#markup' => '<h2 class="mel-event-studio-companion-form__title">' . $this->t('Message branding') . '</h2>',
      ],
      'lede' => [
        '#markup' => '<p>' . $this->t('Choose how messages for this event look and who guests can reply to. Leave a field blank to use your organiser or MyEventLane defaults.') . '</p>',
      ],
      '#weight' => -10,
    ];

    $form['identity'] = [
      '#type' => 'details',
      '#title' => $this->t('1. Sender details'),
      '#open' => TRUE,
      '#tree' => FALSE,
      '#attributes' => ['class' => ['mel-event-studio-companion-form__accordion']],
    ];

    $form['identity']['from_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('From name'),
      '#default_value' => (string) ($config->get('from_name') ?? ''),
      '#maxlength' => 255,
    ];

    $form['identity']['from_email'] = [
      '#type' => 'email',
      '#title' => $this->t('From email'),
      '#default_value' => (string) ($config->get('from_email') ?? ''),
    ];

    $form['identity']['reply_to'] = [
      '#type' => 'email',
      '#title' => $this->t('Reply-to email'),
      '#default_value' => (string) ($config->get('reply_to') ?? ''),
    ];

    $form['visual'] = [
      '#type' => 'details',
      '#title' => $this->t('2. Logo and colour'),
      '#open' => TRUE,
      '#tree' => FALSE,
      '#attributes' => ['class' => ['mel-event-studio-companion-form__accordion']],
    ];

    $logoUrl = trim((string) ($config->get('logo_url') ?? ''));
    if ($logoUrl !== '') {
      $form['visual']['current_logo'] = [
        '#markup' => '<div class="mel-event-studio-companion-form__current-logo"><p><strong>' . $this->t('Current message logo') . '</strong></p><img src="' . Html::escape($logoUrl) . '" alt="' . Html::escape((string) $this->t('Current message logo')) . '"></div>',
      ];
    }

    $form['visual']['logo_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload message logo'),
      '#upload_location' => 'public://event-message-branding/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'png jpg jpeg gif webp'],
        'FileSizeLimit' => ['fileLimit' => 5 * 1024 * 1024],
      ],
      '#description' => $this->t('Use a PNG, JPG, GIF or WebP image up to 5 MB. A wide or square logo with a transparent or plain background works best.'),
    ];

    if ($logoUrl !== '') {
      $form['visual']['remove_logo'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove this event-specific logo and use the organiser default'),
      ];
    }

    $form['visual']['accent_color'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Accent colour'),
      '#default_value' => (string) ($config->get('accent_color') ?? '#6e7ef2'),
      '#maxlength' => 7,
    ];

    $form['footer'] = [
      '#type' => 'details',
      '#title' => $this->t('3. Message footer'),
      '#open' => TRUE,
      '#tree' => FALSE,
      '#attributes' => ['class' => ['mel-event-studio-companion-form__accordion']],
    ];

    $form['footer']['footer_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Footer text'),
      '#default_value' => (string) ($config->get('footer_text') ?? ''),
      '#rows' => 2,
    ];

    $marketing = $config->get('marketing');
    $marketing = is_array($marketing) ? $marketing : [];

    $form['marketing'] = [
      '#type' => 'details',
      '#title' => $this->t('Optional marketing block'),
      '#open' => FALSE,
      '#tree' => FALSE,
      '#attributes' => ['class' => ['mel-event-studio-companion-form__accordion']],
    ];
    $form['marketing']['promo_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Promo title'),
      '#default_value' => (string) ($marketing['promo_title'] ?? ''),
      '#maxlength' => 255,
    ];
    $form['marketing']['promo_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Promo body'),
      '#default_value' => (string) ($marketing['promo_body'] ?? ''),
      '#rows' => 3,
    ];
    $form['marketing']['promo_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Promo URL'),
      '#default_value' => (string) ($marketing['promo_url'] ?? ''),
    ];
    $form['marketing']['promo_button'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Button label'),
      '#default_value' => (string) ($marketing['promo_button'] ?? 'Learn more'),
      '#maxlength' => 64,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save event brand override'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config_name = $form['#config_name'] ?? NULL;
    if (!$config_name) {
      return;
    }

    $config = $this->configFactory()->getEditable($config_name);
    $config->set('from_name', trim((string) $form_state->getValue('from_name')));
    $config->set('from_email', trim((string) $form_state->getValue('from_email')));
    $config->set('reply_to', trim((string) $form_state->getValue('reply_to')));
    $logoUrl = (string) ($config->get('logo_url') ?? '');
    if ($form_state->getValue('remove_logo')) {
      $logoUrl = '';
    }

    $uploaded = $form_state->getValue('logo_upload');
    $fid = is_array($uploaded) ? (int) ($uploaded[0] ?? 0) : (int) $uploaded;
    if ($fid > 0) {
      $file = $this->entityTypeManager->getStorage('file')->load($fid);
      if ($file instanceof FileInterface) {
        if (!$file->isPermanent()) {
          $file->setPermanent();
          $file->save();
        }
        $logoUrl = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      }
    }
    $config->set('logo_url', $logoUrl);
    $config->set('accent_color', trim((string) $form_state->getValue('accent_color')) ?: '#6e7ef2');
    $config->set('footer_text', trim((string) $form_state->getValue('footer_text')));
    $config->set('marketing', [
      'promo_title' => trim((string) $form_state->getValue(['marketing', 'promo_title'])),
      'promo_body' => trim((string) $form_state->getValue(['marketing', 'promo_body'])),
      'promo_url' => trim((string) $form_state->getValue(['marketing', 'promo_url'])),
      'promo_button' => trim((string) $form_state->getValue(['marketing', 'promo_button'])) ?: 'Learn more',
    ]);
    $config->save();

    $this->messenger()->addStatus($this->t('Event brand override saved.'));
  }

}
