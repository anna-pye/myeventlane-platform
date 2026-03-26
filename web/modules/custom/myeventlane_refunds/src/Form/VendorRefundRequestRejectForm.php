<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_refunds\Service\RefundAccessResolver;
use Drupal\myeventlane_refunds\Service\RefundProcessor;
use Drupal\myeventlane_refunds\Service\RefundRequestStorage;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Form for vendor to reject a buyer refund request.
 */
final class VendorRefundRequestRejectForm extends FormBase {

  /**
   * Constructs VendorRefundRequestRejectForm.
   *
   * @param \Drupal\myeventlane_refunds\Service\RefundProcessor $refundProcessor
   *   The refund processor.
   * @param \Drupal\myeventlane_refunds\Service\RefundRequestStorage $refundRequestStorage
   *   The refund request storage.
   * @param \Drupal\myeventlane_refunds\Service\RefundAccessResolver $accessResolver
   *   The access resolver.
   */
  public function __construct(
    private readonly RefundProcessor $refundProcessor,
    private readonly RefundRequestStorage $refundRequestStorage,
    private readonly RefundAccessResolver $accessResolver,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_refunds.processor'),
      $container->get('myeventlane_refunds.refund_request_storage'),
      $container->get('myeventlane_refunds.access_resolver'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_refunds_organiser_reject_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $node = $this->getRouteMatch()->getParameter('node');
    $refundRequestId = (int) $this->getRouteMatch()->getParameter('refund_request');
    $req = $this->refundRequestStorage->load($refundRequestId);
    if (!$req || $req['status'] !== RefundRequestStorage::STATUS_REQUESTED) {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Refund request not found or not pending.') . '</p>',
      ];
      return $form;
    }

    $event = $this->entityTypeManager->getStorage('node')->load($req['event_id']);
    $nodeId = $node instanceof NodeInterface ? $node->id() : (is_numeric($node) ? $node : NULL);
    if (!$event instanceof NodeInterface || $nodeId === NULL || (int) $event->id() !== (int) $nodeId) {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Event mismatch.') . '</p>',
      ];
      return $form;
    }

    if (!$this->accessResolver->vendorCanManageEvent($event, $this->currentUser())) {
      throw new AccessDeniedHttpException('You cannot reject refunds for this event.');
    }

    $form['#node'] = $node;
    $form['#refund_request'] = $req;
    $form['#id'] = Html::getId($this->getFormId());
    $form['#action'] = Url::fromRoute('myeventlane_refunds.vendor_refund_request_reject', [
      'node' => (int) $event->id(),
      'refund_request' => (int) $req['id'],
    ])->toString();
    $form['#attributes']['class'][] = Html::getClass($this->getFormId());
    $form['#attributes']['data-drupal-selector'] = Html::getId($this->getFormId());
    $form['#attributes']['method'] = 'post';
    $form['form_id'] = [
      '#type' => 'hidden',
      '#value' => $this->getFormId(),
    ];
    $form_state->set('refund_request', $req);

    $form['info'] = [
      '#markup' => '<p>' . $this->t('This request will be declined. The buyer will receive your message.') . '</p>',
    ];

    $form['decision_reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message to buyer'),
      '#required' => TRUE,
      '#rows' => 4,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Decline request'),
      '#button_type' => 'primary',
      '#attributes' => [
        'class' => ['mel-force-submit'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $reason = trim((string) $form_state->getValue('decision_reason'));

    if ($reason === '') {
      $form_state->setErrorByName('decision_reason', $this->t('Add a short note so the buyer understands why this refund wasn’t approved.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $req = $form_state->get('refund_request') ?? $form['#refund_request'] ?? NULL;

    if (!$req) {
      $this->getLogger('myeventlane_refunds')->error('Reject form: missing refund request.');
      $this->messenger()->addError($this->t('Something went wrong. Please refresh and try again.'));
      return;
    }

    $reason = trim((string) $form_state->getValue('decision_reason'));

    try {
      $this->refundProcessor->rejectBuyerRefundRequest(
        (int) $req['id'],
        $this->currentUser(),
        $reason
      );

      $this->messenger()->addStatus(
        $this->t('Refund request declined — the buyer has been notified with your message.')
      );
    }
    catch (\Throwable $e) {
      $this->getLogger('myeventlane_refunds')->error('Reject failed: @message', [
        '@message' => $e->getMessage(),
      ]);

      $this->messenger()->addError(
        $this->t('Couldn’t save this decision. No changes were made.')
      );

      return;
    }

    $node = $form['#node'] ?? NULL;
    $nodeId = $node ? $node->id() : 0;

    $form_state->setRedirect('myeventlane_refunds.vendor_refund_requests', [
      'node' => $nodeId,
    ]);
  }

}
