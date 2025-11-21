<?php

namespace Drupal\bootstrap5_admin;

// cspell:ignore bootswatch spacelab glassmorphic neumorphic litera
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * Sub theme settings manager.
 */
class SettingsManager {

  use StringTranslationTrait;

  /**
   * Constructs a Web formThemeManager object.
   *
   * @param \Drupal\Core\Theme\ThemeManagerInterface $themeManager
   *   The theme manager.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file manager service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Extension\ThemeExtensionList $themeExtensionList
   *   The theme extension service.
   */
  public function __construct(
    protected ThemeManagerInterface $themeManager,
    protected FileSystemInterface $fileSystem,
    protected MessengerInterface $messenger,
    protected ThemeExtensionList $themeExtensionList,
  ) {
  }

  /**
   * Alters theme settings form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param string $form_id
   *   The form id.
   *
   * @see hook_form_alter()
   */
  public function themeSettingsAlter(array &$form, FormStateInterface $form_state, $form_id) {
    if (isset($form_id)) {
      return;
    }
    $options_theme = [
      'none' => 'do not apply theme',
      'light' => 'light (dark text/links against a light background)',
      'dark' => 'dark (light/white text/links against a dark background)',
    ];

    $options_colour = [
      'none' => 'do not apply colour',
      'primary' => 'primary',
      'secondary' => 'secondary',
      'light' => 'light',
      'dark' => 'dark',
    ];

    // Populating options for top container.
    $options_top_container = [
      'container-fluid m-0' => 'fluid with padding',
      'container-fluid m-0 p-0' => 'fluid',
      'container' => 'fixed',
    ];
    $options_bootswatch = [
      'cerulean' => 'Cerulean - A calm blue sky',
      'cosmo' => 'Cosmo - An ode to Metro',
      'cyborg' => 'Cyborg - Jet black and electric blue',
      'darkly' => 'Darkly - Flatly in night mode',
      'flatly' => 'Flatly - Flat and modern',
      'journal' => 'Journal - Crisp like a new sheet of paper',
      'litera' => 'Litera - The medium is the message',
      'lumen' => 'Lumen - Light and shadow',
      'lux' => 'Lux - A touch of class',
      'materia' => 'Materia - Material is the metaphor',
      'minty' => 'Minty - A fresh feel',
      'morph' => 'Morph - A neumorphic layer',
      'pulse' => 'Pulse - A trace of purple',
      'quartz' => 'Quartz - A glassmorphic layer',
      'sandstone' => 'Sandstone - A touch of warmth',
      'simplex' => 'Simplex - Mini and minimalist',
      'sketchy' => 'Sketchy - A hand-drawn look for mockups and mirth',
      'slate' => 'Slate - Shades of gunmetal gray',
      'solar' => 'Solar - A spin on Solarized',
      'spacelab' => 'Spacelab - Silvery and sleek',
      'superhero' => 'Superhero - The brave and the blue',
      'united' => 'United - Ubuntu orange and unique font',
      'vapor' => 'Vapor - A cyberpunk aesthetic',
      'yeti' => 'Yeti - A friendly foundation',
      'zephyr' => 'Zephyr - Breezy and beautiful',
    ];
    // Populating extra options for top container.
    if (!empty($container_config = theme_get_setting('b5_top_container_config'))) {
      foreach (explode("\n", $container_config) as $line) {
        $values = explode("|", trim($line));
        if (is_array($values) && (count($values) == 2)) {
          $options_top_container += [trim($values[0]) => trim($values[1])];
        }
      }
    }

    $form['body_details'] = [
      '#type' => 'details',
      '#title' => $this->t('Body options'),
      '#description' => $this->t("Combination of theme/background colour may affect background colour/text colour contrast. To fix any contrast issues, override corresponding variables in scss(refer to dist/bootstrap5_admin/scss/_variables.scss)"),
      '#open' => TRUE,
    ];

    $form['body_details']['b5_top_container'] = [
      '#type' => 'select',
      '#title' => $this->t('Website container type'),
      '#default_value' => theme_get_setting('b5_top_container'),
      '#description' => $this->t("Type of top level container: fluid (eg edge to edge) or fixed width"),
      '#options' => $options_top_container,
    ];

    $form['body_details']['b5_top_container_config'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Website container type configuration'),
      '#default_value' => theme_get_setting('b5_top_container_config'),
      '#description' => $this->t("Format: <classes|label> on each line, e.g. <br><pre>container|fixed<br />container-fluid m-0 p-0|fluid</pre>"),
    ];

    $form['body_details']['b5_body_schema'] = [
      '#type' => 'select',
      '#title' => $this->t('Body theme:'),
      '#default_value' => theme_get_setting('b5_body_schema'),
      '#description' => $this->t("Text colour theme of the body."),
      '#options' => $options_theme,
    ];

    $form['body_details']['b5_body_bg_schema'] = [
      '#type' => 'select',
      '#title' => $this->t('Body background:'),
      '#default_value' => theme_get_setting('b5_body_bg_schema'),
      '#description' => $this->t("Background color of the body."),
      '#options' => $options_colour,
    ];

    $form['nav_details'] = [
      '#type' => 'details',
      '#title' => $this->t('Navbar options'),
      '#description' => $this->t("Combination of theme/background colour may affect background colour/text colour contrast. To fix any contrast issues, override \$navbar-light-*/\$navbar-dark-* variables (refer to dist/bootstrap5_admin/scss/_variables.scss)"),
      '#open' => TRUE,
    ];

    $form['nav_details']['b5_navbar_schema'] = [
      '#type' => 'select',
      '#title' => $this->t('Navbar theme:'),
      '#default_value' => theme_get_setting('b5_navbar_schema'),
      '#description' => $this->t("Text colour theme of the navbar."),
      '#options' => $options_theme,
    ];

    $form['nav_details']['b5_navbar_bg_schema'] = [
      '#type' => 'select',
      '#title' => $this->t('Navbar background:'),
      '#default_value' => theme_get_setting('b5_navbar_bg_schema'),
      '#description' => $this->t("Background color of the navbar."),
      '#options' => $options_colour,
    ];

    $form['footer_details'] = [
      '#type' => 'details',
      '#title' => $this->t('Footer options'),
      '#description' => $this->t("Combination of theme/background colour may affect background colour/text colour contrast. To fix any contrast issues, override corresponding variables in scss (refer to dist/bootstrap5_admin/scss/_variables.scss)"),
      '#open' => TRUE,
    ];

    $form['footer_details']['b5_footer_schema'] = [
      '#type' => 'select',
      '#title' => $this->t('Footer theme:'),
      '#default_value' => theme_get_setting('b5_footer_schema'),
      '#description' => $this->t("Text colour theme of the footer."),
      '#options' => $options_theme,
    ];

    $form['footer_details']['b5_footer_bg_schema'] = [
      '#type' => 'select',
      '#title' => $this->t('Footer background:'),
      '#default_value' => theme_get_setting('b5_footer_bg_schema'),
      '#description' => $this->t("Background color of the footer."),
      '#options' => $options_colour,
    ];
    $bootswatch_theme = theme_get_setting('b5_bootswatch_theme');
    $form['bootswatch'] = [
      '#type' => 'details',
      '#title' => $this->t('Bootswatch'),
      '#open' => !empty($bootswatch_theme),
    ];
    $form['bootswatch']['b5_bootswatch_theme'] = [
      '#type' => 'select',
      '#title' => $this->t('Bootswatch theme'),
      '#options' => $options_bootswatch,
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $bootswatch_theme,
      '#description' => $this->t("Theme <a href='@bootswatch'>Bootswatch</a>.", [
        '@bootswatch' => 'https://bootswatch.com/',
      ]),
      '#attributes' => [
        'onchange' => "document.getElementById('edit-b5-bootswatch-theme--description').innerHTML = '<img src=https://bootswatch.com/' + this.value + '/thumbnail.png />';",
      ],
    ];
    $form['bootstrap_select'] = [
      '#type' => 'details',
      '#title' => $this->t('Bootstrap select'),
      '#open' => !empty(theme_get_setting('b5_bootstrap_select')),
    ];
    $form['bootstrap_select']['b5_bootstrap_select'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Active bootstrap select'),
      '#default_value' => theme_get_setting('b5_bootstrap_select'),
      '#description' => $this->t("View <a href='@select'>Bootstrap select</a>. An error may occur with the select form after the ajax load form", [
        '@select' => 'https://developer.snapappointments.com/bootstrap-select/',
      ]),
    ];
    if (!empty($bootswatch_theme)) {
      $form['bootswatch']['b5_bootswatch_theme']['#description'] = [
        '#theme' => 'image',
        '#uri' => 'https://bootswatch.com/' . $bootswatch_theme . '/thumbnail.png',
        '#height' => 210,
      ];
    }
    $form['custom_css_js'] = [
      '#title' => $this->t('Custom CSS & JS'),
      '#type' => 'details',
      '#description' => $this->t("This CSS will be attached after the theme and allows you to customize your site without needing the additional complexity of a subtheme."),
      '#open' => FALSE,
    ];

    $form['custom_css_js']['custom_css_site'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom CSS'),
      '#default_value' => theme_get_setting('custom_css_site'),
      '#attributes' => [
        'data-ace-editor' => 'css',
        'class' => ['ace-editor'],
      ],
    ];

    $form['custom_css_js']['custom_javascript_site'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom Javascript'),
      '#default_value' => theme_get_setting('custom_javascript_site'),
      '#attributes' => [
        'data-ace-editor' => 'javascript',
        'class' => ['ace-editor'],
      ],
    ];
    $form['#attached']['library'][] = 'bootstrap5_admin/ace_editor';

    $form['subtheme'] = [
      '#type' => 'details',
      '#title' => $this->t('Subtheme'),
      '#description' => $this->t("Create subtheme."),
      '#open' => FALSE,
    ];

    $form['subtheme']['subtheme_folder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subtheme location'),
      '#default_value' => 'themes/custom',
      '#description' => $this->t("Relative path to the webroot <em>%root</em>. No trailing slash.", [
        '%root' => DRUPAL_ROOT,
      ]),
    ];

