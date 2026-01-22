<?php

declare(strict_types=1);

namespace Drupal\mel_tickets\Form;

use Drupal\commerce_price\Price;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Modal form for adding a ticket type.
 *
 * Creates a Commerce product of type "ticket" with a variation.
 */
final class AddTicketModalForm extends FormBase {

  /**
   * Constructs AddTicketModalForm.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mel_tickets_add_ticket_modal_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $event = NULL): array {
    // Get event from route parameter if not passed directly.
    if (!$event) {
      $route_match = \Drupal::service('current_route_match');
      $event = $route_match->getParameter('event');
    }

    if (!$event || $event->bundle() !== 'event') {
      $form['error'] = [
        '#markup' => $this->t('Invalid event.'),
      ];
      return $form;
    }

    // Store event in form state.
    $form_state->set('event', $event);

    // Modal title will be set by the route/controller.
    $form['#prefix'] = '<div class="mel-add-ticket-modal">';
    $form['#suffix'] = '</div>';

    $form['header'] = [
      '#markup' => '<div class="mel-modal-header-context"><p>🎟️ ' . $this->t('Add ticket for') . ' <strong>' . $event->label() . '</strong></p></div>',
      '#weight' => -10,
    ];

    // Required fields.
    $form['ticket_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Ticket name'),
      '#required' => TRUE,
      '#placeholder' => $this->t('General Admission'),
      '#maxlength' => 255,
      '#attributes' => [
        'class' => ['mel-input'],
      ],
    ];

    $form['price'] = [
      '#type' => 'number',
      '#title' => $this->t('Price'),
      '#required' => TRUE,
      '#step' => 0.01,
      '#min' => 0,
      '#default_value' => '0.00',
      '#field_prefix' => '$',
      '#attributes' => [
        'class' => ['mel-input'],
      ],
    ];

    $form['quantity'] = [
      '#type' => 'number',
      '#title' => $this->t('Quantity available'),
      '#required' => TRUE,
      '#min' => 1,
      '#default_value' => 100,
      '#description' => $this->t('Total number of tickets available'),
      '#attributes' => [
        'class' => ['mel-input'],
      ],
    ];

    // Optional settings (progressive disclosure).
    $form['optional_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Optional settings'),
      '#open' => FALSE,
      '#attributes' => [
        'class' => ['mel-optional-settings'],
      ],
    ];

    $form['optional_settings']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#description' => $this->t('Optional description for this ticket type'),
      '#rows' => 3,
      '#attributes' => [
        'class' => ['mel-input'],
      ],
    ];

    // Note: Sales start/end dates are not available on Commerce variations
    // in the current schema. If needed, they would need to be added as fields
    // on ticket_variation bundle first.
    // Form actions.
    $form['actions'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-modal-actions'],
      ],
    ];

    $form['actions']['cancel'] = [
      '#type' => 'button',
      '#value' => $this->t('Cancel'),
      '#ajax' => [
        'callback' => '::cancelAjax',
        'event' => 'click',
      ],
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn--secondary'],
      ],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save ticket'),
      '#ajax' => [
        'callback' => '::submitAjax',
        'event' => 'click',
      ],
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn--primary'],
      ],
    ];

    // Attach modal styling library.
    $form['#attached']['library'][] = 'mel_tickets/add_ticket_modal';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $price = (float) $form_state->getValue('price');
    if ($price < 0) {
      $form_state->setError($form['price'], $this->t('Price cannot be negative.'));
    }

    $quantity = (int) $form_state->getValue('quantity');
    if ($quantity < 1) {
      $form_state->setError($form['quantity'], $this->t('Quantity must be at least 1.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $form_state->get('event');
    if (!$event || $event->bundle() !== 'event') {
      return;
    }

    $ticket_name = trim($form_state->getValue('ticket_name'));
    $price_value = (float) $form_state->getValue('price');
    $quantity = (int) $form_state->getValue('quantity');
    $description = trim($form_state->getValue('description') ?? '');

    // Create Commerce product of type "ticket".
    $product_storage = $this->entityTypeManager->getStorage('commerce_product');
    $product = $product_storage->create([
      'type' => 'ticket',
      'title' => $ticket_name,
      'field_event' => $event->id(),
      'status' => 1,
    ]);

    // Set description if provided (if field exists).
    if (!empty($description) && $product->hasField('body')) {
      $product->set('body', [
        'value' => $description,
        'format' => 'basic_html',
      ]);
    }

    $product->save();

    // Create Commerce product variation of type "ticket_variation".
    $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');

    // Generate a unique SKU.
    $sku = 'TICKET-' . $event->id() . '-' . $product->id() . '-' . time();

    $variation = $variation_storage->create([
      'type' => 'ticket_variation',
      'sku' => $sku,
      'title' => $ticket_name,
      'price' => new Price((string) number_format($price_value, 2, '.', ''), 'USD'),
      'field_event' => $event->id(),
      'status' => 1,
    ]);

    // Set stock quantity if field exists.
    if ($variation->hasField('field_stock')) {
      $variation->set('field_stock', $quantity);
    }

    $variation->save();

    // Link variation to product.
    $product->set('variations', [$variation]);
    $product->save();

    $this->messenger()->addStatus($this->t('Ticket added 🎉'));
  }

  /**
   * AJAX callback for cancel button.
   */
  public function cancelAjax(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new CloseModalDialogCommand());
    return $response;
  }

  /**
   * AJAX callback for submit button.
   */
  public function submitAjax(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    // If there are validation errors, return them.
    if ($form_state->hasAnyErrors()) {
      // Rebuild the form with errors.
      $form_builder = \Drupal::service('form_builder');
      $form = $form_builder->rebuildForm($this->getFormId(), $form_state, $form);
      $event = $form_state->get('event');
      $response->addCommand(new OpenModalDialogCommand(
        $this->t('🎟️ Add ticket for @event', ['@event' => $event->label()]),
        $form,
        ['width' => 'medium', 'dialogClass' => 'mel-add-ticket-dialog']
      ));
      return $response;
    }

    // Close modal and redirect to ticket types page.
    $response->addCommand(new CloseModalDialogCommand());

    // Redirect to ticket types list.
    $event = $form_state->get('event');
    $redirect_url = Url::fromRoute('mel_tickets.event_tickets_types', ['event' => $event->id()]);
    $response->addCommand(new RedirectCommand($redirect_url->toString()));

    return $response;
  }

}
