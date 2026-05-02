<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\myeventlane_commerce\Form\TicketSelectionForm;
use Drupal\myeventlane_event\Service\BookingFlowResolver;
use Drupal\myeventlane_rsvp\Form\RsvpPublicForm;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Book page: renders the booking form based on the canonical booking flow.
 *
 * Mutual exclusivity is resolved by BookingFlowResolver; Twig receives display
 * state only.
 */
final class BookController extends ControllerBase {

  /**
   * Constructs BookController.
   *
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   * @param \Drupal\myeventlane_event\Service\BookingFlowResolver $bookingFlowResolver
   *   The canonical booking flow resolver.
   * @param \Drupal\Core\Form\FormBuilderInterface $formBuilderService
   *   The form builder.
   */
  public function __construct(
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly BookingFlowResolver $bookingFlowResolver,
    private readonly FormBuilderInterface $formBuilderService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('file_url_generator'),
      $container->get('myeventlane_event.booking_flow_resolver'),
      $container->get('form_builder'),
    );
  }

  /**
   * Renders the booking page for an event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return array|\Drupal\Core\Routing\TrustedRedirectResponse
   *   Render array or trusted redirect response for external booking.
   */
  public function book(NodeInterface $node): array|TrustedRedirectResponse {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }

    $bookingMode = $this->bookingFlowResolver->getBookingMode($node);
    if ($bookingMode === BookingFlowResolver::MODE_UNAVAILABLE) {
      throw new AccessDeniedHttpException();
    }

    $availability = $this->bookingFlowResolver->getAvailabilityState($node);
    if ($availability === BookingFlowResolver::AVAILABILITY_UNAVAILABLE) {
      throw new AccessDeniedHttpException();
    }

    $primaryCta = $this->bookingFlowResolver->getPrimaryCta($node);
    if ($bookingMode === BookingFlowResolver::MODE_EXTERNAL) {
      $externalUrl = (string) ($primaryCta['url'] ?? '');
      if (($primaryCta['type'] ?? 'disabled') !== 'external' || $externalUrl === '' || !empty($primaryCta['disabled'])) {
        throw new AccessDeniedHttpException();
      }

      return new TrustedRedirectResponse($externalUrl);
    }

    $eventDateText = '';
    if ($node->hasField('field_event_start') && !$node->get('field_event_start')->isEmpty()) {
      $startDate = $node->get('field_event_start')->value;
      if ($startDate) {
        try {
          $date = new \DateTimeImmutable($startDate);
          $eventDateText = $date->format('l, F j, Y');
        }
        catch (\Exception) {
          $eventDateText = $startDate;
        }
      }
    }

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

    $isRsvp = $bookingMode === BookingFlowResolver::MODE_RSVP;
    $isPaid = $bookingMode === BookingFlowResolver::MODE_PAID;

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
      '#cta_type' => $bookingMode === BookingFlowResolver::MODE_UNAVAILABLE ? 'none' : $bookingMode,
      '#event_cta' => $primaryCta,
      '#event_mode' => $bookingMode,
      '#event' => $node,
      '#cache' => [
        'contexts' => ['route', 'user.roles', 'url.query_args', 'session'],
        'tags' => $node->getCacheTags(),
      ],
    ];

    if ($node->hasField('field_event_image') && !$node->get('field_event_image')->isEmpty()) {
      $file = $node->get('field_event_image')->entity;
      if ($file) {
        $build['#hero_url'] = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      }
    }

    $formClass = $this->bookingFlowResolver->resolveBookingForm($node);
    switch ($formClass) {
      case TicketSelectionForm::class:
        $build['#matrix_form'] = $this->buildPaidForm($node);
        break;

      case RsvpPublicForm::class:
        $build['#matrix_form'] = $this->formBuilderService->getForm($formClass, $node);
        break;

      default:
        $build['#matrix_form'] = $this->buildUnavailable($availability);
        break;
    }

    return $build;
  }

  /**
   * Builds the paid ticket form.
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

    return $this->formBuilderService->getForm(
      TicketSelectionForm::class,
      $event,
      $product
    );
  }

  /**
   * Builds an unavailable booking placeholder.
   *
   * @param string $availability
   *   BookingFlowResolver availability state.
   *
   * @return array
   *   Render array.
   */
  private function buildUnavailable(string $availability): array {
    $message = match ($availability) {
      BookingFlowResolver::AVAILABILITY_SOLD_OUT => $this->t('This event is sold out.'),
      BookingFlowResolver::AVAILABILITY_ENDED => $this->t('This event has ended.'),
      BookingFlowResolver::AVAILABILITY_UNAVAILABLE => $this->t('Booking is not available for this event.'),
      default => $this->t('Booking will be available soon.'),
    };

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-alert', 'mel-alert--info']],
      'message' => [
        '#markup' => '<p>' . $message . '</p>',
      ],
    ];
  }

  /**
   * Applies per-event donation UI defaults to RSVP forms.
   *
   * @param array<string, mixed> $form
   */
  private function applyEventDonationConfigToRsvpForm(array $form, NodeInterface $event): array {
    if (!empty($form['#mel_donation_structured'])) {
      return $form;
    }

    $enabled = $event->hasField('field_enable_donations')
      && !$event->get('field_enable_donations')->isEmpty()
      && (bool) $event->get('field_enable_donations')->value;

    if (!$enabled) {
      unset($form['donation_section'], $form['donation_unavailable'], $form['donation_amount']);
      return $form;
    }

    $label = 'Support this event';
    if ($event->hasField('field_donation_label') && !$event->get('field_donation_label')->isEmpty()) {
      $label = trim((string) $event->get('field_donation_label')->value) ?: 'Support this event';
    }
    $amount = '';
    if ($event->hasField('field_donation_default') && !$event->get('field_donation_default')->isEmpty()) {
      $amount = (string) $event->get('field_donation_default')->value;
    }
    elseif ($event->hasField('field_donation_suggested_amount') && !$event->get('field_donation_suggested_amount')->isEmpty()) {
      $amount = (string) $event->get('field_donation_suggested_amount')->value;
    }

    if (isset($form['donation_section']) && is_array($form['donation_section'])) {
      $form['donation_section']['#title'] = $this->t('💖 @label (optional)', ['@label' => $label]);
      $form['donation_section']['donation_intro'] = [
        '#markup' => '<p class="mel-donation-intro-text">' . $this->t('Support this event (optional)') . '</p>',
      ];
    }

    if (isset($form['donation_amount']) && is_array($form['donation_amount'])) {
      $form['donation_amount']['#type'] = 'number';
      $form['donation_amount']['#title'] = $label;
      $form['donation_amount']['#default_value'] = $amount;
      $form['donation_amount']['#min'] = 0;
      $form['donation_amount']['#step'] = 1;
      $form['donation_amount']['#attributes']['placeholder'] = '$0';
    }
    else {
      $form['donation_amount'] = [
        '#type' => 'number',
        '#title' => $label,
        '#default_value' => $amount,
        '#min' => 0,
        '#step' => 1,
        '#attributes' => ['placeholder' => '$0'],
      ];
    }

    return $form;
  }

}
