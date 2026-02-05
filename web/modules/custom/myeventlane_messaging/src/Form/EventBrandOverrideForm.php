<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for event-level messaging brand override.
 *
 * Edits myeventlane_messaging.brand.event.{nid}.
 * Used from vendor comms at /vendor/events/{node}/comms/branding.
 */
final class EventBrandOverrideForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
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
   * @param \Drupal\node\NodeInterface|null $node
   *   The event node; passed from route parameter.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
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

    $form['info'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Override messaging brand for this event only. Leave blank to use vendor or platform default.') . '</p>',
      '#weight' => -10,
    ];

    $form['from_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('From name'),
      '#default_value' => (string) ($config->get('from_name') ?? ''),
      '#maxlength' => 255,
    ];

    $form['from_email'] = [
      '#type' => 'email',
      '#title' => $this->t('From email'),
      '#default_value' => (string) ($config->get('from_email') ?? ''),
    ];

    $form['reply_to'] = [
      '#type' => 'email',
      '#title' => $this->t('Reply-to email'),
      '#default_value' => (string) ($config->get('reply_to') ?? ''),
    ];

    $form['logo_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Logo URL'),
      '#default_value' => (string) ($config->get('logo_url') ?? ''),
    ];

    $form['accent_color'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Accent colour'),
      '#default_value' => (string) ($config->get('accent_color') ?? '#6e7ef2'),
      '#maxlength' => 7,
    ];

    $form['footer_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Footer text'),
      '#default_value' => (string) ($config->get('footer_text') ?? ''),
      '#rows' => 2,
    ];

    $marketing = $config->get('marketing');
    $marketing = is_array($marketing) ? $marketing : [];

    $form['marketing'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Marketing block'),
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
    $config->set('logo_url', trim((string) $form_state->getValue('logo_url')));
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
