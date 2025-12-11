<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\myeventlane_event\Service\EventModeManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Book page: renders the booking form based on event type.
 *
 * This controller handles the unified booking experience for:
 * - RSVP (free) events: Shows RsvpPublicForm
 * - Paid events: Shows TicketSelectionForm or Commerce add-to-cart
 * - Both events: Shows combined options
 */
final class BookController extends ControllerBase {

  /**
   * Constructs BookController.
   */
  public function __construct(
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly EventModeManager $modeManager,
    private readonly FormBuilderInterface $formBuilderService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('file_url_generator'),
      $container->get('myeventlane_event.mode_manager'),
      $container->get('form_builder')
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
      $location = $node->get('field_location')->first();
      if ($location) {
        $venueText = $location->address_line1 ?? '';
      }
    }

    // Determine event mode using the unified mode manager.
    $eventMode = $this->modeManager->getEffectiveMode($node);
    $isRsvp = in_array($eventMode, [EventModeManager::MODE_RSVP, EventModeManager::MODE_BOTH], TRUE);
    $isPaid = in_array($eventMode, [EventModeManager::MODE_PAID, EventModeManager::MODE_BOTH], TRUE);

    // Build base render array.
    $build = [
      '#theme' => 'myeventlane_event_book',
      '#title' => $node->label(),
      '#event_date_text' => $eventDateText,
      '#venue_text' => $venueText,
      '#hero_url' => '',
      '#matrix_form' => [],
      '#rsvp_form' => [],
      '#is_rsvp' => $isRsvp,
      '#is_paid' => $isPaid,
      '#event_mode' => $eventMode,
      '#event' => $node,
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

    // Handle different booking modes.
    switch ($eventMode) {
      case EventModeManager::MODE_RSVP:
        $build['#matrix_form'] = $this->buildRsvpOnlyForm($node);
        break;

      case EventModeManager::MODE_PAID:
        $build['#matrix_form'] = $this->buildPaidForm($node);
        break;

      case EventModeManager::MODE_BOTH:
        // For "both" mode, show the paid form (which includes RSVP option).
        $build['#matrix_form'] = $this->buildBothForm($node);
        break;

      case EventModeManager::MODE_EXTERNAL:
        // External events redirect to the external URL.
        $build['#matrix_form'] = $this->buildExternalRedirect($node);
        break;

      default:
        // No booking configured.
        $build['#matrix_form'] = $this->buildComingSoon();
        break;
    }

    return $build;
  }

  /**
   * Builds the RSVP-only form for free events.
   *
   * Uses the public RSVP form from myeventlane_rsvp module directly.
   * This does NOT require a Commerce product to be linked.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Form render array.
   */
  private function buildRsvpOnlyForm(NodeInterface $event): array {
    // Check if the event has a linked Commerce product with $0 variation.
    $hasProduct = $event->hasField('field_product_target')
      && !$event->get('field_product_target')->isEmpty();

    if ($hasProduct) {
      $product = $event->get('field_product_target')->entity;
      if ($product && $product->isPublished()) {
        $variation = $product->getDefaultVariation();
        if ($variation) {
          $price = $variation->getPrice();
          $isFree = $price && ((float) $price->getNumber() === 0.0);

          if ($isFree) {
            // Use the Commerce-integrated RSVP form.
            return $this->formBuilderService->getForm(
              'Drupal\myeventlane_commerce\Form\RsvpBookingForm',
              $product->id(),
              $variation->id(),
              $event->id()
            );
          }
        }
      }
    }

    // Fallback: Use the standalone RSVP form from myeventlane_rsvp module.
    // This works even without a Commerce product linked.
    return $this->formBuilderService->getForm(
      'Drupal\myeventlane_rsvp\Form\RsvpPublicForm',
      $event
    );
  }

  /**
   * Builds the paid ticket selection form.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Form render array.
   */
  private function buildPaidForm(NodeInterface $event): array {
    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-alert', 'mel-alert--warning']],
        'message' => [
          '#markup' => '<p>' . $this->t('Tickets are not yet available for this event.') . '</p>',
        ],
      ];
    }

    $product = $event->get('field_product_target')->entity;
    if (!$product || !$product->isPublished()) {
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-alert', 'mel-alert--warning']],
        'message' => [
          '#markup' => '<p>' . $this->t('Tickets are not currently on sale.') . '</p>',
        ],
      ];
    }

    // Use the custom ticket selection form.
    return $this->formBuilderService->getForm(
      'Drupal\myeventlane_commerce\Form\TicketSelectionForm',
      $event,
      $product
    );
  }

  /**
   * Builds the combined RSVP + Paid form.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Form render array.
   */
  private function buildBothForm(NodeInterface $event): array {
    // For "both" mode, show the ticket selection form which handles
    // both free ($0) and paid variations.
    if ($event->hasField('field_product_target') && !$event->get('field_product_target')->isEmpty()) {
      $product = $event->get('field_product_target')->entity;
      if ($product && $product->isPublished()) {
        return $this->formBuilderService->getForm(
          'Drupal\myeventlane_commerce\Form\TicketSelectionForm',
          $event,
          $product
        );
      }
    }

    // Fallback: show RSVP form if no product configured.
    return $this->buildRsvpOnlyForm($event);
  }

  /**
   * Builds the external redirect message.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Render array.
   */
  private function buildExternalRedirect(NodeInterface $event): array {
    $externalUrl = '';
    if ($event->hasField('field_external_url') && !$event->get('field_external_url')->isEmpty()) {
      $link = $event->get('field_external_url')->first();
      if ($link) {
        $externalUrl = $link->getUrl()->toString();
      }
    }

    if (empty($externalUrl)) {
      return $this->buildComingSoon();
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-external-booking']],
      'message' => [
        '#markup' => '<p>' . $this->t('Tickets for this event are sold through an external provider.') . '</p>',
      ],
      'link' => [
        '#type' => 'link',
        '#title' => $this->t('Get Tickets'),
        '#url' => \Drupal\Core\Url::fromUri($externalUrl),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn-primary', 'mel-btn-lg', 'mel-btn-block'],
          'target' => '_blank',
          'rel' => 'noopener noreferrer',
        ],
      ],
    ];
  }

  /**
   * Builds the "coming soon" placeholder.
   *
   * @return array
   *   Render array.
   */
  private function buildComingSoon(): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-alert', 'mel-alert--info']],
      'message' => [
        '#markup' => '<p>' . $this->t('Booking will be available soon.') . '</p>',
      ],
    ];
  }

}
