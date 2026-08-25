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
 * Two behaviours are pinned as current rather than defended, so that the
 * candidate which fixes them lands as a visible diff:
 *
 * 1. The static cache never caches. `drupal_static()` returns by reference and
 *    `$cache = drupal_static(__FUNCTION__)` drops the reference, so the write
 *    at the end lands on a local copy and the `isset()` guard at the top can
 *    never hit.
 * 2. Two of the three exits `return` before the cache write is reached, so
 *    even with the reference restored they would stay uncached.
 *
 * A third was expected and is not there. The plan predicted that the user-1
 * exemption can never fire, on the grounds that `$account->id()` is an `int`
 * and the comparison is `!== '1'`. On this site it is not an `int`: the mysql
 * driver sets `\PDO::ATTR_STRINGIFY_FETCHES`, every fetched column arrives as
 * a string, and an account read back through storage — which is what both
 * `Cookie::getUserFromSession()` and `AccountProxy::getAccount()` do — answers
 * `id()` with the string `'1'`. The exemption therefore fires, and user 1 sees
 * the toolbar exactly as the comment above the branch claims. What the branch
 * really carries is a type dependency rather than a dead exemption, so
 * `testUserOneIsExemptOnlyWhileItsIdIsNotAnInteger()` pins both sides of it:
 * the string id this site's driver produces, and the integer id a driver
 * returning native types would produce. Changing `'1'` to `1` would not
 * repair a dead branch — it would take user 1's toolbar away on every mysql
 * site.
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
   * The static cache never stores, so every call recomputes.
   *
   * Pinned as current behaviour, not defended. `drupal_static()` returns by
   * reference; `$cache = drupal_static(__FUNCTION__)` takes a copy, so the
   * write at the end of the function lands on a local variable and the guard
   * at the top can never hit. Two of the three exits compound it by returning
   * before the write is reached at all.
   *
   * Covers: it recomputes on every call, because the static cache never stores
   * a result.
   */
  public function testRecomputesOnEveryCallBecauseTheCacheNeverStores(): void {
    // The last exit is the only one that reaches `$cache = $access`, so an
    // allowed account is the one input that could store anything.
    $this->signIn($this->createAccount('first', ['access neo_toolbar']));
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    // Nothing was stored: the static the function names is still unset, which
    // is why the `isset()` guard at the top can never hit.
    $this->assertNull(drupal_static('neo_toolbar_toolbar_view_access'));

    // And so a different account is answered afresh rather than handed the
    // first one's result, with no `drupal_static_reset()` in between.
    $this->signIn($this->createAccount('second', []));
    $this->assertFalse(neo_toolbar_toolbar_view_access());
    $this->assertNull(drupal_static('neo_toolbar_toolbar_view_access'));

    // The two early exits never reach the write in the first place. Core's
    // toolbar exit, then the masquerade exit, then back to an allowed account:
    // every answer is the current account's, never a previous one's.
    $this->signIn($this->createAccount('deferred', [
      'access neo_toolbar',
      'access toolbar',
    ]));
    $this->assertFalse(neo_toolbar_toolbar_view_access());

    $this->container->set('masquerade', new TestMasquerade(TRUE));
    $this->signIn($this->createAccount('third', []));
    $this->assertTrue(neo_toolbar_toolbar_view_access());

    $this->container->set('masquerade', new TestMasquerade(FALSE));
    $this->signIn($this->createAccount('fourth', ['access neo_toolbar']));
    $this->assertTrue(neo_toolbar_toolbar_view_access());
    $this->assertNull(drupal_static('neo_toolbar_toolbar_view_access'));
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
