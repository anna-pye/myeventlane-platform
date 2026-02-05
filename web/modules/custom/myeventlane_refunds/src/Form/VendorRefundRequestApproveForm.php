<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_refunds\Service\RefundAccessResolver;
use Drupal\myeventlane_refunds\Service\RefundProcessor;
use Drupal\myeventlane_refunds\Service\RefundRequestStorage;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Form for vendor to approve a buyer refund request.
 */
final class VendorRefundRequestApproveForm extends ConfirmFormBase {

  /**
   * Constructs VendorRefundRequestApproveForm.
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
    return 'myeventlane_refunds_vendor_approve_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return (string) $this->t('Approve refund request?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    $req = $this->getRefundRequest();
    if (!$req) {
      return '';
    }
    $amount = number_format($req['amount_cents'] / 100, 2);
    $currency = strtoupper($req['currency']);
    return (string) $this->t('This will approve the refund of @amount and queue it for processing.', [
      '@amount' => $currency . ' ' . $amount,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    $node = $this->getEvent();
    return $node
      ? Url::fromRoute('myeventlane_refunds.vendor_refund_requests', ['node' => $node->id()])
      : Url::fromRoute('<front>');
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
      throw new AccessDeniedHttpException('You cannot approve refunds for this event.');
    }

    $form['#node'] = $node;
    $form['#refund_request'] = $req;

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $req = $form['#refund_request'] ?? NULL;
    if (!$req) {
      return;
    }

    try {
      $this->refundProcessor->approveBuyerRefundRequest(
        (int) $req['id'],
        $this->currentUser()
      );
      $this->messenger()->addStatus($this->t('Refund request approved. The refund will be processed shortly.'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Approval failed: @message', ['@message' => $e->getMessage()]));
      return;
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Gets the refund request from route.
   */
  private function getRefundRequest(): ?array {
    $reqId = (int) $this->getRouteMatch()->getParameter('refund_request');
    return $this->refundRequestStorage->load($reqId);
  }

  /**
   * Gets the event from route.
   */
  private function getEvent(): ?NodeInterface {
    $node = $this->getRouteMatch()->getParameter('node');
    if ($node instanceof NodeInterface) {
      return $node;
    }
    return is_numeric($node) ? $this->entityTypeManager->getStorage('node')->load($node) : NULL;
  }

}
