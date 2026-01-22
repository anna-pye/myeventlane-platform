<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

/**
 * Resolves optional services that may not be available.
 *
 * Centralises \Drupal::hasService() and \Drupal::service() for optional
 * dependencies used across multiple classes. The container is the single
 * place that performs the lookup.
 *
 * Use when a service is optional (e.g. from an optional module) and the
 * same hasService+service logic would otherwise be duplicated.
 */
final class OptionalServiceResolver {

  /**
   * Returns an optional service if it exists.
   *
   * @param string $service_id
   *   The service ID.
   *
   * @return object|null
   *   The service instance, or NULL if the service does not exist.
   */
  public function get(string $service_id): ?object {
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal -- Centralised optional service lookup.
    if (!\Drupal::hasService($service_id)) {
      return NULL;
    }
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal -- Centralised optional service lookup.
    return \Drupal::service($service_id);
  }

}
