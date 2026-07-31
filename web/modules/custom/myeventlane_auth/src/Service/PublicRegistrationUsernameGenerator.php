<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Service;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Generates private opaque usernames for public self-registration.
 */
final class PublicRegistrationUsernameGenerator {

  private const MAX_ATTEMPTS = 10;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly UuidInterface $uuid,
  ) {}

  /**
   * Generates a unique internal username that contains no customer PII.
   */
  public function generate(): string {
    $storage = $this->entityTypeManager->getStorage('user');

    for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
      $token = strtolower(str_replace('-', '', (string) $this->uuid->generate()));
      $candidate = 'mel_' . substr($token, 0, 24);
      $existing = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('name', $candidate)
        ->range(0, 1)
        ->execute();
      if ($existing === []) {
        return $candidate;
      }
    }

    throw new \RuntimeException('Unable to generate a unique internal account name.');
  }

}
