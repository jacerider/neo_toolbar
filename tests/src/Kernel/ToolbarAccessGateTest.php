<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_toolbar_test\TestMasquerade;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the toolbar access gate.
 *
 * `neo_toolbar_toolbar_view_access()` is ten lines consulted before the toolbar
 * renders on every authenticated request, and once per block when deciding
 * whether to hide the local tasks and local actions blocks. It reads the
 * module's own permission, defers to core's `toolbar` module when that is
 * enabled and the account may use it, and treats an active masquerade as
 * access.
 *
 * Two behaviours this class first pinned as defects are now fixed, and the
 * assertions that pinned them have been inverted in place:
 *
 * 1. The gate memo is a real reference — `$cache = &drupal_static(__FUNCTION__,
 *    [])` — keyed by account id, so a second call for one account is answered
 *    from it and a second *account* on the proxy gets its own answer.
 * 2. All three exits fall through to one write-and-return, so the core toolbar
 *    deferral and the masquerade branch are cached like the plain path. A test
 *    that asks about one account twice with the condition underneath it changed
 *    now needs `drupal_static_reset('neo_toolbar_toolbar_view_access')`, and
 *    two of the tests below say so where they call it.
 *
 * A third defect was expected and is not there. The plan predicted that the
 * user-1 exemption can never fire, on the grounds that `$account->id()` is an
 * `int` and the comparison is `!== '1'`. On this site it is not an `int`: the
 * mysql driver sets `\PDO::ATTR_STRINGIFY_FETCHES`, every fetched column
 * arrives as a string, and an account read back through storage — which is
 * what both `Cookie::getUserFromSession()` and `AccountProxy::getAccount()` do
 * — answers `id()` with the string `'1'`. The exemption therefore fires, and
 * user 1 sees the toolbar exactly as the comment above the branch claims. What
 * the branch really carries is a type dependency rather than a dead exemption,
 * so `testUserOneIsExemptOnlyWhileItsIdIsNotAnInteger()` pins both sides of it:
 * the string id this site's driver produces, and the integer id a driver
 * returning native types would produce. Changing `'1'` to `1` would not
 * repair a dead branch — it would take user 1's toolbar away on every mysql
 * site.
 *
 * That is also why the clause is still here after the ticket that restructured
 * this gate. That ticket described it as dead and proposed deleting it as a
 * provable no-op; on this site's storage it is live, so deleting it would have
 * changed what user 1 is told — the one thing the same ticket says it must not
 * do. `testTellsEveryAccountExactlyWhatItToldThemBefore()` is the assertion
 * that holds the line.
 *
 * Core's `toolbar` is enabled here, which is one of the two tests the spec
 * names as deliberately going wider than the `system`/`user`/`neo_toolbar`
 * floor: three of the six criteria are about the branch that defers to it, and
 * `moduleExists('toolbar')` cannot be driven any other way.
 *
 * The masquerade branch is reached through the fixtures' stand-in registered
 * into the container under the `masquerade` service id. `masquerade` is not
 * installed on this site — that is also where the module's two
 * `class.notFound` phpstan findings come from — so there is no real class to
 * mock, and this is the gate's only non-deterministic exit.
 *
 * The super user access policy is on, because this test file does not live
 * under `core`. That is what makes user 1 hold core's `access toolbar` without
 * being granted it, and it is the shape a real site has.
 */
