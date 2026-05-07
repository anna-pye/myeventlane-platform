/**
 * @file
 * Staff-only governance debug shell hint (no customer/vendor payloads).
 */
(function (Drupal, once) {
  Drupal.behaviors.melGovernanceDebug = {
    attach(context) {
      once('mel-governance-debug', 'body', context).forEach((body) => {
        body.classList.add('mel-governance-debug--staff');
      });
    },
  };
})(Drupal, once);
