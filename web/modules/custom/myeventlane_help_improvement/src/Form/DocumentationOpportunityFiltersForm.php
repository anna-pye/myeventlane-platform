<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_improvement\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * GET filter form for the opportunity listing.
 */
final class DocumentationOpportunityFiltersForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mel_doc_opportunity_filters';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $status = 'all', string $source = 'all', string $audience = 'all'): array {
    $form['#method'] = 'get';
    $form['#action'] = Url::fromRoute('myeventlane_help_improvement.opportunities')->toString();
    $form['#token'] = FALSE;

    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#options' => [
        'all' => $this->t('- Any -'),
        'new' => $this->t('New'),
        'reviewing' => $this->t('Reviewing'),
        'drafted' => $this->t('Drafted'),
        'resolved' => $this->t('Resolved'),
        'ignored' => $this->t('Ignored'),
      ],
      '#default_value' => $status,
    ];
    $form['source'] = [
      '#type' => 'select',
      '#title' => $this->t('Source'),
      '#options' => [
        'all' => $this->t('- Any -'),
        'zero_results_search' => $this->t('Zero-result search'),
        'repeated_search' => $this->t('Repeated search'),
        'low_helpfulness' => $this->t('Low helpfulness'),
        'assistant_no_answer' => $this->t('Assistant: no answer'),
        'assistant_low_confidence' => $this->t('Assistant: low confidence'),
      ],
      '#default_value' => $source,
    ];
    $form['audience'] = [
      '#type' => 'select',
      '#title' => $this->t('Audience'),
      '#options' => [
        'all' => $this->t('- Any -'),
        'unknown' => $this->t('Unknown'),
        'public' => $this->t('Public'),
        'vendor' => $this->t('Vendor'),
        'staff' => $this->t('Staff'),
      ],
      '#default_value' => $audience,
    ];
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Filter'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

}
