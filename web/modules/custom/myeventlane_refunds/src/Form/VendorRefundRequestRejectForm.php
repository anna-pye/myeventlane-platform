<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Form;

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
    return 'myeventlane_refunds_vendor_reject_form';
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

    $amount = number_format($req['amount_cents'] / 100, 2);
    $currency = strtoupper($req['currency']);

    $form['info'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Rejecting refund request for @amount. The buyer will be notified.', [
        '@amount' => $currency . ' ' . $amount,
      ]) . '</p>',
    ];

    $form['decision_reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reason for rejection'),
      '#description' => $this->t('This will be included in the email to the buyer.'),
      '#required' => TRUE,
      '#rows' => 4,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reject refund request'),
      '#button_type' => 'primary',
    ];

    $nodeId = $node instanceof NodeInterface ? $node->id() : (is_numeric($node) ? $node : NULL);
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('myeventlane_refunds.vendor_refund_requests', ['node' => $nodeId ?? 0]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $req = $form['#refund_request'] ?? NULL;
    if (!$req) {
      return;
    }

    $reason = trim((string) $form_state->getValue('decision_reason'));
    if ($reason === '') {
      $form_state->setErrorByName('decision_reason', $this->t('Reason is required.'));
      return;
    }

    try {
      $this->refundProcessor->rejectBuyerRefundRequest(
        (int) $req['id'],
        $this->currentUser(),
        $reason
      );
      $this->messenger()->addStatus($this->t('Refund request rejected. The buyer has been notified.'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Rejection failed: @message', ['@message' => $e->getMessage()]));
      return;
    }

    $node = $form['#node'];
    $nodeId = $node instanceof NodeInterface ? $node->id() : (is_numeric($node) ? $node : NULL);
    $form_state->setRedirect('myeventlane_refunds.vendor_refund_requests', ['node' => $nodeId ?? 0]);
  }

}
