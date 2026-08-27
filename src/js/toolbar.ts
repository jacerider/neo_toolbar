(function (Drupal, once, displace) {

  Drupal.behaviors.neoToolbar = {
    attach: (context: HTMLElement | undefined) => {
      if (!displace) {
        return;
      }

      // A toolbar displaces the viewport, so the offsets have to be
      // recalculated when one enters the page -- and only then. Recalculating
      // on every attach pass meant every AJAX response, every form rebuild and
      // every autocomplete broadcast a drupalViewportOffsetChange to the whole
      // document, which is far more than a page that has not gained a toolbar
      // needs to pay for.
      if (!once('neo-toolbar-displace', '.neo-toolbar', context).length) {
        return;
      }

      displace(true);
    }
  };

})(Drupal, once, Drupal.displace);
