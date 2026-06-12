<?php

/**
 * @file
 * Views data hooks for organiser hub admin reports.
 */

declare(strict_types=1);

/**
 * Implements hook_views_data_alter().
 */
function myeventlane_front_views_data_alter(array &$data): void {
  $data['node']['organiser_hub_completeness'] = [
    'title' => t('Organiser Hub completeness warnings'),
    'field' => [
      'title' => t('Completeness warnings'),
      'help' => t('Non-blocking editorial warnings for organiser hub content.'),
      'id' => 'organiser_hub_completeness',
    ],
  ];
  $data['node']['organiser_hub_download_count'] = [
    'title' => t('Organiser Hub download count'),
    'field' => [
      'title' => t('Downloads'),
      'help' => t('Count of attached resource downloads and external links.'),
      'id' => 'organiser_hub_download_count',
    ],
  ];
  $data['node']['organiser_hub_linking_issues'] = [
    'title' => t('Organiser Hub linking issues'),
    'field' => [
      'title' => t('Linking issues'),
      'help' => t('Related content and download gaps for organiser hub content.'),
      'id' => 'organiser_hub_linking_issues',
    ],
  ];
  $data['taxonomy_term_field_data']['organiser_hub_category_coverage'] = [
    'title' => t('Organiser Hub category coverage'),
    'field' => [
      'title' => t('Coverage summary'),
      'help' => t('Playbook, article, and download counts per organiser category.'),
      'id' => 'organiser_hub_category_coverage',
    ],
  ];
}