    $form['subtheme']['subtheme_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subtheme name'),
      '#default_value' => 'Bootstrap 5 admin subtheme',
      '#description' => $this->t("If name is empty, machine name will be used."),
    ];

    $form['subtheme']['subtheme_machine_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subtheme machine name'),
      '#default_value' => 'bootstrap_subtheme',
      '#description' => $this->t("Use lowercase characters, numbers and underscores. Name must start with a letter."),
    ];

    $form['subtheme']['create'] = [
      '#type' => 'submit',
      '#name' => 'subtheme_create',
      '#value' => $this->t('Create'),
      '#button_type' => 'danger',
      '#attributes' => [
        'class' => ['btn btn-danger'],
      ],
      '#validate' => [[$this, 'subthemeValidate']],
      '#submit' => [[$this, 'subthemeSubmit']],
    ];

  }

  /**
   * Submit validate subtheme callback.
   */
  public function subthemeValidate(array &$form, FormStateInterface $form_state) {
    $subtheme_manager = new SubthemeManager($this->fileSystem, $this->messenger, $this->themeExtensionList);
    return $subtheme_manager->validateForm($form, $form_state);
  }

  /**
   * Submit create subtheme callback.
   */
  public function subthemeSubmit(array &$form, FormStateInterface $form_state) {
    $subtheme_manager = new SubthemeManager($this->fileSystem, $this->messenger, $this->themeExtensionList);
    return $subtheme_manager->submitForm($form, $form_state);
  }

}
