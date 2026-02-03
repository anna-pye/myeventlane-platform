<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays message log.
 */
final class MessageLogController extends ControllerBase {

  /**
   * Constructs MessageLogController.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Displays the message log.
   *
   * @return array
   *   A render array.
   */
  public function log(): array {
    $schema = $this->database->schema();
    $hasProvider = $schema->fieldExists('myeventlane_message', 'provider');
    $hasProviderId = $schema->fieldExists('myeventlane_message', 'provider_message_id');

    $fields = [
      'id',
      'template',
      'recipient',
      'status',
      'attempts',
      'created',
      'sent',
    ];
    if ($hasProvider) {
      $fields[] = 'provider';
    }
    if ($hasProviderId) {
      $fields[] = 'provider_message_id';
    }

    $query = $this->database->select('myeventlane_message', 'm')
      ->fields('m', $fields)
      ->orderBy('m.created', 'DESC')
      ->range(0, 100);

    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      $recipient = $this->maskEmail((string) $row->recipient);
      $created = $row->created ? $this->dateFormatter->format($row->created, 'short') : '-';
      $sent = $row->sent ? $this->dateFormatter->format($row->sent, 'short') : '-';
      $provider = ($hasProvider && isset($row->provider)) ? $row->provider : '-';
      $providerId = ($hasProviderId && isset($row->provider_message_id)) ? $row->provider_message_id : '-';

      $rows[] = [
        $created,
        $row->template,
        $recipient,
        $row->status,
        (string) $row->attempts,
        $provider,
        $providerId,
        $sent,
      ];
    }

    $build = [
      '#type' => 'table',
      '#header' => [
        $this->t('Created'),
        $this->t('Template'),
        $this->t('Recipient'),
        $this->t('Status'),
        $this->t('Attempts'),
        $this->t('Provider'),
        $this->t('Provider ID'),
        $this->t('Sent'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No messages found.'),
    ];

    return $build;
  }

  /**
   * Masks an email address for display.
   *
   * @param string $email
   *   The email address.
   *
   * @return string
   *   Masked email (e.g., j***@example.com).
   */
  private function maskEmail(string $email): string {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return $email;
    }
    [$local, $domain] = explode('@', $email, 2);
    $masked = substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 1));
    return $masked . '@' . $domain;
  }

}
