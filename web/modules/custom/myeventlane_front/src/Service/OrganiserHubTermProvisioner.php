<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\TermInterface;

/**
 * Ensures Organiser Hub taxonomy terms exist after config import.
 */
final class OrganiserHubTermProvisioner {

  /**
   * Maps exported term config suffixes to hub section list values.
   *
   * @var array<string, string>
   */
  private const TERM_SECTIONS = [
    'planning' => 'planning_event',
    'budgeting' => 'planning_event',
    'marketing' => 'marketing_event',
    'sponsorship' => 'marketing_event',
    'operations' => 'running_event',
    'volunteers' => 'running_event',
    'accessibility' => 'running_event',
    'growth' => 'growing_event',
    'templates_downloads' => 'templates_downloads',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Creates missing organiser hub taxonomy terms from exported config.
   *
   * @return list<string>
   *   Status messages.
   */
  public function provision(): array {
    $messages = [];
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');

    foreach (self::TERM_SECTIONS as $suffix => $section) {
      $configName = 'taxonomy.term.organiser_hub_categories.' . $suffix;
      $config = $this->configFactory->get($configName);
      if ($config->isNew()) {
        $messages[] = (string) t('Skipped missing config @id.', ['@id' => $configName]);
        continue;
      }

      $name = trim((string) $config->get('name'));
      if ($name === '') {
        continue;
      }

      $existing = $storage->loadByProperties([
        'vid' => 'organiser_hub_categories',
        'name' => $name,
      ]);
      $term = NULL;
      if (is_array($existing) && $existing !== []) {
        $term = reset($existing);
      }

      if (!$term instanceof TermInterface) {
        $term = Term::create([
          'vid' => 'organiser_hub_categories',
          'name' => $name,
          'description' => (string) $config->get('description'),
          'weight' => (int) $config->get('weight'),
        ]);
        $term->save();
        $messages[] = (string) t('Created organiser hub term @name.', ['@name' => $name]);
      }

      if ($term->hasField('field_organiser_hub_section')) {
        $term->set('field_organiser_hub_section', $section);
        $term->save();
      }
    }

    return $messages;
  }

}
