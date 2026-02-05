<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Form\Wizard;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Step 5: Redirect to Boost Commerce purchase (boost page).
 *
 * Single "Go to payment" button; redirects to myeventlane_boost.boost_page.
 */
final class BoostPaymentRedirectForm extends FormBase implements ContainerInjectionInterface {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self();
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_boost_payment_redirect_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $event = NULL): array {
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      $form['#markup'] = $this->t('This step requires an event.');
      return $form;
    }

    $event_id = (int) $event->id();
    $form_state->set('event_id', $event_id);

    $form['#attributes']['class'][] = 'mel-boost-wizard-step';

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('You’re ready to pay for your boost. Click below to go to the payment page.') . '</p>',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Go to payment'),
      '#button_type' => 'primary',
      '#attributes' => [
        'class' => ['mel-button', 'mel-button--primary'],
        'aria-label' => $this->t('Go to payment for boost'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event_id = (int) $form_state->get('event_id');
    if ($event_id <= 0) {
      return;
    }

    $form_state->setRedirect('myeventlane_boost.boost_page', ['node' => $event_id]);
  }

}
