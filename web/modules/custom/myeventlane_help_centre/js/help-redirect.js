(function (Drupal, drupalSettings) {
  Drupal.behaviors.melHelpRedirect = {
    attach(context) {
      if (context !== document) {
        return;
      }

      var redirectUrl = drupalSettings?.myeventlaneHelpCentre?.redirectUrl;
      if (typeof redirectUrl === 'string' && redirectUrl.length > 0) {
        window.location.assign(redirectUrl);
      }
    }
  };
})(Drupal, drupalSettings);
