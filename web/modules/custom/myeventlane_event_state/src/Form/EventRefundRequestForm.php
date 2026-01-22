<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_state\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to request refunds for an event.
 */
final class EventRefundRequestForm extends FormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_event_state_refund_request_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL, ?int $order = NULL): array {
    $form['#node'] = $node;
    $form['#order'] = $order;

    $form['warning'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning">' . $this->t('Refund requests will be processed by administrators. This action cannot be undone.') . '</div>',
    ];

    $form['refund_reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reason for Refund Request'),
      '#description' => $this->t('Explain why refunds are being requested.'),
      '#required' => TRUE,
      '#rows' => 5,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit Refund Request'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('myeventlane_event_state.refunds', ['node' => $node->id()]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node = $form['#node'];
    $orderId = $form['#order'];
    $reason = $form_state->getValue('refund_reason');

    // @todo Create refund request record in database.
    // For now, log the request.
    \Drupal::logger('myeventlane_event_state')->notice('Refund request submitted for event @event, order @order', [
      '@event' => $node->id(),
      '@order' => $orderId ?? 'all',
    ]);

    $this->messenger()->addStatus($this->t('Refund request has been submitted. Administrators will process it shortly.'));
    $form_state->setRedirect('myeventlane_event_state.refunds', ['node' => $node->id()]);
  }

}