#[Group('neo_toolbar')]
final class ToolbarAccessGateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `breakpoint` is here only because core's `toolbar` names it as a
   * dependency; nothing on this path reads a breakpoint. `neo_toolbar_test` is
   * here for the masquerade stand-in, whose namespace is registered only for
   * an enabled module.
   */
  protected static $modules = [
    'system',
    'user',
    'breakpoint',
    'toolbar',
    'neo_toolbar',
    'neo_toolbar_test',
  ];

  /**
   * The next account id to hand out.
   *
   * Every account is given its id explicitly, because the first account saved
   * into an empty `users` table would otherwise become user 1 and be handed
   * every permission on the site by the super user access policy.
   *
   * @var int
   */
  protected int $nextUid = 2;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['user']);
    // The gate is a global function in the `.module` file, and every caller of
    // it in production reaches it after the module handler has loaded that
    // file. Ask for it by name rather than trusting the boot order.
    $this->container->get('module_handler')->loadInclude('neo_toolbar', 'module');
    // Every site has a user 1, and one test signs in as it. Creating it here
    // also keeps it out of the way of the other five: with uid 1 taken, no
    // ordinary fixture account can inherit the super user's permissions.
    User::create([
      'uid' => 1,
      'name' => 'user_one',
      'status' => 1,
    ])->save();
  }

  /**
   * The module's own permission is the whole of the first exit.
   *
   * Covers: it allows an account holding the toolbar permission.
   */
  public function testAllowsAnAccountHoldingTheToolbarPermission(): void {
    $this->signIn($this->createAccount('viewer', ['access neo_toolbar']));

    // Core's toolbar is installed, so an account reaching the last exit is a
    // statement about the permission it does not hold as much as the one it
    // does.
    $this->assertTrue(\Drupal::moduleHandler()->moduleExists('toolbar'));
    $this->assertTrue(\Drupal::currentUser()->hasPermission('access neo_toolbar'));
    $this->assertFalse(\Drupal::currentUser()->hasPermission('access toolbar'));

    $this->assertTrue(neo_toolbar_toolbar_view_access());
  }

  /**
   * Without the permission and without the service there is nothing to allow.
   *
   * Covers: it denies an account without the toolbar permission and without
   * masquerade.
   */
  public function testDeniesAnAccountWithoutThePermissionAndWithoutMasquerade(): void {
    $this->signIn($this->createAccount('nobody', []));

    $this->assertFalse(\Drupal::currentUser()->hasPermission('access neo_toolbar'));
    // The masquerade branch is guarded by the service existing at all, and on
    // this site it does not: `masquerade` is not installed.
    $this->assertFalse(\Drupal::hasService('masquerade'));

    $this->assertFalse(neo_toolbar_toolbar_view_access());
  }

  /**
   * Core's toolbar wins when the account may use it.
   *
   * Covers: it denies an account that holds both the toolbar permission and
   * core toolbar access.
   */
  public function testDeniesAnAccountHoldingBothTheToolbarPermissionAndCoreToolbarAccess(): void {
    $this->signIn($this->createAccount('both', [
      'access neo_toolbar',
      'access toolbar',
    ]));

    $this->assertTrue(\Drupal::currentUser()->hasPermission('access neo_toolbar'));
    $this->assertTrue(\Drupal::currentUser()->hasPermission('access toolbar'));

    $this->assertFalse(neo_toolbar_toolbar_view_access());

    // The same permissions minus core's toolbar are allowed, which is what
    // makes this an assertion about the deferral rather than about the
    // account.
    $this->signIn($this->createAccount('neo_only', ['access neo_toolbar']));
    $this->assertTrue(neo_toolbar_toolbar_view_access());
  }

  /**
   * User 1's exemption stands or falls on the type of its account id.
   *
   * The branch that defers to core's toolbar guards itself with
   * `\Drupal::currentUser()->id() !== '1'`, and the comment above it says the
   * deferral "does not apply to user 1 who will always see neo_toolbar".
   *
   * The plan predicted this exemption is dead, because `id()` answers an
   * `int` and `!==` compares type. It is not dead here. The mysql driver sets
   * `\PDO::ATTR_STRINGIFY_FETCHES`, so an account read back out of storage
   * answers `id()` with the string `'1'` — and reading it back out of storage
   * is what production does, through `Cookie::getUserFromSession()` on a real
   * request and through `AccountProxy::getAccount()` everywhere else. The
   * exemption fires and user 1 keeps its toolbar.
   *
   * What the branch actually carries is a dependency on that type, which is
   * why both sides are pinned here: the same account id, delivered as the
   * integer a driver without that flag would return, is denied. A driver that
   * returns native integers rather than strings puts a site on that side of
   * the line without a line of this module changing.
   *
   * Covers: it denies user 1 on the same terms, because the user-1 exemption
   * never fires — corrected: on this site's storage the exemption does fire,
   * and user 1 is denied only when its id arrives as an integer.
   */
  public function testUserOneIsExemptOnlyWhileItsIdIsNotAnInteger(): void {
    // The super user access policy is on for a test outside `core`, so user 1
    // holds every permission on the site without being granted one — which is
    // both the shape a real site has and the shape that reaches this branch.
    $userOne = User::load(1);
    $this->signIn($userOne);
    $this->assertTrue(\Drupal::currentUser()->hasPermission('access neo_toolbar'));
    $this->assertTrue(\Drupal::currentUser()->hasPermission('access toolbar'));

    // The premise, stated as an assertion: read back through storage the id is
    // a string, so the branch's `!== '1'` is equal, the guard closes and the
    // deferral is skipped.
    $this->assertSame('1', \Drupal::currentUser()->id());
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    // An ordinary account holding the same two permissions is denied, which is
    // what makes the previous answer the exemption and not something else.
    $this->signIn($this->createAccount('ordinary', [
      'access neo_toolbar',
      'access toolbar',
    ]));
    $this->assertFalse(neo_toolbar_toolbar_view_access());

    // The other side of the type dependency, in the shape production builds it
    // in: `Cookie::getUserFromSession()` hands `\Drupal::currentUser()` a
    // `UserSession` constructed straight from a `users_field_data` row, and
    // `UserSession::id()` answers whatever the driver put in that row. Give it
    // the integer a driver without `\PDO::ATTR_STRINGIFY_FETCHES` would, and
    // `!== '1'` is no longer equal: the guard opens, the deferral is taken and
    // user 1 loses its toolbar.
    //
    // The gate memo is keyed by account id and both halves of this comparison
    // are account 1, so the memo would otherwise answer the second with the
    // first's result. Clearing it is what makes this a question about the id's
    // type rather than about the memo.
    drupal_static_reset('neo_toolbar_toolbar_view_access');
    $this->signIn(new UserSession([
      'uid' => 1,
      'name' => 'user_one',
      'roles' => ['authenticated'],
    ]));
    $this->assertSame(1, \Drupal::currentUser()->id());
    $this->assertTrue(\Drupal::currentUser()->hasPermission('access neo_toolbar'));
    $this->assertTrue(\Drupal::currentUser()->hasPermission('access toolbar'));
    $this->assertFalse(neo_toolbar_toolbar_view_access());
  }

  /**
   * An active masquerade stands in for the permission.
   *
   * Covers: it answers the masquerade service when the toolbar permission is
   * absent.
   */
  public function testAnswersTheMasqueradeServiceWhenThePermissionIsAbsent(): void {
    $this->signIn($this->createAccount('masquerader', []));
    $this->assertFalse(\Drupal::currentUser()->hasPermission('access neo_toolbar'));

    // Both answers of the stand-in come back as the gate's own answer: the
    // branch returns what the service says rather than deciding anything.
    $this->container->set('masquerade', new TestMasquerade(TRUE));
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    // The gate now memoises per account, so asking the same account again with
    // the stand-in's answer changed reads the memo rather than the service.
    // Clearing it is what keeps this an assertion about the service's answer.
    drupal_static_reset('neo_toolbar_toolbar_view_access');
    $this->container->set('masquerade', new TestMasquerade(FALSE));
    $this->assertFalse(neo_toolbar_toolbar_view_access());

    // The branch is guarded by `!$access`, so an account that already answered
    // the permission never reaches the service — not even to be overruled by
    // it. Holding core's toolbar permission too, this account is denied by the
    // exit above while the stand-in says it is masquerading.
    $this->container->set('masquerade', new TestMasquerade(TRUE));
    $this->signIn($this->createAccount('both_and_masquerading', [
      'access neo_toolbar',
      'access toolbar',
    ]));
    $this->assertFalse(neo_toolbar_toolbar_view_access());
  }

  /**
   * The gate memo stores what the old static threw away.
   *
   * Rewritten from `testRecomputesOnEveryCallBecauseTheCacheNeverStores()`,
   * which pinned the defect as current behaviour: `$cache =
   * drupal_static(__FUNCTION__)` dropped the reference, so
   * `assertNull(drupal_static('neo_toolbar_toolbar_view_access'))` held after
   * every call, and two of the three exits `return`ed before the write besides.
   * The reference is restored, the memo is keyed by account id and all three
   * exits fall through to one write, so the pinned assertion inverts: the
   * static the function names now carries an answer per account, from each of
   * the three exits in turn.
   *
   * Covers: it leaves the module's existing tests green, with the pinned
   * non-caching assertion rewritten.
   */
  public function testTheGateMemoStoresWhatTheOldStaticThrewAway(): void {
    // The plainly allowed exit, which was the only one that ever reached the
    // write.
    $first = $this->createAccount('stored_first', ['access neo_toolbar']);
    $this->signIn($first);
    $this->assertTrue(neo_toolbar_toolbar_view_access());
    $this->assertSame(
      [(int) $first->id() => TRUE],
      drupal_static('neo_toolbar_toolbar_view_access'),
    );

    // The core toolbar deferral, which used to `return FALSE` above it.
    $deferred = $this->createAccount('stored_deferred', [
      'access neo_toolbar',
      'access toolbar',
    ]);
    $this->signIn($deferred);
    $this->assertFalse(neo_toolbar_toolbar_view_access());

    // The masquerade exit, which used to `return` the service's answer.
    $this->container->set('masquerade', new TestMasquerade(TRUE));
    $masquerading = $this->createAccount('stored_masquerading', []);
    $this->signIn($masquerading);
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    $this->assertSame([
      (int) $first->id() => TRUE,
      (int) $deferred->id() => FALSE,
      (int) $masquerading->id() => TRUE,
    ], drupal_static('neo_toolbar_toolbar_view_access'));
  }

  /**
   * A second call for one account is answered by the memo, not by the gate.
   *
   * The gate memo is `drupal_static(__FUNCTION__)` taken by reference and
   * keyed by account id. Proving it stores means changing something the gate
   * would otherwise notice and finding that it does not: the same account id
   * is put back on the proxy carrying no permissions at all, and the answer
   * does not move.
   *
   * Covers: it answers from the gate memo on a second call for the same
   * account.
   */
  public function testAnswersFromTheGateMemoOnTheSecondCallForOneAccount(): void {
    $account = $this->createAccount('memoised', ['access neo_toolbar']);
    $this->signIn($account);
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    // The same account id, delivered as a session that holds nothing. A gate
    // that recomputed would answer FALSE here; a gate that remembers answers
    // for the account it already answered about.
    $this->signIn(new UserSession([
      'uid' => (int) $account->id(),
      'name' => 'memoised',
      'roles' => ['authenticated'],
    ]));
    $this->assertFalse(\Drupal::currentUser()->hasPermission('access neo_toolbar'));

    $this->assertTrue(neo_toolbar_toolbar_view_access());
  }

  /**
   * The core toolbar deferral writes its answer down like every other exit.
   *
   * This exit `return`ed before the cache write, so restoring the reference
   * alone would have left the branch that does the most work uncached. It is
   * driven, then the condition underneath it is removed, and the memo's answer
   * is expected to win.
   *
   * Covers: it answers the core toolbar deferral from the memo, which returned
   * before the cache write before.
   */
  public function testAnswersTheCoreToolbarDeferralFromTheMemo(): void {
    $account = $this->createAccount('deferred_once', [
      'access neo_toolbar',
      'access toolbar',
    ]);
    $this->signIn($account);
    $this->assertFalse(neo_toolbar_toolbar_view_access());

    // The same account id, now holding only the module's own permission, which
    // is the plainly allowed path. A gate that recomputed would answer TRUE.
    Role::create([
      'id' => 'deferred_once_neo_only',
      'label' => 'deferred_once_neo_only',
      'permissions' => ['access neo_toolbar'],
    ])->save();
    $this->signIn(new UserSession([
      'uid' => (int) $account->id(),
      'name' => 'deferred_once',
      'roles' => ['authenticated', 'deferred_once_neo_only'],
    ]));
    $this->assertTrue(\Drupal::currentUser()->hasPermission('access neo_toolbar'));
    $this->assertFalse(\Drupal::currentUser()->hasPermission('access toolbar'));

    $this->assertFalse(neo_toolbar_toolbar_view_access());
  }

  /**
   * The masquerade branch writes its answer down like every other exit.
   *
   * The gate's only non-deterministic exit, and the second of the two that
   * `return`ed before the cache write. The stand-in is asked once, then told
   * to stop masquerading; the memo is expected to keep the answer it gave.
   *
   * Covers: it answers the masquerade branch from the memo, which returned
   * before the cache write before.
   */
  public function testAnswersTheMasqueradeBranchFromTheMemo(): void {
    $this->signIn($this->createAccount('memo_masquerading', []));
    $this->assertFalse(\Drupal::currentUser()->hasPermission('access neo_toolbar'));

    $this->container->set('masquerade', new TestMasquerade(TRUE));
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    // The stand-in stops masquerading. A gate that recomputed would ask it
    // again and answer FALSE; a gate that remembers does not ask at all.
    $this->container->set('masquerade', new TestMasquerade(FALSE));
    $this->assertTrue(neo_toolbar_toolbar_view_access());
  }

  /**
   * The memo is resettable, which is the reason it stayed a `drupal_static()`.
   *
   * A plain function `static` could not be cleared from outside; a long-running
   * drush or queue process that switches accounts, and this test, both need
   * that. Reset restores the memo to the empty array it was registered with.
   *
   * Covers: it recomputes after the static is reset under the gate's own
   * function name.
   */
  public function testRecomputesAfterTheStaticIsResetUnderItsOwnFunctionName(): void {
    $this->signIn($this->createAccount('resettable', []));

    $this->container->set('masquerade', new TestMasquerade(TRUE));
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    // Still remembered, so the recomputation below is the reset's doing and
    // nothing else's.
    $this->container->set('masquerade', new TestMasquerade(FALSE));
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    drupal_static_reset('neo_toolbar_toolbar_view_access');
    $this->assertSame([], drupal_static('neo_toolbar_toolbar_view_access'));

    $this->assertFalse(neo_toolbar_toolbar_view_access());
  }

  /**
   * Two accounts in one request get two answers, not one answer twice.
   *
   * The gate reads `\Drupal::currentUser()`, a proxy whose account can be
   * replaced within a single PHP process — cron, a queue worker, a test. A memo
   * keyed on nothing would hand the first account's answer to the second, which
   * on a question shaped "may this person see the admin toolbar" is the trap
   * worth keying against.
   *
   * Covers: it answers separately for a second account put on the current-user
   * proxy in the same request.
   */
  public function testAnswersSeparatelyForTheSecondAccountOnTheProxy(): void {
    $first = $this->createAccount('proxy_first', ['access neo_toolbar']);
    $second = $this->createAccount('proxy_second', []);

    $this->signIn($first);
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    $this->signIn($second);
    $this->assertFalse(neo_toolbar_toolbar_view_access());

    // And the first account is still answered as itself rather than as the
    // account that was asked about most recently.
    $this->signIn($first);
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    // Both answers are held side by side, under their own account ids.
    $this->assertSame([
      (int) $first->id() => TRUE,
      (int) $second->id() => FALSE,
    ], drupal_static('neo_toolbar_toolbar_view_access'));
  }

  /**
   * Every permutation answers after this ticket what it answered before it.
   *
   * The whole ticket is a restructure: the reference restored, a key added, and
   * two `return`s turned into assignments falling through to one exit. Nothing
   * about *which* answer a branch produces moves, only how many times it is
   * computed and whether it is remembered — so every permutation the class
   * already pins is walked again here in one request, and the memo is asserted
   * as the record of what each account was told.
   *
   * User 1 is in that table on purpose. The ticket describes the
   * `\Drupal::currentUser()->id() !== '1'` clause as dead and proposes deleting
   * it as a no-op; on this site it is not dead — see
   * `testUserOneIsExemptOnlyWhileItsIdIsNotAnInteger()` — and deleting it would
   * take user 1's toolbar away, which is the one thing this ticket says it must
   * not do. The clause therefore stays, and this is the assertion that says so.
   *
   * Covers: it tells every account exactly what it told them before, user 1
   * included.
   */
  public function testTellsEveryAccountExactlyWhatItToldThemBefore(): void {
    $expected = [];

    // The module's own permission and nothing else: allowed.
    $allowed = $this->createAccount('every_allowed', ['access neo_toolbar']);
    $this->signIn($allowed);
    $this->assertTrue(neo_toolbar_toolbar_view_access());
    $expected[(int) $allowed->id()] = TRUE;

    // Neither permission, and no masquerade service in the container at all:
    // denied.
    $nobody = $this->createAccount('every_nobody', []);
    $this->signIn($nobody);
    $this->assertFalse(\Drupal::hasService('masquerade'));
    $this->assertFalse(neo_toolbar_toolbar_view_access());
    $expected[(int) $nobody->id()] = FALSE;

    // Both permissions, so core's toolbar takes it: denied.
    $both = $this->createAccount('every_both', [
      'access neo_toolbar',
      'access toolbar',
    ]);
    $this->signIn($both);
    $this->assertFalse(neo_toolbar_toolbar_view_access());
    $expected[(int) $both->id()] = FALSE;

    // No permission, masquerading: allowed.
    $this->container->set('masquerade', new TestMasquerade(TRUE));
    $masquerading = $this->createAccount('every_masquerading', []);
    $this->signIn($masquerading);
    $this->assertTrue(neo_toolbar_toolbar_view_access());
    $expected[(int) $masquerading->id()] = TRUE;

    // No permission, not masquerading: denied.
    $this->container->set('masquerade', new TestMasquerade(FALSE));
    $idle = $this->createAccount('every_idle', []);
    $this->signIn($idle);
    $this->assertFalse(neo_toolbar_toolbar_view_access());
    $expected[(int) $idle->id()] = FALSE;

    // User 1, read back through storage exactly as production hands it to the
    // proxy: allowed, because the deferral exempts it while its id is the
    // string this site's driver returns.
    $userOne = User::load(1);
    $this->signIn($userOne);
    $this->assertSame('1', \Drupal::currentUser()->id());
    $this->assertTrue(neo_toolbar_toolbar_view_access());
    $expected[1] = TRUE;

    $this->assertSame($expected, drupal_static('neo_toolbar_toolbar_view_access'));
  }

  /**
   * Creates an account holding exactly the given permissions.
   *
   * The account is returned as storage answers it, because that is the shape
   * production hands to `\Drupal::currentUser()` — an id that is a string.
   *
   * @param string $name
   *   The account name, which also names the role created for it.
   * @param string[] $permissions
   *   The permissions the account's role grants.
   *
   * @return \Drupal\user\UserInterface
   *   The saved account, reloaded.
   */
  protected function createAccount(string $name, array $permissions): UserInterface {
    Role::create([
      'id' => $name,
      'label' => $name,
      'permissions' => $permissions,
    ])->save();
    $uid = $this->nextUid++;
    User::create([
      'uid' => $uid,
      'name' => $name,
      'status' => 1,
      'roles' => [$name],
    ])->save();
    return User::load($uid);
  }

  /**
   * Makes an account the current user.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   */
  protected function signIn(AccountInterface $account): void {
    \Drupal::currentUser()->setAccount($account);
  }

}
