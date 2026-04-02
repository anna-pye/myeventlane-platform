<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;
use Drupal\myeventlane_event\Service\EventCtaResolver;
use Drupal\myeventlane_event\Service\EventModeManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Book page: renders the booking form based on CTA type.
 *
 * Mutual exclusivity: paid (tickets only), rsvp (RSVP only), or none.
 * No combined RSVP + Paid UI. Logic in controller; Twig display only.
 */
final class BookController extends ControllerBase {

  /**
   * Constructs BookController.
   *
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   * @param \Drupal\myeventlane_event\Service\EventModeManager $modeManager
   *   The event mode manager.
   * @param \Drupal\myeventlane_event\Service\EventCtaResolver $ctaResolver
   *   The CTA resolver (paid | rsvp | none).
   * @param \Drupal\Core\Form\FormBuilderInterface $formBuilderService
   *   The form builder.
   */
  public function __construct(
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly EventModeManager $modeManager,
    private readonly EventCtaResolver $ctaResolver,
    private readonly FormBuilderInterface $formBuilderService,
    private readonly ?EventCapacityServiceInterface $capacityService = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('file_url_generator'),
      $container->get('myeventlane_event.event_mode_manager'),
      $container->get('myeventlane_event.cta_resolver'),
      $container->get('form_builder'),
      $container->has('myeventlane_capacity.service')
        ? $container->get('myeventlane_capacity.service')
        : NULL,
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
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
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

    $mode = $this->modeManager->getEffectiveMode($node);
    $ctaType = $this->ctaResolver->getCtaType($node);

    $isRsvp = $ctaType === EventCtaResolver::CTA_RSVP;
    $isPaid = $ctaType === EventCtaResolver::CTA_PAID;

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
      '#cta_type' => $ctaType,
      '#event_mode' => $ctaType,
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

    if ($mode === EventModeManager::MODE_EXTERNAL) {
      $build['#matrix_form'] = $this->buildExternalRedirect($node);
      $build['#event_mode'] = 'external';
      return $build;
    }

    switch ($ctaType) {
      case EventCtaResolver::CTA_PAID:
        $build['#matrix_form'] = $this->buildPaidForm($node);
        break;

      case EventCtaResolver::CTA_RSVP:
        $build['#matrix_form'] = $this->buildRsvpOnlyForm($node);
        break;

      default:
        $build['#matrix_form'] = $this->buildComingSoon();
        $build['#event_mode'] = 'none';
        break;
    }

    return $build;
  }

  /**
   * Builds the RSVP-only form.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Form render array.
   */
  private function buildRsvpOnlyForm(NodeInterface $event): array {
    if (!$this->melEventHasRsvp($event)) {
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-alert', 'mel-alert--warning']],
        'message' => [
          '#markup' => '<p>' . $this->t('RSVP is not yet available for this event.') . '</p>',
        ],
      ];
    }
    return $this->formBuilderService->getForm(
      'Drupal\myeventlane_rsvp\Form\RsvpPublicForm',
      $event
    );
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

    if ($this->capacityService && $this->capacityService->isSoldOut($event)) {
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-alert', 'mel-alert--info']],
        'message' => [
          '#markup' => '<p>' . $this->t('This event is sold out.') . '</p>',
        ],
      ];
    }

    return $this->formBuilderService->getForm(
      'Drupal\myeventlane_commerce\Form\TicketSelectionForm',
      $event,
      $product
    );
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
        '#url' => Url::fromUri($externalUrl),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn--primary', 'mel-btn--xl'],
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

  /**
   * MEL source-of-truth RSVP readiness: at least one RSVP tier.
   */
  private function melEventHasRsvp(NodeInterface $event): bool {
    if (!$event->hasField('field_ticket_types')) {
      return FALSE;
    }

    $tiers = $event->get('field_ticket_types')->referencedEntities();
    if ($tiers === []) {
      \Drupal::logger('mel_debug')->notice('No tiers resolved on event @nid', [
        '@nid' => (string) $event->id(),
      ]);
      return FALSE;
    }

    foreach ($tiers as $tier) {
      $kind = (string) ($tier->get('ticket_kind')->value ?? '');
      \Drupal::logger('mel_debug')->notice('Tier check @id kind=@kind', [
        '@id' => (string) $tier->id(),
        '@kind' => $kind,
      ]);
      if ($kind === 'rsvp') {
        return TRUE;
      }
    }

    return FALSE;
  }

}
