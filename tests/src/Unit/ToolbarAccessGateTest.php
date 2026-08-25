<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Unit;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxy;
use Drupal\neo_toolbar\ToolbarAccessGate;
use Drupal\neo_toolbar_test\TestMasquerade;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Drives every branch of the toolbar access gate without a container.
 *
 * The gate is the module's single access answer and its only branching logic.
 * Until it became `neo_toolbar.access_gate` it read the current user, the
 * module handler and an optional service straight out of the container, so its
 * four permutations could only be driven by booting one — which is what
 * `Drupal\Tests\neo_toolbar\Kernel\ToolbarAccessGateTest` still does, end to
 * end, through the global forwarder.
 *
 * This class is the half that extraction bought. Three constructor arguments,
 * a real `AccountProxy` carrying stubbed accounts, a stubbed module handler and
 * — for the optional collaborator — an object with one method, which is the
 * whole of what the gate asks of it. No container, no database, no bootstrap.
 *
 * The masquerade collaborator is typed as a plain nullable object and its class
 * is named nowhere in the module, because `masquerade` is neither required nor
 * installed here and naming it would add a fourth finding to the three the
 * module already carries for it. The fixtures' `TestMasquerade` is what stands
 * in, exactly as it does in the kernel class.
 */
#[Group('neo_toolbar')]
final class ToolbarAccessGateTest extends UnitTestCase {

  /**
   * The module's own permission is the whole of the first exit.
   *
   * Covers: it allows an account holding the toolbar permission.
   */
  public function testAllowsAnAccountHoldingTheToolbarPermission(): void {
    $proxy = $this->proxy($this->account(2, 'access neo_toolbar'));
    $gate = new ToolbarAccessGate($proxy, $this->moduleHandler(TRUE));

    $this->assertTrue($gate->hasAccess());
  }

  /**
   * Core's toolbar wins when it is installed and the account may use it.
   *
   * The deferral carries the user-1 exemption as a clause, so both are driven
   * here: core's `UserSession` short-circuits every permission check for user
   * 1, which would hand user 1 core's toolbar on any site running both modules
   * without a site builder ever having chosen it. The cast is core's own idiom
   * from `UserSession::hasPermission()` and answers the same whether the id
   * arrives as the numeric string storage returns or the integer a session
   * carries.
   *
   * Covers: it defers to core's toolbar when that module is installed and the
   * account may use it.
   */
  public function testDefersToCoreToolbarWhenInstalledAndTheAccountMayUseIt(): void {
    $both = ['access neo_toolbar', 'access toolbar'];

    $gate = new ToolbarAccessGate(
      $this->proxy($this->account(2, ...$both)),
      $this->moduleHandler(TRUE),
    );
    $this->assertFalse($gate->hasAccess());

    // Nothing to defer to, so the module's own permission decides. This is
    // what makes the assertion above about the deferral and not the account.
    $gate = new ToolbarAccessGate(
      $this->proxy($this->account(2, ...$both)),
      $this->moduleHandler(FALSE),
    );
    $this->assertTrue($gate->hasAccess());

    // User 1 is exempt from the deferral, whichever type its id arrives as.
    foreach ([1, '1'] as $uid) {
      $gate = new ToolbarAccessGate(
        $this->proxy($this->account($uid, ...$both)),
        $this->moduleHandler(TRUE),
      );
      $this->assertTrue($gate->hasAccess());
    }
  }

  /**
   * An active masquerade stands in for the permission the account lacks.
   *
   * The collaborator is optional because `masquerade` is neither required nor
   * installed here, so the shape this site actually runs is the third case
   * below: nothing injected, and the branch denies. Its class is named nowhere
   * — the constructor parameter is a plain nullable object — so the fixtures'
   * stand-in is a complete collaborator by having the one method the gate
   * calls.
   *
   * Covers: it answers the masquerade collaborator when the toolbar permission
   * is absent, and denies when none was injected.
   */
  public function testAnswersTheMasqueradeCollaboratorAndDeniesWithoutOne(): void {
    // Both answers of the stand-in come back as the gate's own answer: the
    // branch returns what the collaborator says rather than deciding anything.
    $gate = new ToolbarAccessGate(
      $this->proxy($this->account(2)),
      $this->moduleHandler(TRUE),
      new TestMasquerade(TRUE),
    );
    $this->assertTrue($gate->hasAccess());

    $gate = new ToolbarAccessGate(
      $this->proxy($this->account(2)),
      $this->moduleHandler(TRUE),
      new TestMasquerade(FALSE),
    );
    $this->assertFalse($gate->hasAccess());

    // Nothing injected, which is every site that does not install
    // `masquerade` — this one included.
    $gate = new ToolbarAccessGate(
      $this->proxy($this->account(2)),
      $this->moduleHandler(TRUE),
    );
    $this->assertFalse($gate->hasAccess());

    // The branch is guarded on the permission's absence, so an account that
    // already answered never reaches the collaborator — not even to be
    // overruled by it. Holding core's toolbar permission too, this account is
    // denied by the deferral while the stand-in says it is masquerading.
    $gate = new ToolbarAccessGate(
      $this->proxy($this->account(2, 'access neo_toolbar', 'access toolbar')),
      $this->moduleHandler(TRUE),
      new TestMasquerade(TRUE),
    );
    $this->assertFalse($gate->hasAccess());
  }

