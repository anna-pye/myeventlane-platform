<?php

namespace Drupal\bootstrap5_admin;

// cspell:ignore subforder
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Subtheme manager.
 */
class SubthemeManager {

  use StringTranslationTrait;

  /**
   * SubthemeManager constructor.
   *
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\Core\Extension\ThemeExtensionList|null $themeExtensionList
   *   The theme extension list.
   */
  public function __construct(protected FileSystemInterface $fileSystem, protected MessengerInterface $messenger, protected ThemeExtensionList $themeExtensionList) {
  }

  /**
   * Validate callback.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @see hook_form_alter()
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $subthemePathValue = strtolower($form_state->getValue('subtheme_folder'));
    $themeMName = $form_state->getValue('subtheme_machine_name');
    // Check for empty values.
    if (!$subthemePathValue) {
      $form_state->setErrorByName('subtheme_folder', $this->t('Subtheme folder is empty.'));
    }
    // Check for name validity.
    if (empty($themeMName)) {
      $form_state->setErrorByName('subtheme_machine_name', $this->t('Subtheme machine name is empty.'));
      return;
    }

    // Check for path trailing slash.
    if (strrev(trim($subthemePathValue))[0] === '/') {
      $form_state->setErrorByName('subtheme_folder', $this->t('Subtheme folder should be without trailing slash.'));
      return;
    }

    // Check for writable path.
    $directory = DRUPAL_ROOT . '/' . $subthemePathValue;
    if ($this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS) === FALSE) {
      $form_state->setErrorByName('subtheme_folder', $this->t('Subtheme cannot be created. Check permissions.'));
    }
    $themePath = $directory . '/' . $themeMName;
    if (file_exists($themePath)) {
      $form_state->setErrorByName('subtheme_machine_name', $this->t('Folder already exists.'));
    }

    // Check for common theme names.
    if (in_array($themeMName, [
      'bootstrap', 'bootstrap4', 'bootstrap5', 'bootstrap5_admin', 'classy',
      'claro', 'bartik', 'seven', 'olivero', 'stable', 'stable9', 'stark',
    ])) {
      $form_state->setErrorByName('subtheme_machine_name', $this->t('Subtheme name should not match existing themes.'));
    }

    // Check for reserved terms.
    if (in_array($themeMName, [
      'src', 'lib', 'vendor', 'assets', 'css', 'files', 'images', 'js', 'misc', 'templates', 'includes', 'fixtures', 'Drupal',
    ])) {
      $form_state->setErrorByName('subtheme_machine_name', $this->t('Subtheme name should not match reserved terms.'));
    }
    if (!empty($form_state->getErrors())) {
      return;
    }

    // Validate machine name to ensure correct format.
    if (!preg_match("/^[a-z]+[0-9a-z_]+$/", $themeMName)) {
      $form_state->setErrorByName('subtheme_machine_name', $this->t('Subtheme machine name format is incorrect.'));
    }
    // Check machine name is not longer than 50 characters.
    if (strlen($themeMName) > 50) {
      $form_state->setErrorByName('subtheme_folder', $this->t('Subtheme machine name must not be longer than 50 characters.'));
    }

  }

  /**
   * Submit callback.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @see hook_form_alter()
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $themeMName = $form_state->getValue('subtheme_machine_name');
    $themeName = $form_state->getValue('subtheme_name');
    $subThemePathValue = $form_state->getValue('subtheme_folder');
    try {
      $themePath = $this->createSubtheme($themeMName, $themeName, $subThemePathValue);
      $this->messenger->addStatus("Subtheme created at $themePath");
    }
    catch (\Exception $e) {
      $this->messenger->addError($e->getMessage());
    }
  }

  /**
   * Create subtheme.
   *
   * {@inheritDoc}
   */
  public function createSubtheme(string $themeMName, string $themeName, string $subthemePathValue,) {
    $fs = $this->fileSystem;
    $parentName = 'bootstrap5_admin';
    $pathParent = $this->themeExtensionList->getPath($parentName) . DIRECTORY_SEPARATOR;
    // Create subtheme.
    if (empty($themeName)) {
      $themeName = $themeMName;
    }
    $themePath = DRUPAL_ROOT . DIRECTORY_SEPARATOR . $subthemePathValue . DIRECTORY_SEPARATOR . $themeMName;
    if (!is_dir($themePath)) {
      // Copy CSS file replace empty one.
      $subFolders = ['css'];
      foreach ($subFolders as $subFolder) {
        $directory = $themePath . DIRECTORY_SEPARATOR . $subFolder . DIRECTORY_SEPARATOR;
        $fs->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
        if ($subFolder == 'css') {
          file_put_contents($directory . DIRECTORY_SEPARATOR . 'style.css', '');
          continue;
        }
        $files = $fs->scanDirectory($pathParent . $subFolder . DIRECTORY_SEPARATOR, '/.*/');
        foreach ($files as $file) {
          $fileName = $file->filename;
          $fs->copy($pathParent . $subFolder . DIRECTORY_SEPARATOR . $fileName,
            $themePath . DIRECTORY_SEPARATOR . $subFolder . DIRECTORY_SEPARATOR . $fileName, TRUE);
        }
      }

      // Copy image files.
      $files = [
        'favicon.ico',
        'logo.svg',
        'screenshot.png',
      ];
      foreach ($files as $fileName) {
        $fs->copy($pathParent . $fileName, $themePath . DIRECTORY_SEPARATOR . $fileName, TRUE);
      }

      // Copy files and rename content (array of lines of copy existing).
      $files = [
        $parentName . '.breakpoints.yml' => -1,
        $parentName . '.libraries.yml' => [
          'global-styling:',
          '  css:',
          '    theme:',
          '      css/style.css: {}',
          '  dependencies:',
          '    - bootstrap5_admin/cdn',
          '',
        ],
        $parentName . '.theme' => [
          '<?php',
          '',
          '/**',
          ' * @file',
          ' * ' . $themeName . ' theme file.',
          ' */',
          '',
        ],
        'README.md' => [
          '# ' . $themeName . ' theme',
          '',
          '[Bootstrap 5 admin](https://www.drupal.org/project/bootstrap5_admin) subtheme.',
          '',
          '## Development.',
          '',
          '### CSS compilation.',
          '',
          'Prerequisites: install [sass](https://sass-lang.com/install).',
          '',
          'To compile, run from subtheme directory: `sass scss/style.scss css/style.css`',
          '',
          'Or: `sass scss:css`',
          '',
        ],
      ];

      foreach ($files as $fileName => $lines) {
        // Get file content.
        $content = str_replace($parentName, $themeMName, file_get_contents($pathParent . $fileName));
        if (is_array($lines)) {
          $content = implode(PHP_EOL, $lines);
        }
        file_put_contents($themePath . DIRECTORY_SEPARATOR . str_replace($parentName, $themeMName, $fileName),
          $content);
      }

      // Info yml file generation.
      $infoYml = Yaml::decode(file_get_contents($pathParent . $parentName . '.info.yml'));
      $infoYml['name'] = $themeName;
      $infoYml['description'] = $themeName . ' subtheme based on ' . $themeName . ' theme.';
      $infoYml['base theme'] = $parentName;
      $infoYml['stylesheets-remove'] = ['@bootstrap5_admin/css/style.css'];
      $infoYml['libraries'] = [];
      $infoYml['libraries'][] = $themeMName . '/global-styling';
      $infoYml['libraries-override'] = [
        $parentName . '/global-styling' => FALSE,
      ];

      foreach (['generator', 'starterkit', 'version', 'project', 'datestamp'] as $value) {
        if (isset($infoYml[$value])) {
          unset($infoYml[$value]);
        }
      }

      file_put_contents($themePath . DIRECTORY_SEPARATOR . $themeMName . '.info.yml',
        Yaml::encode($infoYml));

      // SCSS files generation.
      $scssPath = $themePath . DIRECTORY_SEPARATOR . 'scss';
      $b5ScssPath = $pathParent . 'scss' . DIRECTORY_SEPARATOR;
      $fs->prepareDirectory($scssPath, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $files = [
        'style.scss' => [
          "// Sub theme styling.",
          "@import 'variables_drupal';",
          '',
          "// Bootstrap override variables.",
          "// @see https://getbootstrap.com/docs/5.3/customize/sass/#variable-defaults.",
          "@import 'variables_bootstrap';",
          '',
        ],
        '_variables_drupal.scss' => $b5ScssPath . '_variables_drupal.scss',
        '_variables_bootstrap.scss' => $b5ScssPath . '_variables_bootstrap.scss',
      ];

      foreach ($files as $fileName => $lines) {
        // Get file content.
        if (is_array($lines)) {
          $content = implode(PHP_EOL, $lines);
          file_put_contents($scssPath . DIRECTORY_SEPARATOR . $fileName, $content);
        }
        elseif (is_string($lines)) {
          $fs->copy($lines, $scssPath . DIRECTORY_SEPARATOR . $fileName, TRUE);
        }
      }

      // Add block config to sub-theme.
      $orig_config_path = $pathParent . 'config/optional';
      $config_path = $themePath . DIRECTORY_SEPARATOR . 'config/optional';
      $files = scandir($orig_config_path);
      $fs->prepareDirectory($config_path, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      foreach ($files as $filename) {
        if (substr($filename, 0, 5) === 'block') {
          $confYml = Yaml::decode(file_get_contents($orig_config_path . DIRECTORY_SEPARATOR . $filename));
          $confYml['dependencies']['theme'] = [];
          $confYml['dependencies']['theme'][] = $themeMName;
          $confYml['id'] = str_replace($parentName, $themeMName, $confYml['id']);
          $confYml['theme'] = $themeMName;
          $file_name = str_replace($parentName, $themeMName, $filename);
          file_put_contents($config_path . DIRECTORY_SEPARATOR . $file_name,
            Yaml::encode($confYml));
        }
      }

      // Add install config to subtheme.
      $orig_config_path = $pathParent . 'config/install';
      $config_path = $themePath . DIRECTORY_SEPARATOR . 'config/install';
      $files = scandir($orig_config_path);
      $fs->prepareDirectory($config_path, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      foreach ($files as $filename) {
        if (substr($filename, 0, 10) === $parentName) {
          $confYml = Yaml::decode(file_get_contents($orig_config_path . DIRECTORY_SEPARATOR . $filename));
          $file_name = str_replace($parentName, $themeMName, $filename);
          file_put_contents($config_path . DIRECTORY_SEPARATOR . $file_name,
            Yaml::encode($confYml));
        }
      }
      return $themePath;
    }
    else {
      throw new \Exception("Folder already exists at $themePath");
    }
  }

}
