<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Shared, public help destinations for the Help Centre and MEL Guide.
 */
final class HelpJourneyLinks {

  use StringTranslationTrait;

  public function __construct(private readonly PathValidatorInterface $pathValidator) {}

  /**
   * Customer tasks; unavailable articles fall back to a useful help search.
   */
  public function topics(): array {
    $topics = [
      'tickets' => [
        $this->t('Find my tickets'),
        $this->t('Missing emails, ticket links and booking access.'),
        '/help/attendees/missing-ticket-or-confirmation-email',
        'missing ticket',
      ],
      'booking' => [
        $this->t('Booking or payment problem'),
        $this->t('Get help with checkout or a booking that looks incomplete.'),
        '/help/attendees/having-trouble-checking-out',
        'checkout',
      ],
      'refunds' => [
        $this->t('Refunds and cancellations'),
        $this->t('Understand your options when plans change.'),
        '/help/refunds-explained',
        'refund',
      ],
      'guests' => [
        $this->t('Change guest details'),
        $this->t('Assign tickets or check how to change a guest name.'),
        '/help/attendees/change-name-or-transfer-ticket',
        'guest',
      ],
      'contact' => [
        $this->t('Contact the organiser or MEL'),
        $this->t('Find the right person for your event or account question.'),
        '/help/contact',
        'contact',
      ],
      'organisers' => [
        $this->t('Help running an event'),
        $this->t('Event setup, ticketing and organiser tools.'),
        '/help/organisers',
        'organiser',
      ],
    ];
    $result = [];
    foreach ($topics as $key => [$title, $summary, $path, $query]) {
      $url = $this->pathValidator->getUrlIfValid($path)
        ?: Url::fromRoute('myeventlane_help_centre.search', [], ['query' => ['q' => $query]]);
      $result[$key] = ['title' => $title, 'summary' => $summary, 'url' => $url->toString()];
    }
    return $result;
  }

}
