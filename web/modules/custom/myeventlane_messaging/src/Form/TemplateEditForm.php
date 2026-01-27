<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for editing messaging templates.
 */
final class TemplateEditForm extends FormBase {

  /**
   * Constructs TemplateEditForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_messaging_template_edit_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $template = NULL): array {
    if (!$template) {
      $form['error'] = [
        '#markup' => $this->t('Template not specified.'),
      ];
      return $form;
    }

    $configName = "myeventlane_messaging.template.{$template}";
    $config = $this->configFactory->getEditable($configName);

    if ($config->isNew()) {
      $form['error'] = [
        '#markup' => $this->t('Template @template does not exist.', ['@template' => $template]),
      ];
      return $form;
    }

    $form['#template'] = $template;
    $form['#config_name'] = $configName;

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#description' => $this->t('When disabled, messages using this template will be suppressed.'),
      '#default_value' => (bool) $config->get('enabled'),
    ];

    $form['category'] = [
      '#type' => 'select',
      '#title' => $this->t('Category'),
      '#description' => $this->t('Template category affects preference enforcement.'),
      '#options' => [
        'transactional' => $this->t('Transactional (always sent)'),
        'operational' => $this->t('Operational (respects operational reminder opt-out)'),
        'marketing' => $this->t('Marketing (respects marketing opt-out)'),
      ],
      '#default_value' => $this->getCategory($template),
      '#disabled' => TRUE,
      '#description' => $this->t('Category is determined by template key and cannot be changed.'),
    ];

    $form['subject'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Subject (Twig)'),
      '#description' => $this->t('Email subject line. Use Twig syntax, e.g., {{ event_title }}.'),
      '#default_value' => (string) ($config->get('subject') ?? ''),
      '#required' => TRUE,
      '#rows' => 2,
    ];

    $bodyValue = (string) ($config->get('body_html') ?? $config->get('body') ?? '');
    $form['body_html'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body HTML (Twig)'),
      '#description' => $this->t('Email body HTML. Use Twig syntax for variables.'),
      '#default_value' => $bodyValue,
      '#required' => TRUE,
      '#rows' => 20,
    ];

    $requiredTokens = $this->getRequiredTokens($template);
    if (!empty($requiredTokens)) {
      $form['required_tokens'] = [
        '#type' => 'item',
        '#title' => $this->t('Required tokens'),
        '#markup' => '<p>' . $this->t('This template must include the following tokens: @tokens', [
          '@tokens' => implode(', ', $requiredTokens),
        ]) . '</p>',
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save'),
      ],
      'cancel' => [
        '#type' => 'link',
        '#title' => $this->t('Cancel'),
        '#url' => Url::fromRoute('myeventlane_messaging.templates'),
        '#attributes' => ['class' => ['button']],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $template = $form['#template'];
    $subject = trim((string) $form_state->getValue('subject'));
    $body = trim((string) $form_state->getValue('body_html'));

    // Extract tokens from Twig templates.
    $subjectTokens = $this->extractTokens($subject);
    $bodyTokens = $this->extractTokens($body);
    $allTokens = array_unique(array_merge($subjectTokens, $bodyTokens));

    // Check required tokens.
    $requiredTokens = $this->getRequiredTokens($template);
    $missing = [];
    foreach ($requiredTokens as $required) {
      if (!in_array($required, $allTokens, TRUE)) {
        $missing[] = $required;
      }
    }

    if (!empty($missing)) {
      $form_state->setError($form['body_html'], $this->t('Missing required tokens: @tokens', [
        '@tokens' => implode(', ', $missing),
      ]));
    }

    // Validate Twig syntax (basic check).
    if ($this->hasInvalidTwigSyntax($subject)) {
      $form_state->setError($form['subject'], $this->t('Subject contains invalid Twig syntax.'));
    }
    if ($this->hasInvalidTwigSyntax($body)) {
      $form_state->setError($form['body_html'], $this->t('Body contains invalid Twig syntax.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $configName = $form['#config_name'];
    $config = $this->configFactory->getEditable($configName);

    $config->set('enabled', (bool) $form_state->getValue('enabled'));
    $config->set('subject', trim((string) $form_state->getValue('subject')));
    $body = trim((string) $form_state->getValue('body_html'));
    $config->set('body_html', $body);
    // Clear legacy body field if it exists.
    if ($config->get('body')) {
      $config->clear('body');
    }
    $config->save();

    $this->messenger()->addStatus($this->t('Template saved.'));
    $form_state->setRedirect('myeventlane_messaging.templates');
  }

  /**
   * Extracts token names from Twig template string.
   *
   * @param string $template
   *   The Twig template string.
   *
   * @return string[]
   *   Array of token names (without {{ }}).
   */
  private function extractTokens(string $template): array {
    $tokens = [];
    // Match {{ variable }}, {{ variable|filter }}, {{ variable.field }}, etc.
    if (preg_match_all('/\{\{\s*([a-zA-Z0-9_\.]+)/', $template, $matches)) {
      foreach ($matches[1] as $match) {
        // Extract base variable name (before any dots or pipes).
        $parts = explode('.', $match);
        $base = $parts[0];
        if (!empty($base)) {
          $tokens[] = $base;
        }
      }
    }
    return array_unique($tokens);
  }

  /**
   * Checks if template has invalid Twig syntax.
   *
   * @param string $template
   *   The template string.
   *
   * @return bool
   *   TRUE if invalid syntax detected.
   */
  private function hasInvalidTwigSyntax(string $template): bool {
    // Basic check: unmatched braces.
    $open = substr_count($template, '{{');
    $close = substr_count($template, '}}');
    if ($open !== $close) {
      return TRUE;
    }
    // Check for unclosed tags.
    $openTags = substr_count($template, '{%');
    $closeTags = substr_count($template, '%}');
    if ($openTags !== $closeTags) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Gets required tokens for a template.
   *
   * @param string $template
   *   The template key.
   *
   * @return string[]
   *   Array of required token names.
   */
  private function getRequiredTokens(string $template): array {
    // Define required tokens per template type.
    $required = [
      'vendor_event_update' => ['event_title', 'event_url'],
      'vendor_event_important_change' => ['event_title', 'event_url'],
      'vendor_event_cancellation' => ['event_title', 'event_url'],
      'order_receipt' => ['order_number', 'order_email'],
      'event_reminder' => ['event_title', 'event_url'],
      'event_reminder_24h' => ['event_title', 'event_url'],
      'event_reminder_7d' => ['event_title', 'event_url'],
    ];
    return $required[$template] ?? [];
  }

  /**
   * Gets the category for a template.
   *
   * @param string $template
   *   The template key.
   *
   * @return string
   *   The category.
   */
  private function getCategory(string $template): string {
    $transactional = [
      'order_receipt',
      'vendor_event_cancellation',
      'vendor_event_important_change',
      'vendor_event_update',
    ];
    $operational = [
      'event_reminder',
      'event_reminder_24h',
      'event_reminder_7d',
      'cart_abandoned',
      'boost_reminder',
    ];

    if (in_array($template, $transactional, TRUE)) {
      return 'transactional';
    }
    if (in_array($template, $operational, TRUE)) {
      return 'operational';
    }
    return 'marketing';
  }

}
