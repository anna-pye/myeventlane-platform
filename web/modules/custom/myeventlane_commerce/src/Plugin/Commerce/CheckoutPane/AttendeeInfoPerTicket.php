<?php

namespace Drupal\myeventlane_commerce\Plugin\Commerce\CheckoutPane;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneInterface;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Attendee information (per ticket) pane.
 *
 * @deprecated in myeventlane_commerce:2.0.0 and is removed from checkout flows.
 *   Use ticket_holder_paragraph pane instead, which stores attendee data in
 *   paragraph entities (attendee_answer) via field_ticket_holder.
 *
 * This pane stored attendee data in JSON format (field_attendee_data), which
 * has been replaced by the paragraph-based system for better data integrity
 * and vendor access control.
 *
 * @see \Drupal\myeventlane_checkout_paragraph\Plugin\Commerce\CheckoutPane\TicketHolderParagraphPane
 *
 * @CommerceCheckoutPane(
 *   id = "myeventlane_attendee_info_per_ticket",
 *   label = @Translation("Attendee information (per ticket)"),
 *   default_step = "order_information",
 *   wrapper_element = "container",
 * )
 */
class AttendeeInfoPerTicket extends CheckoutPaneBase implements CheckoutPaneInterface {

  /**
   * The paragraph question mapper service.
   *
   * @var \Drupal\myeventlane_commerce\Service\ParagraphQuestionMapper
   */
  protected $questionMapper;

  /**
   * Factory create with checkout flow (Commerce RC/Drupal 11 compatible).
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface|null $checkout_flow
   *   The checkout flow, if available.
   *
   * @return static
   *   The checkout pane instance.
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ?CheckoutFlowInterface $checkout_flow = NULL,
  ) {
    /** @var static $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition, $checkout_flow);
    $instance->questionMapper = $container->get('myeventlane_commerce.paragraph_question_mapper');
    return $instance;
  }

  /**
   * Visible only if any item belongs to an event with per-ticket capture enabled.
   *
   * @return bool
   *   TRUE if the pane should be visible.
   */
  public function isVisible(): bool {
    // Always return FALSE - this pane is deprecated and should not be visible.
    // It is disabled in checkout flow configuration.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form): array {
    // This pane is deprecated. Return empty form.
    \Drupal::logger('myeventlane_commerce')->warning(
      'Deprecated AttendeeInfoPerTicket pane was accessed. This pane stores attendee data in JSON format and has been replaced by the paragraph-based system.'
    );
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function validatePaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    // No validation needed - pane is deprecated.
  }

  /**
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    // Log warning if this deprecated pane attempts to write data.
    \Drupal::logger('myeventlane_commerce')->warning(
      'Deprecated AttendeeInfoPerTicket pane attempted to write to field_attendee_data. This is deprecated in favor of paragraph-based storage.'
    );
    // Do not write any data.
  }

  /**
   * From order item → purchased entity → referenced Event node.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The event node, if available.
   */
  protected function loadEventFromOrderItem(OrderItemInterface $order_item): ?NodeInterface {
    $purchased_entity = $order_item->getPurchasedEntity();
    if ($purchased_entity && $purchased_entity->hasField('field_event_ref') && !$purchased_entity->get('field_event_ref')->isEmpty()) {
      return $purchased_entity->get('field_event_ref')->entity;
    }
    return NULL;
  }

  /**
   * Gets existing stored attendee data for a given ticket index.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item.
   * @param int $n
   *   The ticket index (1-based).
   *
   * @return array
   *   Default values for this ticket.
   */
  protected function getExistingTicketData(OrderItemInterface $order_item, int $n): array {
    // Deprecated - do not read from field_attendee_data.
    return [];
  }

  /**
   * Gets accessibility taxonomy term options.
   *
   * @return array
   *   Array of term ID => term name.
   */
  protected function getAccessibilityOptions(): array {
    try {
      $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
      $terms = $storage->loadByProperties(['vid' => 'accessibility']);
      $options = [];
      foreach ($terms as $term) {
        $options[$term->id()] = $term->label();
      }
      return $options;
    }
    catch (\Exception) {
      return [];
    }
  }

}
