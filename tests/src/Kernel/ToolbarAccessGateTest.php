<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_toolbar\ToolbarAccessGate;
use Drupal\neo_toolbar_test\TestMasquerade;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the toolbar access gate, end to end through its forwarder.
 *
 * The gate is ten lines consulted before the toolbar renders on every
 * authenticated request, and once per block when deciding whether to hide the
 * local tasks and local actions blocks. It reads the module's own permission,
 * defers to core's `toolbar` module when that is enabled and the account may
 * use it, and treats an active masquerade as access.
 *
 * Those ten lines now live on the `neo_toolbar.access_gate` service and
 * `neo_toolbar_toolbar_view_access()` is a one-line forwarder to it, kept
 * undeprecated because it is a global function in a file every site running
 * this module loads. Every test below still asks through the function, because
 * the function is what the module's own callers use and what a site's custom
 * code may call; the branches themselves are driven with no container at all in
 * `Drupal\Tests\neo_toolbar\Unit\ToolbarAccessGateTest`.
 *
 * Two behaviours this class first pinned as defects are now fixed, and the
 * assertions that pinned them have been inverted in place:
 *
 * 1. The gate memo is real and keyed by account id, so a second call for one
 *    account is answered from it and a second *account* on the proxy gets its
 *    own answer.
 * 2. All three exits fall through to one write-and-return, so the core toolbar
 *    deferral and the masquerade branch are cached like the plain path. A test
 *    that asks about one account twice with the condition underneath it changed
 *    has to clear the memo, and several of the tests below say so where they
 *    do.
 *
 * How the memo is cleared is the one thing about this class that the move
 * changed. It was `drupal_static_reset('neo_toolbar_toolbar_view_access')`;
 * there is no such static any more, so it is `$this->resetGate()`, which puts
 * a separately constructed gate in the container. The criterion that named the
 * static by function name is the single pinned assertion the move inverts, and
 * it is `testRecomputesInSeparatelyConstructedGate()` below.
 *
 * The third thing this class pins is the user-1 exemption, whose story changed
 * twice. The plan predicted the exemption could never fire, on the grounds
 * that `$account->id()` is an `int` and the comparison was `!== '1'`. On this
 * site it was not an `int`: the mysql driver sets
 * `\PDO::ATTR_STRINGIFY_FETCHES`, every fetched column arrives as a string,
 * and an account read back through storage — which is what both
 * `Cookie::getUserFromSession()` and `AccountProxy::getAccount()` do — answers
 * `id()` with the string `'1'`. The guard closed and user 1 kept its toolbar,
 * but by accident of a database driver: the same account delivered with the
 * native integer id a driver without that flag returns lost it, which
 * `testUserOneIsExemptOnlyWhileItsIdIsNotAnInteger()` used to pin.
 *
 * The comparison is now `(int) $uid !== 1`, matching core's own idiom in
 * `UserSession::hasPermission()`, so the exemption is a decision rather than a
 * driver's side effect and holds whichever type the id arrives as. That test
 * is replaced here by
 * `testExemptsUserOneWhetherItsIdIsAnIntegerOrNumericString()`, whose
 * integer-id assertion is the inverse of the one it supersedes.
 *
 * What the exemption is for is the accident it prevents. Core's `UserSession`
 * short-circuits every permission check for user 1, so user 1 holds core's
 * `access toolbar` without a site builder ever having granted it and would be
 * handed core's toolbar automatically rather than by anyone's choice. The same
 * short-circuit means user 1 is never denied the module's own permission and
 * never reaches the masquerade branch either: user 1 always sees the Neo
 * toolbar.
 *
 * Core's `toolbar` is enabled here, which is one of the two places the spec
 * names as deliberately going wider than the `system`/`user`/`neo_toolbar`
 * floor: most of the criteria below are about the branch that defers to it, and
 * `moduleExists('toolbar')` cannot be driven any other way. The one test that
 * needs the other side of that check uninstalls it inside the method, because
 * the module list is a property of the class.
 *
 * The masquerade branch is reached through the fixtures' stand-in, handed to a
 * separately constructed gate by `resetGate()`. `masquerade` is not installed
 * on this site — that is also where the module's `class.notFound` phpstan
 * findings come from — so there is no real class to mock, the service
 * definition's optional reference resolves to NULL here, and this is the gate's
 * only non-deterministic exit.
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
    // Every site has a user 1, and four of the tests below sign in as it.
    // Creating it here also keeps it out of the way of the rest: with uid 1
    // taken, no ordinary fixture account can inherit the super user's
    // permissions.
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
   * User 1 keeps the Neo toolbar on a site that also runs core's.
   *
   * This is the whole behaviour the exemption exists for. Core's `UserSession`
   * answers every permission check for user 1 with TRUE by short-circuit, so
   * on a site that installs core's `toolbar` alongside this one user 1 holds
   * `access toolbar` without a site builder having granted it, and the
   * deferral would fire for user 1 automatically — not because anyone chose
   * it. The exemption is what stops that.
   *
   * Both of the shapes production hands `\Drupal::currentUser()` are driven,
   * because the answer must not depend on which one arrives: an account read
   * back through storage, and a `UserSession` built straight from a
   * `users_field_data` row. The memo is keyed by account id and both are
   * account 1, so it is cleared between them.
   *
   * Covers: it allows user 1 when core toolbar is installed and user 1 may use
   * it.
   */
  public function testAllowsUserOneWhenCoreToolbarIsInstalledAndUserOneMayUseIt(): void {
    $this->assertTrue(\Drupal::moduleHandler()->moduleExists('toolbar'));

    // Through storage, which is what `AccountProxy::getAccount()` does.
    $this->signIn(User::load(1));
    $this->assertTrue(\Drupal::currentUser()->hasPermission('access neo_toolbar'));
    $this->assertTrue(\Drupal::currentUser()->hasPermission('access toolbar'));
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    // Through a session, which is what `Cookie::getUserFromSession()` does.
    $this->resetGate();
    $this->signIn($this->userOneSession());
    $this->assertTrue(\Drupal::currentUser()->hasPermission('access neo_toolbar'));
    $this->assertTrue(\Drupal::currentUser()->hasPermission('access toolbar'));
    $this->assertTrue(neo_toolbar_toolbar_view_access());
  }

  /**
   * With no core toolbar to defer to, user 1 is allowed by the plain path.
   *
   * The exemption is a clause of the deferral and nothing else, so on the
   * majority of sites — this one included, where `core.extension.yml` does not
   * enable core's `toolbar` — it has nothing to exempt anyone from and user 1
   * is allowed by the module's own permission alone. Asserting that is what
   * says the clause did not reach past the branch it guards.
   *
   * Covers: it allows user 1 when core toolbar is not installed.
   */
  public function testAllowsUserOneWhenCoreToolbarIsNotInstalled(): void {
    $this->uninstallCoreToolbar();
    $this->assertFalse(\Drupal::moduleHandler()->moduleExists('toolbar'));

    $this->signIn(User::load(1));
    $this->assertTrue(\Drupal::currentUser()->hasPermission('access neo_toolbar'));
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    $this->resetGate();
    $this->signIn($this->userOneSession());
    $this->assertTrue(neo_toolbar_toolbar_view_access());
  }

  /**
   * The exemption is a decision, not a side effect of a database driver.
   *
   * The clause used to read `$uid !== '1'`, an untyped comparison against a
   * string, and whether it fired came down to what the driver put in the row:
   * mysql's `\PDO::ATTR_STRINGIFY_FETCHES` makes `id()` answer `'1'` and the
   * guard closed, while a driver returning native integers answered `1` and
   * the guard opened. One module, two behaviours, chosen by neither.
   *
   * `(int) $uid !== 1` is core's own idiom from `UserSession::hasPermission()`
   * and answers the same for both, which is what makes the exemption a rule
   * rather than an accident. The integer half of this test is the assertion
   * that `testUserOneIsExemptOnlyWhileItsIdIsNotAnInteger()` made in reverse.
   *
   * Covers: it exempts user 1 whether the account id arrives as an integer or
   * a numeric string.
   */
  public function testExemptsUserOneWhetherItsIdIsAnIntegerOrNumericString(): void {
    // The numeric string this site's driver produces.
    $this->signIn(User::load(1));
    $this->assertSame('1', \Drupal::currentUser()->id());
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    // The native integer a driver without that flag produces. The memo is
    // keyed by account id and both halves are account 1, so clearing it is
    // what keeps this a question about the id's type.
    $this->resetGate();
    $this->signIn($this->userOneSession());
    $this->assertSame(1, \Drupal::currentUser()->id());
    $this->assertTrue(neo_toolbar_toolbar_view_access());
  }

  /**
   * Every account that is not user 1 is deferred exactly as before.
   *
   * The exemption is one account's, and the cast is not a numeric-id amnesty:
   * an ordinary account holding both permissions is denied whether its id
   * arrives as the string storage returns or the integer a session carries.
   *
   * Covers: it still denies a non-user-1 account holding both the toolbar
   * permission and core toolbar access.
   */
  public function testStillDeniesNonUserOneAccountsHoldingBothPermissions(): void {
    $account = $this->createAccount('not_user_one', [
      'access neo_toolbar',
      'access toolbar',
    ]);

    $this->signIn($account);
    $this->assertSame((string) $account->id(), \Drupal::currentUser()->id());
    $this->assertTrue(\Drupal::currentUser()->hasPermission('access neo_toolbar'));
    $this->assertTrue(\Drupal::currentUser()->hasPermission('access toolbar'));
    $this->assertFalse(neo_toolbar_toolbar_view_access());

    $this->resetGate();
    $this->signIn(new UserSession([
      'uid' => (int) $account->id(),
      'name' => 'not_user_one',
      'roles' => ['authenticated', 'not_user_one'],
    ]));
    $this->assertSame((int) $account->id(), \Drupal::currentUser()->id());
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
    // branch returns what the collaborator says rather than deciding anything.
    $this->resetGate(new TestMasquerade(TRUE));
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    // The gate memoises per account, so asking the same account again with the
    // stand-in's answer changed reads the memo rather than the collaborator.
    // Clearing it is what keeps this an assertion about the answer given.
    $this->resetGate(new TestMasquerade(FALSE));
    $this->assertFalse(neo_toolbar_toolbar_view_access());

    // The branch is guarded by `!$access`, so an account that already answered
    // the permission never reaches the collaborator — not even to be overruled
    // by it. Holding core's toolbar permission too, this account is denied by
    // the exit above while the stand-in says it is masquerading.
    $this->resetGate(new TestMasquerade(TRUE));
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
   * drupal_static(__FUNCTION__)` dropped the reference, so the memo held
   * nothing after every call, and two of the three exits `return`ed before the
   * write besides. The memo is real, keyed by account id, and all three exits
   * fall through to one write, so the pinned assertion inverts: each of the
   * three exits leaves an answer behind under its own account id.
   *
   * It is a private property on the service now rather than a static this test
   * can read, so each of the three is shown to have been stored the way a memo
   * is ever shown to have been stored: the account is asked again with the
   * condition that decided it taken away, and the answer does not move.
   *
   * Covers: it leaves the module's existing tests green, with the pinned
   * non-caching assertion rewritten.
   */
  public function testTheGateMemoStoresWhatTheOldStaticThrewAway(): void {
    $masquerade = new TestMasquerade(TRUE);
    $this->resetGate($masquerade);

    // The plainly allowed exit, which was the only one that ever reached the
    // write.
    $first = $this->createAccount('stored_first', ['access neo_toolbar']);
    $this->signIn($first);
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    // The core toolbar deferral, which used to `return FALSE` above it.
    $deferred = $this->createAccount('stored_deferred', [
      'access neo_toolbar',
      'access toolbar',
    ]);
    $this->signIn($deferred);
    $this->assertFalse(neo_toolbar_toolbar_view_access());

    // The masquerade exit, which used to `return` the collaborator's answer.
    $masquerading = $this->createAccount('stored_masquerading', []);
    $this->signIn($masquerading);
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    // All three are held side by side under their own account ids. The same
    // account id comes back holding nothing, so a gate that recomputed would
    // answer FALSE.
    $this->signIn(new UserSession([
      'uid' => (int) $first->id(),
      'name' => 'stored_first',
      'roles' => ['authenticated'],
    ]));
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    // The deferred account comes back holding only the module's own
    // permission, which is the plainly allowed path a recomputing gate would
    // answer TRUE for.
    Role::create([
      'id' => 'stored_neo_only',
      'label' => 'stored_neo_only',
      'permissions' => ['access neo_toolbar'],
    ])->save();
    $this->signIn(new UserSession([
      'uid' => (int) $deferred->id(),
      'name' => 'stored_deferred',
      'roles' => ['authenticated', 'stored_neo_only'],
    ]));
    $this->assertFalse(neo_toolbar_toolbar_view_access());

    // And the stand-in stops masquerading, which a recomputing gate would ask
    // it about and answer FALSE for.
    $masquerade->setMasquerading(FALSE);
    $this->signIn($masquerading);
    $this->assertTrue(neo_toolbar_toolbar_view_access());
  }

  /**
   * A second call for one account is answered by the memo, not by the gate.
   *
   * The gate memo is a private array on the service, keyed by account id.
   * Proving it stores means changing something the gate would otherwise notice
   * and finding that it does not: the same account id is put back on the proxy
   * carrying no permissions at all, and the answer does not move.
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
   * The stand-in is told to change its answer rather than replaced, because
   * the gate holds the collaborator it was constructed with: swapping the
   * object would prove nothing the memo did.
   *
   * Covers: it answers the masquerade branch from the memo, which returned
   * before the cache write before.
   */
  public function testAnswersTheMasqueradeBranchFromTheMemo(): void {
    $this->signIn($this->createAccount('memo_masquerading', []));
    $this->assertFalse(\Drupal::currentUser()->hasPermission('access neo_toolbar'));

    $masquerade = new TestMasquerade(TRUE);
    $this->resetGate($masquerade);
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    // The stand-in stops masquerading. A gate that recomputed would ask it
    // again and answer FALSE; a gate that remembers does not ask at all.
    $masquerade->setMasquerading(FALSE);
    $this->assertTrue(neo_toolbar_toolbar_view_access());
  }

  /**
   * The memo is cleared by constructing another gate.
   *
   * This is the one criterion of this class the move inverts, and the only one.
   * It read "it recomputes after the static is reset under the gate's own
   * function name", because the memo was `drupal_static(__FUNCTION__)` and
   * `drupal_static_reset()` could clear it — which a long-running drush or
   * queue process that switches accounts needs, and so does a test. There is no
   * such static now: the memo is a private array on the service, per-request by
   * construction, and cleared by constructing another instance, which is one
   * line here and one line in a unit test. Every other criterion in this class
   * survives verbatim, because the forwarder answers what the function
   * answered.
   *
   * Covers: it recomputes in a separately constructed gate, which is the
   * criterion that replaces the pinned static reset.
   */
  public function testRecomputesInSeparatelyConstructedGate(): void {
    $this->signIn($this->createAccount('resettable', []));

    $masquerade = new TestMasquerade(TRUE);
    $this->resetGate($masquerade);
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    // Still remembered, so the recomputation below is the second construction's
    // doing and nothing else's.
    $masquerade->setMasquerading(FALSE);
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    $this->resetGate($masquerade);
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

    // Both answers are held side by side under their own account ids. The memo
    // is the service's own private property rather than a static this test can
    // read, so the second entry is shown the way the first one was: the account
    // comes back with what decided it reversed, and its answer does not move.
    Role::create([
      'id' => 'proxy_second_neo_only',
      'label' => 'proxy_second_neo_only',
      'permissions' => ['access neo_toolbar'],
    ])->save();
    $this->signIn(new UserSession([
      'uid' => (int) $second->id(),
      'name' => 'proxy_second',
      'roles' => ['authenticated', 'proxy_second_neo_only'],
    ]));
    $this->assertTrue(\Drupal::currentUser()->hasPermission('access neo_toolbar'));
    $this->assertFalse(neo_toolbar_toolbar_view_access());
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
   * User 1 is in that table on purpose, and its entry is the one line of it the
   * next ticket had any licence to move. It does not: read back through storage
   * the id is the numeric string `'1'`, which the old untyped comparison and
   * the integer one both exempt, so the answer is the same on either side of
   * that change. What moved is the reason — see
   * `testExemptsUserOneWhetherItsIdIsAnIntegerOrNumericString()` — and a reason
   * is not something this table records.
   *
   * Covers: it tells every account exactly what it told them before, user 1
   * included.
   * Covers: it leaves every other permutation answering exactly what ticket 01
   * left it answering.
   */
  public function testTellsEveryAccountExactlyWhatItToldThemBefore(): void {
    $expected = [];

    // The module's own permission and nothing else: allowed.
    $allowed = $this->createAccount('every_allowed', ['access neo_toolbar']);
    $this->signIn($allowed);
    $this->assertTrue(neo_toolbar_toolbar_view_access());
    $expected['every_allowed'] = [$allowed, TRUE];

    // Neither permission, and no masquerade collaborator: denied. The service
    // definition's optional reference resolves to NULL here, because
    // `masquerade` is not installed on this site.
    $nobody = $this->createAccount('every_nobody', []);
    $this->signIn($nobody);
    $this->assertFalse(\Drupal::hasService('masquerade'));
    $this->assertFalse(neo_toolbar_toolbar_view_access());
    $expected['every_nobody'] = [$nobody, FALSE];

    // Both permissions, so core's toolbar takes it: denied.
    $both = $this->createAccount('every_both', [
      'access neo_toolbar',
      'access toolbar',
    ]);
    $this->signIn($both);
    $this->assertFalse(neo_toolbar_toolbar_view_access());
    $expected['every_both'] = [$both, FALSE];

    // No permission, masquerading: allowed. Handing the stand-in to the gate
    // clears the memo, so the three above are recomputed by the loop at the
    // end rather than read back — which is the stronger of the two.
    $masquerade = new TestMasquerade(TRUE);
    $this->resetGate($masquerade);
    $masquerading = $this->createAccount('every_masquerading', []);
    $this->signIn($masquerading);
    $this->assertTrue(neo_toolbar_toolbar_view_access());
    $expected['every_masquerading'] = [$masquerading, TRUE];

    // No permission, not masquerading: denied.
    $masquerade->setMasquerading(FALSE);
    $idle = $this->createAccount('every_idle', []);
    $this->signIn($idle);
    $this->assertFalse(neo_toolbar_toolbar_view_access());
    $expected['every_idle'] = [$idle, FALSE];

    // User 1, read back through storage exactly as production hands it to the
    // proxy: allowed, because the deferral exempts it.
    $userOne = User::load(1);
    $this->signIn($userOne);
    $this->assertSame('1', \Drupal::currentUser()->id());
    $this->assertTrue(neo_toolbar_toolbar_view_access());
    $expected['user_one'] = [$userOne, TRUE];

    // The table again, as answers rather than as a memo this test can read.
    foreach ($expected as $name => [$account, $answer]) {
      $this->signIn($account);
      $this->assertSame($answer, neo_toolbar_toolbar_view_access(), $name);
    }
  }

  /**
   * The forwarder is the service's answer and nothing of its own.
   *
   * `neo_toolbar_toolbar_view_access()` survives the move undeprecated,
   * because it is a global function in a file every site loads and any of
   * them can call it from custom code. What it must not do is answer anything
   * of its own: every permutation below is asked twice, once through the
   * function and once of `neo_toolbar.access_gate` directly, and the two are
   * asserted identical as well as against the answer the permutation has
   * always produced.
   *
   * Covers: it answers through the gate forwarder exactly what it answers when
   * asked directly.
   */
  public function testAnswersThroughTheForwarderExactlyWhatTheServiceAnswers(): void {
    $this->assertInstanceOf(ToolbarAccessGate::class, \Drupal::service('neo_toolbar.access_gate'));

    $permutations = [
      // The module's own permission and nothing else: allowed.
      'forwarded_allowed' => [['access neo_toolbar'], TRUE],
      // Neither permission and no masquerade collaborator: denied.
      'forwarded_denied' => [[], FALSE],
      // Both permissions, so core's toolbar takes it: denied.
      'forwarded_deferred' => [['access neo_toolbar', 'access toolbar'], FALSE],
    ];
    foreach ($permutations as $name => [$permissions, $expected]) {
      $this->signIn($this->createAccount($name, $permissions));
      $this->assertSame($expected, neo_toolbar_toolbar_view_access(), $name);
      $this->assertSame(
        neo_toolbar_toolbar_view_access(),
        \Drupal::service('neo_toolbar.access_gate')->hasAccess(),
        $name,
      );
    }
  }

  /**
   * Puts a separately constructed gate in the container.
   *
   * The gate memo is the service's own private property, so clearing it means
   * constructing another gate. That is the whole of what
   * `drupal_static_reset('neo_toolbar_toolbar_view_access')` used to buy here,
   * and the criterion this class had pinned on that call is now
   * `testRecomputesInSeparatelyConstructedGate()`.
   *
   * The optional collaborator is passed in rather than read from the container,
   * because `masquerade` is not installed on this site: the service
   * definition's optional reference resolves to NULL, and a stand-in registered
   * under that service id would only reach a gate the container had not
   * constructed yet.
   *
   * @param object|null $masquerade
   *   The masquerade stand-in, or NULL for the shape this site runs.
   */
  protected function resetGate(?object $masquerade = NULL): void {
    $this->container->set('neo_toolbar.access_gate', new ToolbarAccessGate(
      $this->container->get('current_user'),
      $this->container->get('module_handler'),
      $masquerade,
    ));
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

  /**
   * User 1 as a session, which is the other shape production delivers.
   *
   * `Cookie::getUserFromSession()` hands `\Drupal::currentUser()` a
   * `UserSession` built straight from a `users_field_data` row, and
   * `UserSession::id()` answers whatever the driver put in that row. Building
   * it with a native `int` is what a driver without
   * `\PDO::ATTR_STRINGIFY_FETCHES` produces, and it is the delivery the old
   * `!== '1'` comparison failed to exempt.
   *
   * @return \Drupal\Core\Session\UserSession
   *   User 1, with an id that is an integer.
   */
  protected function userOneSession(): UserSession {
    return new UserSession([
      'uid' => 1,
      'name' => 'user_one',
      'roles' => ['authenticated'],
    ]);
  }

  /**
   * Removes core's toolbar module from the running site.
   *
   * The deferral is guarded by `moduleExists('toolbar')`, and this class
   * installs core's toolbar for the five tests that need the branch reachable.
   * Uninstalling rebuilds the container, so the current user proxy is replaced
   * and the account has to be signed in afterwards rather than before.
   */
  protected function uninstallCoreToolbar(): void {
    // `user` clears any per-user data an uninstalled module left behind, which
    // is a table this class has no other reason to install.
    $this->installSchema('user', ['users_data']);
    $this->container->get('module_installer')->uninstall(['toolbar']);
    $this->container = \Drupal::getContainer();
    // A rebuilt module handler has not loaded the `.module` file the gate lives
    // in, the same reason setUp() asks for it by name.
    $this->container->get('module_handler')->loadInclude('neo_toolbar', 'module');
  }

}
