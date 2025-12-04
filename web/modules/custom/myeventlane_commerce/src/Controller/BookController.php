<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Book page: renders the linked product in "add_to_cart" view mode.
 */
final class BookController extends ControllerBase {

  /**
   * Constructs BookController.
   */
  public function __construct(
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('file_url_generator')
    );
  }

  /**
   * Renders the booking page for an event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return array
   *   Render array.
   */
  public function book(NodeInterface $node): array {
    // Only for Event nodes.
    if ($node->bundle() !== 'event') {
      throw $this->createNotFoundException();
    }

    // Get event date text.
    $eventDateText = '';
    if ($node->hasField('field_event_start') && !$node->get('field_event_start')->isEmpty()) {
      $startDate = $node->get('field_event_start')->value;
      if ($startDate) {
        try {
          $date = new \DateTime($startDate);
          $eventDateText = $date->format('l, F j, Y');
        }
        catch (\Exception) {
          $eventDateText = $startDate;
        }
      }
    }

    // Get venue text.
    $venueText = '';
    if ($node->hasField('field_venue_name') && !$node->get('field_venue_name')->isEmpty()) {
      $venueText = $node->get('field_venue_name')->value;
    }
    elseif ($node->hasField('field_location') && !$node->get('field_location')->isEmpty()) {
      $location = $node->get('field_location')->value;
      if (is_array($location) && isset($location['address_line1'])) {
        $venueText = $location['address_line1'];
      }
    }

    $build = [
      '#theme' => 'myeventlane_event_book',
      '#title' => $node->label(),
      '#event_date_text' => $eventDateText,
      '#venue_text' => $venueText,
      '#hero_url' => '',
      '#matrix_form' => [],
      '#is_rsvp' => FALSE,
      '#cache' => [
        'contexts' => ['route', 'user.roles', 'url.query_args'],
        'tags' => $node->getCacheTags(),
      ],
    ];

    // Optional hero image URL.
    if ($node->hasField('field_event_image') && !$node->get('field_event_image')->isEmpty()) {
      $file = $node->get('field_event_image')->entity;
      if ($file) {
        $build['#hero_url'] = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      }
    }

    // Determine event type.
    $eventType = $node->hasField('field_event_type') && !$node->get('field_event_type')->isEmpty()
      ? $node->get('field_event_type')->value
      : 'paid';
    
    $isRsvp = in_array($eventType, ['rsvp', 'both'], TRUE);
    $build['#is_rsvp'] = $isRsvp;

    // Pull first referenced product and render appropriate form.
    if ($node->hasField('field_product_target') && !$node->get('field_product_target')->isEmpty()) {
      $product = $node->get('field_product_target')->entity;
      if ($product && $product->isPublished()) {
        $default_variation = $product->getDefaultVariation();
        if ($default_variation) {
          // For RSVP events, show RSVP form. For paid events, show add-to-cart.
          $price = $default_variation->getPrice();
          $isFree = $price && ((float) $price->getNumber() === 0.0);
          
          if ($isRsvp && $isFree) {
            // Build RSVP form with attendee fields.
            $build['#matrix_form'] = $this->buildRsvpForm($node, $product, $default_variation);
          }
          else {
            // Build standard add-to-cart form.
            /** @var \Drupal\commerce_order\OrderItemStorageInterface $order_item_storage */
            $order_item_storage = $this->entityTypeManager()->getStorage('commerce_order_item');
            $order_item = $order_item_storage->createFromPurchasableEntity($default_variation);
            
            /** @var \Drupal\commerce_cart\Form\AddToCartFormInterface $form_object */
            $form_object = $this->entityTypeManager()->getFormObject('commerce_order_item', 'add_to_cart');
            $form_object->setEntity($order_item);
            $form_object->setFormId($form_object->getBaseFormId() . '_commerce_product_' . $product->id());
            
            $form_state = (new FormState())->setFormState([
              'product' => $product,
              'view_mode' => 'default',
              'settings' => [
                'combine' => TRUE,
              ],
            ]);
            
            $build['#matrix_form'] = $this->formBuilder()->buildForm($form_object, $form_state);
          }
        }
      }
    }

    return $build;
  }

  /**
   * Builds an RSVP form for free events.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product.
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The product variation.
   *
   * @return array
   *   Form render array.
   */
  private function buildRsvpForm(NodeInterface $event, $product, $variation): array {
    return $this->formBuilder()->getForm(
      'Drupal\myeventlane_commerce\Form\RsvpBookingForm',
      $product->id(),
      $variation->id(),
      $event->id()
    );
  }
}
