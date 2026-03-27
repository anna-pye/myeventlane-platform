<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_improvement\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_help_improvement\Service\DocumentationOpportunityStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Updates workflow fields on a documentation opportunity.
 */
final class DocumentationOpportunityEditForm extends FormBase {

  public function __construct(
    private readonly DocumentationOpportunityStorage $storage,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_help_improvement.storage'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mel_doc_opportunity_edit';
  }

  /**
   * {@inheritdoc}
   *
   * Extra arguments from the form builder's getForm() call are passed after
   * $form_state; a variadic parameter keeps the signature compatible with
   * \Drupal\Core\Form\FormInterface while still receiving the opportunity row.
   */
  public function buildForm(array $form, FormStateInterface $form_state, mixed ...$form_args): array {
    $opportunity = [];
    if ($form_args !== [] && is_array($form_args[0])) {
      $opportunity = $form_args[0];
    }
    else {
      $build_args = $form_state->getBuildInfo()['args'] ?? [];
      if (isset($build_args[0]) && is_array($build_args[0])) {
        $opportunity = $build_args[0];
      }
    }

    if (!$this->currentUser()->hasPermission('administer documentation opportunities')) {
      return [
        '#markup' => '<p>' . $this->t('You do not have permission to edit opportunities.') . '</p>',
      ];
    }

    $id = (int) ($opportunity['id'] ?? 0);
    $form['opportunity_id'] = [
      '#type' => 'value',
      '#value' => $id,
    ];
    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#required' => TRUE,
      '#options' => [
        DocumentationOpportunityStorage::STATUS_NEW => $this->t('New'),
        DocumentationOpportunityStorage::STATUS_REVIEWING => $this->t('Reviewing'),
        DocumentationOpportunityStorage::STATUS_DRAFTED => $this->t('Drafted'),
        DocumentationOpportunityStorage::STATUS_RESOLVED => $this->t('Resolved'),
        DocumentationOpportunityStorage::STATUS_IGNORED => $this->t('Ignored'),
      ],
      '#default_value' => (string) ($opportunity['status'] ?? DocumentationOpportunityStorage::STATUS_NEW),
    ];
    $form['audience'] = [
      '#type' => 'select',
      '#title' => $this->t('Audience'),
      '#description' => $this->t('Set a concrete audience before running AI draft generation (unknown blocks generation).'),
      '#required' => TRUE,
      '#options' => [
        'unknown' => $this->t('Unknown'),
        'public' => $this->t('Public'),
        'vendor' => $this->t('Vendor'),
        'staff' => $this->t('Staff'),
      ],
      '#default_value' => (string) ($opportunity['audience'] ?? 'unknown'),
    ];
    $form['linked_help_article_nid'] = [
      '#type' => 'number',
      '#title' => $this->t('Linked help article (nid)'),
      '#min' => 1,
      '#default_value' => $opportunity['linked_help_article_nid'] !== NULL ? (int) $opportunity['linked_help_article_nid'] : NULL,
    ];
    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Editorial notes'),
      '#default_value' => $opportunity['notes'] !== NULL ? (string) $opportunity['notes'] : '',
      '#rows' => 5,
    ];
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save opportunity'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $id = (int) $form_state->getValue('opportunity_id');
    $linked = $form_state->getValue('linked_help_article_nid');
    $linkedNid = $linked !== NULL && $linked !== '' ? (int) $linked : NULL;
    if ($linkedNid !== NULL && $linkedNid < 1) {
      $linkedNid = NULL;
    }
    $this->storage->update($id, [
      'status' => (string) $form_state->getValue('status'),
      'audience' => (string) $form_state->getValue('audience'),
      'linked_help_article_nid' => $linkedNid,
      'notes' => (string) $form_state->getValue('notes'),
      'last_seen' => $this->time->getRequestTime(),
    ]);
    $this->messenger()->addStatus($this->t('Opportunity @id updated.', ['@id' => (string) $id]));
    $form_state->setRedirectUrl(Url::fromRoute('myeventlane_help_improvement.opportunity', ['opportunity' => $id]));
  }

}
