<?php

use Drupal\bootstrap5_admin\Bootstrap5AdminPreRender;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Hook implementations for bootstrap 5 admin.
 */
class Bootstrap5AdminHook {

  /**
   * Constructor hook.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request service.
   */
  public function __construct(protected Request $request) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack')->getCurrentRequest(),
    );
  }

  /**
   * Implements hook_form_alter().
   */
  #[Hook('form_alter')]
  public function formAlter(&$form, FormStateInterface $form_state, $form_id) : void {
    $build_info = $form_state->getBuildInfo();
    // Make entity forms delete link use the action-link component.
    $is_ajax = $this->request->isXmlHttpRequest();
    if (!$is_ajax && isset($form['actions']['delete']['#type']) && $form['actions']['delete']['#type'] === 'link' && !empty($build_info['callback_object']) && $build_info['callback_object'] instanceof EntityForm) {
      $form['actions']['delete'] = _bootstrap5_convert_link_to_action_link($form['actions']['delete'], 'trash', 'default', 'danger');
    }
    if (!$is_ajax && isset($form['actions']['cancel']['#type']) && $form['actions']['cancel']['#type'] === 'link' && !empty($build_info['callback_object'])) {
      $form['actions']['cancel'] = _bootstrap5_convert_link_to_action_link($form['actions']['cancel'], 'arrow-counterclockwise', 'default', 'danger');
    }

    switch ($form_id) {
      case 'field_ui_field_storage_add_form':
        $form["add"]["new_storage_type"]['#wrapper_attributes']['class'][] = 'col-auto';
        $form["add"]["separator"]['#wrapper_attributes']['class'][] = 'col-auto';
        $form["add"]["existing_storage_name"]['#wrapper_attributes']['class'][] = 'col-auto';
        break;

      case 'node_preview_form_select':
        $form['backlink']['#options']['attributes']['class'][] = 'button btn';
        $form['backlink']['#options']['attributes']['class'][] = 'btn-primary';
        $form['backlink']['#options']['attributes']['class'][] = 'button--icon-back';
        $form['backlink']['#options']['attributes']['class'][] = 'btn-primary';
        $form['view_mode']['#attributes']['class'][] = 'form-element--small';
        $form['#attributes']['class'][] = 'bg-light bg-gradient';
        break;
    }
  }

  /**
   * Implements hook_views_pre_render().
   */
  #[Hook('views_pre_render')]
  public function viewsPreRender($view) {
    $add_classes = function (&$option, array $classes_to_add) {
      $classes = preg_split('/\s+/', $option);
      $classes = array_filter($classes);
      $classes = array_merge($classes, $classes_to_add);
      $option = implode(' ', array_unique($classes));
    };

    if ($view->id() === 'media_library') {
      if ($view->display_handler->options['defaults']['css_class']) {
        $add_classes($view->displayHandlers->get('default')->options['css_class'], ['media-library-view']);
      }
      else {
        $add_classes($view->display_handler->options['css_class'], ['media-library-view']);
      }

      if ($view->current_display === 'page') {
        if (array_key_exists('media_bulk_form', $view->field)) {
          $add_classes($view->field['media_bulk_form']->options['element_class'], ['media-library-item__click-to-select-checkbox']);
        }
        if (array_key_exists('rendered_entity', $view->field)) {
          $add_classes($view->field['rendered_entity']->options['element_class'], ['media-library-item__content']);
        }
        if (array_key_exists('edit_media', $view->field)) {
          $add_classes($view->field['edit_media']->options['alter']['link_class'], ['media-library-item__edit']);
          $add_classes($view->field['edit_media']->options['alter']['link_class'], ['icon-link']);
        }
        if (array_key_exists('delete_media', $view->field)) {
          $add_classes($view->field['delete_media']->options['alter']['link_class'], ['media-library-item__remove']);
          $add_classes($view->field['delete_media']->options['alter']['link_class'], ['icon-link']);
        }
      }
      elseif (strpos($view->current_display, 'widget') === 0) {
        if (array_key_exists('rendered_entity', $view->field)) {
          $add_classes($view->field['rendered_entity']->options['element_class'], ['media-library-item__content']);
        }
        if (array_key_exists('media_library_select_form', $view->field)) {
          $add_classes($view->field['media_library_select_form']->options['element_wrapper_class'], ['media-library-item__click-to-select-checkbox']);
        }

        if ($view->display_handler->options['defaults']['css_class']) {
          $add_classes($view->displayHandlers->get('default')->options['css_class'], ['media-library-view--widget']);
        }
        else {
          $add_classes($view->display_handler->options['css_class'], ['media-library-view--widget']);
        }
      }
    }
  }

  /**
   * Implements hook_element_info_alter().
   */
  #[Hook('element_info_alter')]
  public function elementInfoAlter(&$info): void {
    if (isset($info['vertical_tabs'])) {
      $info['vertical_tabs']['#pre_render'][] = [Bootstrap5AdminPreRender::class, 'verticalTabs'];
    }
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter().
   */
  #[Hook('form_node_form_alter')]
  public function formNodeFormAlter(&$form, FormStateInterface $form_state, $form_id) : void {
    $form['#theme'] = ['node_edit_form'];

    $form['advanced']['#type'] = 'container';
    $form['advanced']['#accordion'] = TRUE;
    $form['meta']['#type'] = 'container';
    $form['meta']['#access'] = TRUE;

    $form['revision_information']['#type'] = 'container';
    $form['revision_information']['#group'] = 'meta';
    $form['revision_information']['#attributes']['class'][] = 'entity-meta__revision';
    // cspell:ignore metabox
    if (isset($form['metabox_fields'])) {
      $form['metabox_fields']['#open'] = TRUE;
    }
    foreach ($form['actions'] as &$btnSubmit) {
      if (!empty($btnSubmit['#type']) && $btnSubmit['#type'] == 'submit') {
        $btnSubmit['#no_icon'] = TRUE;
      }
    }
  }

}
