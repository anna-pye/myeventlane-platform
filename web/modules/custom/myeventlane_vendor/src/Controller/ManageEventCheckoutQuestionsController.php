<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\node\NodeInterface;

/**
 * Controller for the "Checkout questions" step.
 */
final class ManageEventCheckoutQuestionsController extends ManageEventControllerBase {

  /**
   * Renders the checkout questions form.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Render array.
   */
  public function checkoutQuestions(NodeInterface $event): array {
    // Get the checkout questions form.
    $form = $this->formBuilder()->getForm('Drupal\myeventlane_vendor\Form\EventCheckoutQuestionsForm', $event);

    $content = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-manage-event-content', 'mel-checkout-questions-step']],
      'form' => $form,
    ];

    return $this->buildPage($event, 'myeventlane_vendor.manage_event.checkout_questions', $content);
  }

  /**
   * {@inheritdoc}
   */
  protected function getPageTitle(NodeInterface $event): string {
    return (string) $this->t('Guest questions');
  }

}
