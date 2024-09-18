(function (Drupal) {

  Drupal.behaviors.neoToolbar = {
    attach: () => {
      if (Drupal.displace) {
        Drupal.displace(true);
      }
    }
  };

})(Drupal);

export {};
