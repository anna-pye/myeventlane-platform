<?php

namespace Drupal\myeventlane_cart\Plugin\Commerce\Cart\Form;

use Drupal\commerce_cart\Plugin\Commerce\Cart\Form\CartFormInterface;
use Drupal\commerce_cart\Plugin\Commerce\Cart\Form\CartFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Adds attendee info Paragraphs per ticket to cart lines.
 *
 * @CommerceCartForm(
 *   id = "ticket_holder_form",
 *   label = @Translation("Ticket Holder Info"),
 *   cart_form_id = "default"
 * )
 */
class TicketHolderForm extends CartFormBase implements CartFormInterface {

  /**
   * {@inheritdoc}
   */
  public function buildCartForm(array $form, FormStateInterface $form_state, array &$form_parents = []) {
    foreach ($this->cart->getItems() as $index => $order_item) {
      $quantity = (int) $order_item->getQuantity();
      $data = $order_item->getData('ticket_holders') ?? [];

      $form['items'][$index]['ticket_holders'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Ticket Holder Info'),
        '#tree' => TRUE,
      ];

      for ($i = 0; $i < $quantity; $i++) {
        $paragraph = NULL;
        if (!empty($data[$i]['paragraph_id']) && $loaded = Paragraph::load($data[$i]['paragraph_id'])) {
          $paragraph = $loaded;
        }
        else {
          // Create new paragraph entity.
          $paragraph = Paragraph::create([
            'type' => 'attendee_info',
          ]);
          $paragraph->save();
          $data[$i]['paragraph_id'] = $paragraph->id();
          $order_item->setData('ticket_holders', $data);
        }

        $form['items'][$index]['ticket_holders'][$i] = [
          '#type' => 'entity_inline_form',
          '#entity_type' => 'paragraph',
          '#bundle' => 'attendee_info',
          '#entity' => $paragraph,
          '#required' => TRUE,
        ];
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitCartForm(array &$form, FormStateInterface $form_state) {
    foreach ($this->cart->getItems() as $index => $order_item) {
      $ticket_holder_data = [];
      $form_items = $form_state->getValue(['items', $index, 'ticket_holders']) ?? [];

      foreach ($form_items as $delta => $value) {
        /** @var \Drupal\paragraphs\Entity\Paragraph $paragraph */
        $paragraph = $form['items'][$index]['ticket_holders'][$delta]['#entity'];
        if ($paragraph && $paragraph->isNew()) {
          $paragraph->save();
        }
        $ticket_holder_data[] = ['paragraph_id' => $paragraph->id()];
      }

      $order_item->setData('ticket_holders', $ticket_holder_data);
    }
  }

}