  /**
   * The memo answers a repeat question, and keys what it remembers per account.
   *
   * The gate is consulted once before the toolbar renders and again once per
   * block whose job the toolbar's own items take over, so an admin page with
   * ten blocks would otherwise repeat the permission check, the module check
   * and the masquerade lookup eleven times.
   *
   * It is keyed by account id because the current user is a proxy whose account
   * can be replaced within a single PHP process — cron, a queue worker, a test
   * — and a memo keyed on nothing would hand the first account's answer to the
   * second. Proving it stores means changing something the gate would otherwise
   * notice and finding that it does not.
   *
   * Covers: it answers a second call for the same account from the gate memo,
   * and answers a second account separately.
   */
  public function testAnswersFromTheGateMemoAndKeysItPerAccount(): void {
    $proxy = $this->proxy($this->account(2, 'access neo_toolbar'));
    $gate = new ToolbarAccessGate($proxy, $this->moduleHandler(TRUE));
    $this->assertTrue($gate->hasAccess());

    // The same account id, now holding nothing at all. A gate that recomputed
    // would answer FALSE; a gate that remembers answers for the account it has
    // already answered about.
    $proxy->setAccount($this->account(2));
    $this->assertFalse($proxy->hasPermission('access neo_toolbar'));
    $this->assertTrue($gate->hasAccess());

    // A second account on the same proxy is answered as itself.
    $proxy->setAccount($this->account(3));
    $this->assertFalse($gate->hasAccess());

    // And the first account is still answered as itself rather than as the
    // account that was asked about most recently.
    $proxy->setAccount($this->account(2));
    $this->assertTrue($gate->hasAccess());
  }

  /**
   * A second gate starts with an empty memo, which is what reset used to buy.
   *
   * This is the one assertion of the characterisation suite that the move
   * inverts. The memo was `drupal_static(__FUNCTION__)` for two stated
   * reasons: it was what the code already reached for, and
   * `drupal_static_reset()` could clear it — which a long-running drush or
   * queue process that switches accounts needs, and so does a test. Neither
   * survives the move. A service holding a private array keyed by account id
   * is per-request by construction and is cleared by constructing another
   * instance, with no global involved.
   *
   * Covers: it recomputes in a separately constructed gate, which is the
   * criterion that replaces the pinned static reset.
   */
  public function testRecomputesInSeparatelyConstructedGate(): void {
    $proxy = $this->proxy($this->account(2, 'access neo_toolbar'));
    $gate = new ToolbarAccessGate($proxy, $this->moduleHandler(TRUE));
    $this->assertTrue($gate->hasAccess());

    // Still remembered with the permission taken away, so the recomputation
    // below is the second construction's doing and nothing else's.
    $proxy->setAccount($this->account(2));
    $this->assertTrue($gate->hasAccess());

    $fresh = new ToolbarAccessGate($proxy, $this->moduleHandler(TRUE));
    $this->assertFalse($fresh->hasAccess());
  }

  /**
   * A real proxy carrying the given account.
   *
   * The proxy is real rather than mocked because the gate reads its id and its
   * permissions, and because one criterion is about a second account replacing
   * the first on it — which is a thing the proxy does, not a thing a mock can
   * be asked to pretend.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to seat on the proxy.
   *
   * @return \Drupal\Core\Session\AccountProxy
   *   The proxy.
   */
  protected function proxy(AccountInterface $account): AccountProxy {
    $proxy = new AccountProxy($this->createMock(EventDispatcherInterface::class));
    $proxy->setAccount($account);
    return $proxy;
  }

  /**
   * An account answering an id and a fixed set of permissions.
   *
   * @param int|string $uid
   *   The account id. Both shapes production delivers are used below: the
   *   numeric string storage answers with, and the integer a session carries.
   * @param string ...$permissions
   *   The permissions the account holds.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account.
   */
  protected function account(int|string $uid, string ...$permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('hasPermission')->willReturnCallback(
      static fn (string $permission): bool => in_array($permission, $permissions, TRUE),
    );
    return $account;
  }

  /**
   * A module handler answering whether core's toolbar is installed.
   *
   * @param bool $coreToolbar
   *   Whether `moduleExists('toolbar')` answers TRUE.
   *
   * @return \Drupal\Core\Extension\ModuleHandlerInterface
   *   The module handler.
   */
  protected function moduleHandler(bool $coreToolbar): ModuleHandlerInterface {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturnCallback(
      static fn (string $module): bool => $module === 'toolbar' && $coreToolbar,
    );
    return $moduleHandler;
  }

}
