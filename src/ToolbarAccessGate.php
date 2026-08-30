<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * The module's single access answer for the toolbar.
 *
 * The ten lines below were `neo_toolbar_toolbar_view_access()` in the module
 * file, which survives as a one-line forwarder to this service because it is a
 * global function every site running this module loads and any of them may call
 * it from custom code. What moved is where the collaborators come from and
 * where the memo lives; no answer this gate gives anybody moved with it.
 *
 * `final` with no interface is what `ToolbarRepository` already is, for the
 * same reasons: nothing needs to substitute an implementation and the tests
 * construct the real class. One consequence is worth stating — `final` plus no
 * interface means this cannot be decorated, which is the trade the repository
 * already carries.
 */
final class ToolbarAccessGate {

  /**
   * The answer already given, per account id.
   *
   * The gate is consulted once before the toolbar renders and again once per
   * block whose job the toolbar's own items take over, so an admin page with
   * ten blocks would otherwise repeat the permission check, the module check
   * and the masquerade lookup eleven times.
   *
   * It is keyed by account id because the current user is a proxy whose account
   * can be replaced within a single PHP process — cron, a queue worker, a test
   * — and a memo keyed on nothing would hand the first account's answer to the
   * second.
   *
   * This was `drupal_static(__FUNCTION__)`, chosen because it was already there
   * and because `drupal_static_reset()` could clear it. A private array on a
   * service needs neither: it is per-request by construction, and it is cleared
   * by constructing another gate.
   *
   * @var array<int|string, bool>
   */
  private array $memo = [];

  /**
   * Constructs a ToolbarAccessGate object.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param object|null $masquerade
   *   The masquerade service, or NULL where the `masquerade` module is not
   *   installed. It is injected through core's optional service reference and
   *   typed as a plain object on purpose: this module neither requires nor
   *   installs `masquerade` — it is the provider of one toolbar item plugin and
   *   nothing more — so naming the class here would be a static analysis
   *   finding on every site that does not install it and no finding at all on
   *   the sites that do, giving one module a finding count that differs by
   *   site. All that is asked of it is `isMasquerading()`.
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ?object $masquerade = NULL,
  ) {}

  /**
   * Whether the current user has access to the toolbar.
   *
   * @return bool
   *   TRUE if the current user has access to the toolbar, FALSE otherwise.
   */
  public function hasAccess(): bool {
    $uid = $this->currentUser->id();
    if (isset($this->memo[$uid])) {
      return $this->memo[$uid];
    }
    $access = $this->currentUser->hasPermission('access neo_toolbar');
    // The two branches below are mutually exclusive by construction: the first
    // needs the toolbar permission and the second needs its absence. Neither
    // returns, so every exit leaves through the single write below.
    //
    // User 1 is exempt from the first branch, and the exemption is there to
    // stop an accident rather than to express a preference. Core's own
    // UserSession answers every permission check for user 1 with TRUE by
    // short-circuit, so on a site that installs core's toolbar alongside this
    // one, user 1 holds 'access toolbar' without any site builder having
    // granted it and would be handed core's toolbar automatically — not
    // because anyone chose it.
    //
    // The comparison casts, matching core's own idiom in
    // UserSession::hasPermission(), because the account id reaches this method
    // as whatever the database driver put in the row: a driver setting
    // \PDO::ATTR_STRINGIFY_FETCHES answers the string '1' and one returning
    // native types answers the integer 1. Comparing against either literal
    // alone would make a site on one driver behave differently from a site on
    // the other for no reason a reader could find.
    //
    // The same permission short-circuit means user 1 is never denied above and
    // so never reaches the masquerade branch below: user 1 always sees the Neo
    // toolbar.
    if (
      $access &&
      (int) $uid !== 1 &&
      $this->moduleHandler->moduleExists('toolbar') &&
      $this->currentUser->hasPermission('access toolbar')
    ) {
      // If core toolbar is enabled and user has access, do not show
      // neo_toolbar.
      $access = FALSE;
    }
    elseif (!$access && $this->masquerade !== NULL) {
      // If masquerading, we assume toolbar access.
      $access = $this->masquerade->isMasquerading();
    }
    $this->memo[$uid] = $access;
    return $access;
  }

}
