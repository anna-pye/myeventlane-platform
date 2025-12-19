<?php

namespace Drupal\myeventlane_event\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * MISSING FIELDS: The following fields referenced in getStepMap() do not exist
 * on this site and have been removed from the step map:
 * - field_event_summary (Step 1)
 * - field_event_timezone (Step 2)
 * - field_donation_enabled (Step 5)
 * - field_donation_suggested (Step 5)
 * - field_social_share (Step 6)
 * 
 * FIELD NAME CORRECTIONS:
 * - field_event_category -> field_category
 * - field_event_tags -> field_tags
 * - field_ticket_type -> field_ticket_types
 */
final class EventFormAlter {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly RouteMatchInterface $routeMatch,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('current_user'),
      $container->get('current_route_match'),
      $container->get('entity_type.manager'),
    );
  }

  public function alterForm(array &$form, FormStateInterface $form_state, string $form_id): void {
    if (!$this->isEventForm($form, $form_state, $form_id)) {
      return;
    }

    $this->attachLibraries($form);

    $is_vendor_context = $this->isVendorContext();
    if ($is_vendor_context) {
      $this->hideAdminFields($form);
      $this->suppressTextFormatHelp($form);
      $this->buildTabbedWizard($form, $form_state, $is_vendor_context);
    }
  }

  private function isEventForm(array &$form, FormStateInterface $form_state, string $form_id): bool {
    if (!str_contains($form_id, 'node_event')) {
      return FALSE;
    }
    
    // Check via form entity
    $form_object = $form_state->getFormObject();
    if ($form_object && method_exists($form_object, 'getEntity')) {
      $entity = $form_object->getEntity();
      if ($entity && $entity->getEntityTypeId() === 'node' && $entity->bundle() === 'event') {
        return TRUE;
      }
    }
    
    // Fallback: check form properties
    if (isset($form['#node']) && $form['#node'] instanceof \Drupal\node\NodeInterface) {
      return $form['#node']->bundle() === 'event';
    }
    
    if (isset($form['#entity_type']) && $form['#entity_type'] === 'node') {
      if (isset($form['#bundle']) && $form['#bundle'] === 'event') {
        return TRUE;
      }
    }
    
    return FALSE;
  }

  private function isVendorContext(): bool {
    $route_name = (string) $this->routeMatch->getRouteName();
    if (str_starts_with($route_name, 'myeventlane_vendor')) {
      return TRUE;
    }
    $path = \Drupal::service('path.current')->getPath();
    return str_starts_with($path, '/vendor/');
  }

  private function buildTabbedWizard(array &$form, FormStateInterface $form_state, bool $is_vendor_context): void {
    // Get current tab from form state or default to 'basics'
    $current_tab = (string) ($form_state->get('mel_wizard_tab') ?? 'basics');
    
    // Define tabs and their field mappings
    $tabs = $this->getTabMap();
    
    // Store current tab in form state
    $form_state->set('mel_wizard_tab', $current_tab);
    
    // Organize fields into tab sections
    $placed = [];
    
    foreach ($tabs as $tab_id => $tab_config) {
      $section_key = $tab_config['section_key'] ?? $tab_id;
      
      // Create section container
      $is_active = ($current_tab === $tab_id);
      if (!isset($form[$section_key])) {
        $classes = ['mel-tab-pane', 'mel-simple-tab-pane'];
        if ($is_active) {
          $classes[] = 'is-active';
        }
        $form[$section_key] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => $classes,
            'data-tab-pane' => $tab_id,
            'data-simple-tab-pane' => $tab_id,
            'role' => 'tabpanel',
          ],
        ];
      } else {
        // Add tab pane attributes to existing section
        if (!isset($form[$section_key]['#attributes'])) {
          $form[$section_key]['#attributes'] = [];
        }
        if (!isset($form[$section_key]['#attributes']['class'])) {
          $form[$section_key]['#attributes']['class'] = [];
        }
        if (!is_array($form[$section_key]['#attributes']['class'])) {
          $form[$section_key]['#attributes']['class'] = [$form[$section_key]['#attributes']['class']];
        }
        $classes = $form[$section_key]['#attributes']['class'];
        if (!in_array('mel-tab-pane', $classes, TRUE)) {
          $classes[] = 'mel-tab-pane';
        }
        if (!in_array('mel-simple-tab-pane', $classes, TRUE)) {
          $classes[] = 'mel-simple-tab-pane';
        }
        if ($is_active && !in_array('is-active', $classes, TRUE)) {
          $classes[] = 'is-active';
        }
        $form[$section_key]['#attributes']['class'] = $classes;
        $form[$section_key]['#attributes']['data-tab-pane'] = $tab_id;
        $form[$section_key]['#attributes']['data-simple-tab-pane'] = $tab_id;
        $form[$section_key]['#attributes']['role'] = 'tabpanel';
      }
      
      // Add content wrapper if not exists
      if (!isset($form[$section_key]['content'])) {
        $form[$section_key]['content'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-tab-content']],
        ];
      }
      
      // Move fields into section
      foreach ($tab_config['fields'] as $field_name) {
        if (isset($form[$field_name])) {
          $form[$section_key]['content'][$field_name] = $form[$field_name];
          unset($form[$field_name]);
          $placed[$field_name] = TRUE;
        }
      }
    }
    
    // Hide orphaned fields
    $this->hideOrphanedFields($form, $placed, $is_vendor_context);
    
    // Build navigation actions with validation limits
    $this->buildTabActions($form, $form_state, $tabs, $current_tab);
  }

  private function getTabMap(): array {
    return [
      'basics' => [
        'section_key' => 'event_basics',
        'title' => 'Basics',
        'fields' => [
          'title',
          'body',
          'field_event_image',
        ],
      ],
      'schedule' => [
        'section_key' => 'date_time',
        'title' => 'Schedule',
        'fields' => [
          'field_event_start',
          'field_event_end',
        ],
      ],
      'location' => [
        'section_key' => 'location',
        'title' => 'Location',
        'fields' => [
          'field_venue_name',
          'field_location',
        ],
      ],
      'tickets' => [
        'section_key' => 'booking_config',
        'title' => 'Tickets',
        'fields' => [
          'field_event_type',
          'field_ticket_types',
          'field_capacity',
          'field_waitlist_capacity',
          'field_product_target',
          'field_external_url',
          'field_collect_per_ticket',
        ],
      ],
      'design' => [
        'section_key' => 'visibility',
        'title' => 'Design',
        'fields' => [
          'field_category',
          'field_tags',
          'field_accessibility',
        ],
      ],
      'questions' => [
        'section_key' => 'addons_tab',
        'title' => 'Questions',
        'fields' => [
          'field_attendee_questions',
        ],
      ],
    ];
  }

  private function buildTabActions(array &$form, FormStateInterface $form_state, array $tabs, string $current_tab): void {
    $tab_ids = array_keys($tabs);
    $current_index = array_search($current_tab, $tab_ids, TRUE);
    $is_first = ($current_index === 0);
    $is_last = ($current_index === (count($tab_ids) - 1));
    
    // Ensure actions container exists
    if (!isset($form['actions'])) {
      $form['actions'] = [
        '#type' => 'actions',
      ];
    }
    
    // Add Next button if not last tab
    if (!$is_last) {
      $next_tab = $tab_ids[$current_index + 1] ?? NULL;
      if ($next_tab) {
        $form['actions']['wizard_next'] = [
          '#type' => 'submit',
          '#value' => 'Next',
          '#submit' => [[get_class($this), 'submitNextTab']],
          '#limit_validation_errors' => $this->limitErrorsForTab($tabs, $current_tab),
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
          '#weight' => 10,
        ];
      }
    }
    
    // Update submit button for last tab
    if ($is_last && isset($form['actions']['submit'])) {
      $form['actions']['submit']['#value'] = 'Publish event';
      $form['actions']['submit']['#attributes']['class'][] = 'mel-btn';
      $form['actions']['submit']['#attributes']['class'][] = 'mel-btn--primary';
    }
  }

  private function limitErrorsForTab(array $tabs, string $current_tab): array {
    $parents = [];
    foreach (($tabs[$current_tab]['fields'] ?? []) as $field_name) {
      $parents[] = [$field_name];
    }
    // Always include title
    $parents[] = ['title'];
    return $parents;
  }

  public static function submitNextTab(array &$form, FormStateInterface $form_state): void {
    $tabs = ['basics', 'schedule', 'location', 'tickets', 'design', 'questions'];
    $current = (string) ($form_state->get('mel_wizard_tab') ?? 'basics');
    $current_index = array_search($current, $tabs, TRUE);
    
    if ($current_index !== FALSE && isset($tabs[$current_index + 1])) {
      $form_state->set('mel_wizard_tab', $tabs[$current_index + 1]);
    }
    $form_state->setRebuild(TRUE);
  }

  private function hideAdminFields(array &$form): void {
    $admin_only = [
      'path',
      'revision',
      'revision_log',
      'uid',
      'created',
      'promote',
      'sticky',
      'menu',
      'metatag',
    ];
    foreach ($admin_only as $key) {
      if (isset($form[$key])) {
        $form[$key]['#access'] = FALSE;
      }
    }
  }

  private function suppressTextFormatHelp(array &$form): void {
    // Suppress text format help recursively
    $this->hideTextFormatHelpRecursive($form);
  }

  private function hideTextFormatHelpRecursive(array &$element): void {
    foreach ($element as $key => &$child) {
      if (is_array($child)) {
        // Hide filter guidelines and help
        if (isset($child['#type']) && in_array($child['#type'], ['filter_guidelines', 'processed_text'], TRUE)) {
          $child['#access'] = FALSE;
        }
        
        // Hide "About text formats" links
        if (isset($child['#type']) && $child['#type'] === 'link') {
          if (isset($child['#url']) && method_exists($child['#url'], 'toString')) {
            $href = $child['#url']->toString();
            if (str_contains($href, 'filter/tips') || str_contains($href, 'filter-help')) {
              $child['#access'] = FALSE;
            }
          }
        }
        
        // Recursively process
        $this->hideTextFormatHelpRecursive($child);
      }
    }
    unset($child);
  }

  private function hideOrphanedFields(array &$form, array $placed, bool $is_vendor_context): void {
    $allow_root = [
      'actions',
      'advanced',
      'footer',
      'field_sidebar',
      'langcode',
      'translation',
      'event_basics',
      'date_time',
      'location',
      'booking_config',
      'visibility',
      'addons_tab',
    ];

    foreach ($form as $key => &$element) {
      if (str_starts_with((string) $key, '#')) {
        continue;
      }
      if (in_array($key, $allow_root, TRUE)) {
        continue;
      }
      if (!empty($placed[$key])) {
        continue;
      }

      if ($is_vendor_context) {
        if (is_array($element)) {
          $element['#access'] = FALSE;
        }
      }
    }
  }

  private function attachLibraries(array &$form): void {
    $form['#attached']['library'][] = 'myeventlane_event/event_wizard';
  }

}
