(function (Drupal, displace) {

  Drupal.behaviors.neoToolbar = {
    attach: () => {
      if (displace) {
        displace(true);
      }
    }
  };

})(Drupal, Drupal.displace);

export {};
