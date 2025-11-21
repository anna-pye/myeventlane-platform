/**
 * @file
 * Custom CSS JS editor for the theme bootstrap 5 admin.
 */
(function ($, Drupal, once) {
  'use strict';

  /**
   * Attach behavior for JSON Fields.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.ace_editor = {
    attach(context) {
      const initEditor = function () {
        once('ace-editor', 'textarea[data-ace-editor]', context).forEach(function ($textarea) {
          let $editDiv = document.createElement('div');
          let mode = $textarea.getAttribute("data-ace-editor");
          if (!$textarea.parentNode) {
            return;
          }
          $textarea.classList.add('visually-hidden');
          $textarea.parentNode.insertBefore($editDiv, $textarea);
          // Init Ace editor.
          let editor = ace.edit($editDiv);
          editor.getSession().setValue($textarea.value);
          editor.getSession().setMode('ace/mode/'+mode);
          editor.getSession().setTabSize(2);
          editor.setTheme('ace/theme/chrome');
          editor.setOptions({
            minLines: 3,
            maxLines: 20
          });

          // Update Drupal textarea value.
          editor.getSession().on('change', function () {
            $textarea.value = editor.getSession().getValue();
          });
        });
      };

      // Check if Ace editor is already available and load it from source cdn otherwise.
      if (typeof ace !== 'undefined') {
        initEditor();
      }
    }
  };
})(jQuery, Drupal, once);
